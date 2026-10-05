<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Courier\ApplySteadfastWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Steadfast delivery-status webhooks.
 *
 * Authentication: "Authorization: Bearer <STEADFAST_WEBHOOK_TOKEN>", the
 * mechanism Steadfast's official plugin uses (the same token is entered in
 * the Steadfast merchant panel). Steadfast does not document a request
 * signature, so none is checked. Tokens in query strings are not accepted
 * because they leak into access logs.
 */
class SteadfastWebhookController extends Controller
{
    public function __invoke(Request $request, ApplySteadfastWebhook $apply): JsonResponse
    {
        $expected = (string) config('courier.steadfast.webhook_token');

        if ($expected === '') {
            return response()->json(['status' => 'error', 'message' => 'Webhook is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::warning('Steadfast webhook rejected: invalid token', ['ip' => $request->ip()]);

            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $validator = Validator::make($request->all(), [
            'notification_type' => ['nullable', 'string', 'max:50'],
            'consignment_id' => ['nullable', 'required_without:invoice', 'alpha_num:ascii', 'max:64'],
            'invoice' => ['nullable', 'required_without:consignment_id', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'tracking_message' => ['nullable', 'string', 'max:1000'],
            'updated_at' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload.', 'errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $apply->handle($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => match ($result) {
                ApplySteadfastWebhook::APPLIED => 'Delivery status updated.',
                ApplySteadfastWebhook::IGNORED_STALE => 'Already up to date.',
                ApplySteadfastWebhook::IGNORED_UNKNOWN => 'Unknown consignment; ignored.',
            },
        ]);
    }
}
