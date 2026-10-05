<?php

namespace App\Exceptions\Courier;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A courier action was refused by a local rule before anything was sent, or
 * the courier is not configured. Messages are safe to show to admins.
 */
class CourierException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->with('error', $this->getMessage());
    }
}
