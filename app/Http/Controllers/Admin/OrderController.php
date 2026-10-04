<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\UpdateOrderDetails;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrderRequest;
use App\Http\Requests\Admin\UpdateOrderDetailsRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\PaymentLedger;
use App\Support\Like;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Order::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => OrderStatus::tryFrom((string) $request->query('status')),
            'payment_status' => PaymentStatus::tryFrom((string) $request->query('payment_status')),
            'source' => OrderSource::tryFrom((string) $request->query('source')),
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $orders = Order::query()
            ->withCount('items')
            ->when($filters['search'] !== '', fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when($filters['status'], fn (Builder $query, OrderStatus $status) => $query->where('status', $status))
            ->when($filters['payment_status'], fn (Builder $query, PaymentStatus $status) => $query->where('payment_status', $status))
            ->when($filters['source'], fn (Builder $query, OrderSource $source) => $query->where('source', $source))
            ->when($filters['from'], fn (Builder $query, Carbon $from) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($filters['to'], fn (Builder $query, Carbon $to) => $query->where('created_at', '<=', $to->endOfDay()))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'isFiltered' => array_filter($filters) !== [],
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Order::class);

        $customer = $request->filled('customer') ? Customer::query()->find($request->integer('customer')) : null;

        return view('admin.orders.create', [
            'customer' => $customer,
            'products' => Product::query()
                ->where('status', ProductStatus::Active)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'selling_price', 'discount_price', 'manage_stock', 'stock_quantity', 'stock_status', 'status']),
            'defaults' => [
                'delivery_charge' => config('orders.default_delivery_charge'),
                'payment_method' => PaymentMethod::CashOnDelivery->value,
                'source' => OrderSource::Phone->value,
            ],
        ]);
    }

    public function store(StoreOrderRequest $request, CreateOrder $createOrder): RedirectResponse
    {
        $order = $createOrder->handle($request->validated(), $request->user());

        return to_route('admin.orders.show', $order)->with('success', "Order {$order->order_number} placed.");
    }

    public function show(Order $order, PaymentLedger $ledger): View
    {
        Gate::authorize('view', $order);

        $order->load([
            'customer',
            'creator:id,name',
            'items.product:id,name,status',
            'statusHistories.changedBy:id,name',
            'payments' => fn ($query) => $query->latest('id'),
            'payments.recordedBy:id,name',
            'stockMovements' => fn ($query) => $query->orderBy('id'),
            'stockMovements.product:id,name,sku',
        ]);

        return view('admin.orders.show', [
            'order' => $order,
            'ledger' => $ledger->totals($order),
            'itemsGross' => $order->items->reduce(fn (string $sum, $item) => bcadd($sum, bcmul($item->unit_price, (string) $item->quantity, 2), 2), '0.00'),
            'itemDiscounts' => $order->items->reduce(fn (string $sum, $item) => bcadd($sum, $item->discount_amount, 2), '0.00'),
        ]);
    }

    public function edit(Order $order): View
    {
        Gate::authorize('update', $order);

        return view('admin.orders.edit', ['order' => $order]);
    }

    public function update(UpdateOrderDetailsRequest $request, Order $order, UpdateOrderDetails $updateDetails): RedirectResponse
    {
        $updateDetails->handle($order, $request->validated());

        return to_route('admin.orders.show', $order)->with('success', "Order {$order->order_number} updated.");
    }

    /**
     * Order number / customer name substring, or phone (exact when a full
     * number is typed, otherwise a digit fragment).
     *
     * @param  Builder<Order>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $phone = PhoneNumber::normalize($search);
        $digits = preg_replace('/\D+/', '', $search);

        $query->where(function (Builder $query) use ($search, $phone, $digits): void {
            $query->where('order_number', 'like', Like::contains($search))
                ->orWhere('customer_name', 'like', Like::contains($search));

            if ($phone !== null) {
                $query->orWhere('customer_phone', $phone);
            } elseif (strlen($digits) >= 4) {
                $query->orWhere('customer_phone', 'like', Like::contains($digits));
            }
        });
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }
}
