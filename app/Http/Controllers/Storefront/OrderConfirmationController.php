<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Storefront\CheckoutSession;
use App\Services\Tracking\DataLayer;
use App\Services\Tracking\EcommerceEvents;
use Illuminate\Http\Response;

/**
 * Thank-you page. Only the browser session that placed the order may see
 * it; anyone else gets a 404 without the database being consulted, so the
 * response does not reveal whether an order number exists.
 *
 * The purchase dataLayer event is built from the saved order (never the
 * submitted form) and only after CreateOrder has committed.
 */
class OrderConfirmationController extends Controller
{
    public function show(string $orderNumber, CheckoutSession $checkout, DataLayer $dataLayer, EcommerceEvents $events): Response
    {
        abort_unless($checkout->owns($orderNumber), 404);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with('items:id,order_id,product_name,sku,quantity,unit_price,discount_amount,line_total')
            ->firstOrFail();

        // At most one purchase event per order: the first authorized view
        // atomically claims it; refreshes and repeat visits do not re-send it.
        $dataLayer->pushOnce('purchase:'.$order->order_number, $events->purchase($order));

        return response()
            ->view('storefront.thank-you', ['order' => $order])
            ->header('Cache-Control', 'no-store, private');
    }
}
