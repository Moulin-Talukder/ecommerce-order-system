<?php

namespace App\Actions\Storefront;

use App\Actions\Orders\CreateOrder;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Orders\OrderException;
use App\Exceptions\Storefront\CheckoutException;
use App\Models\Order;
use App\Services\Storefront\Cart;
use App\Services\Storefront\CartLine;
use Illuminate\Support\Facades\Log;

/**
 * Turns the session cart plus validated checkout details into an order by
 * calling the existing CreateOrder action, which stays the only place that
 * locks stock, calculates totals, snapshots data and writes the order.
 *
 * This class only decides the storefront-specific inputs: items from the
 * session cart, delivery charge from the chosen zone, Cash on Delivery,
 * source = website.
 */
class CheckoutCart
{
    public function __construct(
        private Cart $cart,
        private CreateOrder $createOrder,
    ) {}

    /**
     * @param  array{name: string, phone: string, alternate_phone?: string|null, email?: string|null, address: string, area: string, city: string, delivery_zone: string, note?: string|null}  $details
     *
     * @throws CheckoutException
     */
    public function handle(array $details): Order
    {
        $deliveryCharge = (string) config("storefront.delivery_zones.{$details['delivery_zone']}.charge");
        $summary = $this->cart->summary($deliveryCharge);

        if ($summary->isEmpty()) {
            throw $summary->changed() ? CheckoutException::cartChanged($summary->notices) : CheckoutException::emptyCart();
        }

        if ($summary->changed()) {
            throw CheckoutException::cartChanged($summary->notices);
        }

        try {
            return $this->createOrder->handle([
                'customer_name' => $details['name'],
                'customer_phone' => $details['phone'],
                'customer_alternate_phone' => $details['alternate_phone'] ?? null,
                'customer_email' => $details['email'] ?? null,
                'shipping_address' => $details['address'],
                'shipping_area' => $details['area'],
                'shipping_city' => $details['city'],
                'items' => array_map(fn (CartLine $line) => ['product_id' => $line->product->id, 'quantity' => $line->quantity], $summary->lines),
                'delivery_charge' => $deliveryCharge,
                'discount_total' => '0',
                'payment_method' => PaymentMethod::CashOnDelivery,
                'source' => OrderSource::Website,
                'customer_note' => $details['note'] ?? null,
            ]);
        } catch (InsufficientStockException) {
            $this->cart->summary();

            throw CheckoutException::soldOut();
        } catch (OrderException $exception) {
            // Covers blocked customers and products that became unavailable mid-checkout;
            // the customer only sees a neutral message.
            Log::notice('Storefront checkout refused', ['reason' => $exception->getMessage()]);

            throw CheckoutException::couldNotPlace();
        }
    }
}
