<?php

namespace App\Services\Courier\Steadfast;

use App\Services\Courier\BookingOutcome;
use App\Services\Courier\BookingResult;
use App\Services\Courier\ConsignmentRequest;
use App\Services\Courier\CourierGateway;
use App\Services\Courier\LookupKey;
use App\Services\Courier\LookupResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Steadfast Courier API client.
 *
 * Verified against Steadfast's official WordPress plugin (v1.0.9):
 *  - POST {base}/create_order with Api-Key / Secret-Key headers and JSON body
 *    invoice, recipient_name, recipient_phone, recipient_address, cod_amount, note;
 *    success: body status 200 + consignment.{consignment_id, tracking_code, status};
 *    validation failure: body status 400 + errors.
 *  - GET {base}/status_by_cid/{id}: body status 200 + delivery_status.
 * status_by_invoice/{invoice} and status_by_trackingcode/{code} come from
 * Steadfast's API documentation (same response shape).
 *
 * Credentials are sent only as headers and never logged or stored.
 */
class SteadfastGateway implements CourierGateway
{
    private const LOOKUP_PATHS = [
        'consignment_id' => 'status_by_cid',
        'invoice' => 'status_by_invoice',
        'tracking_code' => 'status_by_trackingcode',
    ];

    public function isConfigured(): bool
    {
        return filled(config('courier.steadfast.api_key'))
            && filled(config('courier.steadfast.secret_key'))
            && filled(config('courier.steadfast.base_url'));
    }

    public function createConsignment(ConsignmentRequest $request): BookingResult
    {
        $startedAt = microtime(true);
        $payload = array_filter([
            'invoice' => $request->invoice,
            'recipient_name' => $request->recipientName,
            'recipient_phone' => $request->recipientPhone,
            'recipient_address' => $request->recipientAddress,
            'cod_amount' => $request->codAmount,
            'note' => $request->note,
        ], fn ($value) => $value !== null);

        try {
            $response = $this->client()->post('create_order', $payload);
        } catch (ConnectionException $exception) {
            return $this->log($request->invoice, $startedAt, $this->connectionFailure($exception));
        } catch (Throwable $exception) {
            return $this->log($request->invoice, $startedAt, new BookingResult(BookingOutcome::Uncertain, 'Unexpected error while contacting Steadfast; the booking outcome is unknown.'));
        }

        return $this->log($request->invoice, $startedAt, $this->interpretBooking($response));
    }

    public function lookup(LookupKey $key, string $value): LookupResult
    {
        $path = self::LOOKUP_PATHS[$key->value].'/'.rawurlencode($value);

        try {
            $response = $this->client()
                ->retry(
                    max(1, 1 + (int) config('courier.steadfast.lookup_retries')),
                    (int) config('courier.steadfast.lookup_retry_delay_ms'),
                    fn (Throwable $exception) => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()),
                    throw: false,
                )
                ->get($path);
        } catch (Throwable) {
            return new LookupResult(LookupResult::FAILED, message: 'Could not reach Steadfast.');
        }

        $body = $this->json($response);
        $bodyStatus = (int) ($body['status'] ?? $response->status());

