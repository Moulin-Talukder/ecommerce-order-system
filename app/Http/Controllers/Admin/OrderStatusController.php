<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\ChangeOrderStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeOrderStatusRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

class OrderStatusController extends Controller
{
    public function update(ChangeOrderStatusRequest $request, Order $order, ChangeOrderStatus $changeStatus): RedirectResponse
    {
        $status = $request->enum('status', OrderStatus::class);

        $changeStatus->handle($order, $status, $request->user(), $request->validated('note'));

        return to_route('admin.orders.show', $order)->with('success', "Order {$order->order_number} is now {$status->label()}.");
    }
}
