<?php

namespace App\Exceptions\Inventory;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A stock operation was refused by a domain rule. Rendered as a flash error
 * (web) or 422 JSON (API); nothing has been changed when it is thrown.
 */
class StockException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }
}
