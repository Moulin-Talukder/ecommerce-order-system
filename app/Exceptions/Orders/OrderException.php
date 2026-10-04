<?php

namespace App\Exceptions\Orders;

use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An order operation was refused by a business rule. Thrown before or inside
 * the order transaction, so nothing has been changed. Rendered as a flash
 * error with the input kept (web) or 422 JSON (API).
 */
class OrderException extends RuntimeException
{
    public static function customerBlocked(string $name): self
    {
        return new self("{$name} is blocked and cannot place orders.");
    }

    public static function productNotOrderable(string $name): self
    {
        return new self("“{$name}” is not available for sale.");
    }

    public static function productUnavailable(int $productId): self
    {
        return new self("Product #{$productId} does not exist.");
    }

    public static function outOfStock(string $name): self
    {
        return new self("“{$name}” is out of stock.");
    }

    public static function discountExceedsSubtotal(string $discount, string $subtotal): self
    {
        return new self('The discount ('.Money::format($discount).') cannot exceed the items subtotal ('.Money::format($subtotal).').');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }
}
