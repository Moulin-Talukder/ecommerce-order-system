<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Storefront\CheckoutSession;
use Illuminate\View\View;

/**
 * Thank-you page. Only the browser session that placed the order may see
 * it; anyone else gets a 404 without the database being consulted, so the
 * response does not reveal whether an order number exists.
 */
class OrderConfirmationController extends Controller
{
    public function show(string $orderNumber, CheckoutSession $checkout): View
    {
        abort_unless($checkout->owns($orderNumber), 404);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with('items:id,order_id,product_name,sku,quantity,unit_price,discount_amount,line_total')
            ->firstOrFail();

        return view('storefront.thank-you', ['order' => $order]);
    }
}
