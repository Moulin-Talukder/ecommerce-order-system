<?php

namespace App\Exceptions\Storefront;

use RuntimeException;

/**
 * Checkout could not place the order. Messages are customer-friendly and
 * never reveal internal reasons (e.g. a blocked customer).
 */
class CheckoutException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $reviewCart = false)
    {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('Your cart is empty. Add a product before checking out.', reviewCart: true);
    }

    /**
     * @param  list<string>  $notices
     */
    public static function cartChanged(array $notices): self
    {
        return new self('Your cart changed: '.implode(' ', $notices).' Please review it and place your order again.', reviewCart: true);
    }

    public static function soldOut(): self
    {
        return new self('Some items sold out while you were checking out. Your cart has been updated; please review it and try again.', reviewCart: true);
    }

    public static function couldNotPlace(): self
    {
        return new self("We couldn't place this order. Please contact us for assistance.");
    }
}
