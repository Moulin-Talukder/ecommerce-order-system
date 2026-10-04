<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\RecordPayment;
use App\Actions\Orders\RecordRefund;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Http\Requests\Admin\RecordRefundRequest;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;

class OrderPaymentController extends Controller
{
    public function store(RecordPaymentRequest $request, Order $order, RecordPayment $recordPayment): RedirectResponse
    {
        $payment = $recordPayment->handle(
            $order,
            (string) $request->validated('amount'),
            $request->enum('method', PaymentMethod::class),
            $request->enum('status', PaymentTransactionStatus::class),
            $request->user(),
            $request->validated('transaction_reference'),
            $request->validated('note'),
        );

        $message = $payment->status === PaymentTransactionStatus::Failed
            ? 'Failed payment attempt of '.Money::format($payment->amount).' recorded.'
            : 'Payment of '.Money::format($payment->amount).' recorded.';

        return to_route('admin.orders.show', $order)->with('success', $message);
    }

    public function refund(RecordRefundRequest $request, Order $order, RecordRefund $recordRefund): RedirectResponse
    {
        $refund = $recordRefund->handle(
            $order,
            (string) $request->validated('amount'),
            $request->enum('method', PaymentMethod::class),
            $request->user(),
            $request->validated('transaction_reference'),
            $request->validated('note'),
        );

        return to_route('admin.orders.show', $order)->with('success', 'Refund of '.Money::format($refund->amount).' recorded.');
    }
}
