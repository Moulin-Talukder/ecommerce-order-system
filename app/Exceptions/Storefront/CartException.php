<?php

namespace App\Exceptions\Storefront;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A cart change was refused (unavailable product, quantity limit). The
 * message is customer-friendly.
 */
class CartException extends RuntimeException
{
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
