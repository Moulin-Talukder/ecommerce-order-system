<?php

namespace App\Services\Courier;

use App\Models\Order;

/**
 * Data sent to a courier to book one parcel. Built from the order's own
 * snapshot (never from client input). Amounts are 2-decimal strings.
 */
final readonly class ConsignmentRequest
{
    public function __construct(
        public string $invoice,
        public string $recipientName,
        public string $recipientPhone,
        public string $recipientAddress,
        public string $codAmount,
        public ?string $note,
    ) {}

    public static function forOrder(Order $order): self
    {
        $address = collect([$order->shipping_address, $order->shipping_area, $order->shipping_city, $order->shipping_postal_code])
            ->map(fn (?string $part) => trim((string) $part))
            ->filter()
            ->implode(', ');

        return new self(
            invoice: $order->order_number,
            recipientName: $order->customer_name,
            recipientPhone: $order->customer_phone,
            recipientAddress: $address,
            codAmount: $order->amountDue(),
            note: filled($order->customer_note) ? $order->customer_note : null,
        );
    }
}
