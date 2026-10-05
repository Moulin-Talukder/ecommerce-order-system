<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\UpdateOrderDetails;
use App\Enums\CourierProvider;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrderRequest;
use App\Http\Requests\Admin\UpdateOrderDetailsRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Courier\CourierRegistry;
use App\Services\Customers\CustomerService;
use App\Services\Orders\OrderSearch;
use App\Services\Orders\OrderTimeline;
use App\Services\Orders\PaymentLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request, OrderSearch $search): View
    {
        Gate::authorize('viewAny', Order::class);

        $filters = $search->filters($request->query());

        $orders = $search->query($filters)
            ->withCount('items')
            ->with(['latestShipment' => fn ($query) => $query->select([
                'courier_shipments.id', 'courier_shipments.order_id', 'courier_shipments.courier',
                'courier_shipments.consignment_id', 'courier_shipments.status',
            ])])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'isFiltered' => array_filter($filters) !== [],
            'viewCounts' => $search->viewCounts(),
            'filteredCustomer' => $filters['customer'] ? Customer::query()->find($filters['customer'], ['id', 'name']) : null,
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

    public function show(Order $order, PaymentLedger $ledger, OrderTimeline $timeline, CustomerService $customers, CourierRegistry $couriers): View
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
            'shipments' => fn ($query) => $query->latest('id'),
            'shipments.submittedBy:id,name',
            'stockMovements.product:id,name,sku',
        ]);

        return view('admin.orders.show', [
            'order' => $order,
            'timeline' => $timeline->for($order),
            'customerSummary' => $customers->summary($order->customer),
            'otherOrders' => $order->customer->orders()
                ->whereKeyNot($order->id)
                ->latest('id')
                ->limit(5)
                ->get(['id', 'order_number', 'total', 'currency', 'status', 'created_at']),
            'ledger' => $ledger->totals($order),
            'courierConfigured' => $couriers->gateway(CourierProvider::Steadfast)->isConfigured(),
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
}