        return match (true) {
            $response->status() === 401 || $response->status() === 403 || $bodyStatus === 401 => new LookupResult(LookupResult::FAILED, message: 'Steadfast rejected the API credentials.', response: $body),
            $response->status() === 404 || $bodyStatus === 404 => new LookupResult(LookupResult::NOT_FOUND, message: 'Steadfast has no parcel for this '.str_replace('_', ' ', $key->value).'.', response: $body),
            $response->successful() && $bodyStatus === 200 && is_string($body['delivery_status'] ?? null) && $body['delivery_status'] !== '' => new LookupResult(LookupResult::FOUND, status: $body['delivery_status'], response: $body),
            $response->successful() && $bodyStatus === 200 => new LookupResult(LookupResult::FAILED, message: 'Steadfast returned an unexpected status response.', response: $body),
            default => new LookupResult(LookupResult::FAILED, message: "Steadfast status lookup failed (HTTP {$response->status()}).", response: $body),
        };
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('courier.steadfast.base_url'))
            ->withHeaders([
                'Api-Key' => (string) config('courier.steadfast.api_key'),
                'Secret-Key' => (string) config('courier.steadfast.secret_key'),
            ])
            ->asJson()
            ->acceptJson()
            ->connectTimeout((int) config('courier.steadfast.connect_timeout'))
            ->timeout((int) config('courier.steadfast.timeout'));
    }

    private function interpretBooking(Response $response): BookingResult
    {
        $body = $this->json($response);
        $httpStatus = $response->status();
        $bodyStatus = isset($body['status']) && is_numeric($body['status']) ? (int) $body['status'] : null;
        $consignment = is_array($body['consignment'] ?? null) ? $body['consignment'] : null;

        if ($response->successful() && ($bodyStatus === null || $bodyStatus === 200) && filled($consignment['consignment_id'] ?? null)) {
            return new BookingResult(
                BookingOutcome::Accepted,
                'Booked with Steadfast.',
                consignmentId: (string) $consignment['consignment_id'],
                trackingCode: filled($consignment['tracking_code'] ?? null) ? (string) $consignment['tracking_code'] : null,
                status: filled($consignment['status'] ?? null) ? (string) $consignment['status'] : null,
                response: $body,
                httpStatus: $httpStatus,
            );
        }

        if ($httpStatus === 401 || $httpStatus === 403 || $bodyStatus === 401) {
            return new BookingResult(BookingOutcome::Rejected, 'Steadfast rejected the API credentials. Check STEADFAST_API_KEY / STEADFAST_SECRET_KEY.', response: $body, httpStatus: $httpStatus);
        }

        if ($httpStatus === 429 || $bodyStatus === 429) {
            return new BookingResult(BookingOutcome::Rejected, 'Steadfast rate limit reached. Try again shortly.', response: $body, httpStatus: $httpStatus);
        }

        if (($httpStatus >= 400 && $httpStatus < 500) || ($bodyStatus !== null && $bodyStatus >= 400 && $bodyStatus < 500)) {
            return new BookingResult(BookingOutcome::Rejected, 'Steadfast rejected the booking: '.$this->errorSummary($body), response: $body, httpStatus: $httpStatus);
        }

        // 5xx, or a 2xx we cannot interpret: Steadfast may have created the parcel.
        return new BookingResult(
            BookingOutcome::Uncertain,
            $response->serverError()
                ? "Steadfast returned a server error (HTTP {$httpStatus}); the parcel may or may not have been created."
                : 'Steadfast returned an unexpected response; the parcel may or may not have been created.',
            response: $body,
            httpStatus: $httpStatus,
        );
    }

    /**
     * A request that never connected cannot have been processed; a timeout
     * after the request was sent can have been.
     */
    private function connectionFailure(ConnectionException $exception): BookingResult
    {
        $message = $exception->getMessage();

        if (preg_match('/cURL error (6|7|35|60)\b/', $message) === 1) {
            return new BookingResult(BookingOutcome::Rejected, 'Could not connect to Steadfast; nothing was sent. Try again.');
        }

        return new BookingResult(BookingOutcome::Uncertain, 'Steadfast did not respond in time; the parcel may or may not have been created.');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function errorSummary(?array $body): string
    {
        $errors = collect($body['errors'] ?? [])->flatten()->filter(fn ($error) => is_string($error))->take(5);

        if ($errors->isNotEmpty()) {
            return $errors->implode(' ');
        }

        return is_string($body['message'] ?? null) ? $body['message'] : 'no reason given.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function log(string $invoice, float $startedAt, BookingResult $result): BookingResult
    {
        Log::log($result->outcome === BookingOutcome::Accepted ? 'info' : 'warning', 'Steadfast booking attempt', [
            'invoice' => $invoice,
            'outcome' => $result->outcome->value,
            'http_status' => $result->httpStatus,
            'consignment_id' => $result->consignmentId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }
}
