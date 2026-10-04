<?php

namespace App\Actions\Orders;

use App\Enums\CustomerStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Enums\StockStatus;
use App\Events\OrderPlaced;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Orders\OrderException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\Inventory\StockService;
use App\Services\Orders\OrderCalculator;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Places an order. The whole operation is one database transaction:
 *
 *  1. resolve the customer by normalized phone (find or create) and refuse
 *     blocked customers;
 *  2. lock every product row (SELECT ... FOR UPDATE) in ascending id order,
 *     so concurrent orders always acquire locks in the same order;
 *  3. check each product is orderable and in stock;
 *  4. calculate all amounts server-side ({@see OrderCalculator});
 *  5. insert the order + item snapshots with a generated order number;
 *  6. deduct stock through {@see StockService} (sale movements linked to
 *     the order);
 *  7. write the initial status history row.
 *
 * Any failure rolls everything back. {@see OrderPlaced} is dispatched only
 * after the transaction commits.
 */
class CreateOrder
{
    private const MAX_NUMBER_ATTEMPTS = 3;

    public function __construct(
        private CustomerService $customers,
        private StockService $stock,
        private OrderCalculator $calculator,
        private OrderNumberGenerator $numbers,
    ) {}

    /**
     * @param array{
     *     customer_name: string,
     *     customer_phone: string,
     *     customer_alternate_phone?: string|null,
     *     customer_email?: string|null,
     *     shipping_address: string,
     *     shipping_area?: string|null,
     *     shipping_city?: string|null,
     *     shipping_postal_code?: string|null,
     *     items: list<array{product_id: int|string, quantity: int|string}>,
     *     delivery_charge: string,
     *     discount_total?: string|null,
     *     payment_method: PaymentMethod|string,
     *     source: OrderSource|string,
     *     customer_note?: string|null,
     *     admin_note?: string|null,
     * } $data
     *
     * @throws OrderException|InsufficientStockException
     */
    public function handle(array $data, ?User $placedBy = null): Order
    {
        $quantities = $this->quantitiesByProduct($data['items']);

        for ($attempt = 1; ; $attempt++) {
            try {
                $order = DB::transaction(fn () => $this->place($data, $quantities, $placedBy));
                break;
            } catch (UniqueConstraintViolationException $exception) {
                if (! str_contains($exception->getMessage(), 'orders_order_number_unique') || $attempt >= self::MAX_NUMBER_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $quantities  product id => quantity, sorted by id
     */
    private function place(array $data, array $quantities, ?User $placedBy): Order
    {
        $customer = $this->customers->findOrCreateByPhone([
            'name' => $data['customer_name'],
            'phone' => $data['customer_phone'],
            'alternate_phone' => $data['customer_alternate_phone'] ?? null,
            'email' => $data['customer_email'] ?? null,
            'address' => $data['shipping_address'],
            'area' => $data['shipping_area'] ?? null,
            'city' => $data['shipping_city'] ?? null,
            'postal_code' => $data['shipping_postal_code'] ?? null,
        ]);

        if ($customer->status === CustomerStatus::Blocked) {
            throw OrderException::customerBlocked($customer->name);
        }

        $products = Product::query()
            ->whereKey(array_keys($quantities))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId) ?? throw OrderException::productUnavailable($productId);

            if (! $product->isOrderable()) {
                throw OrderException::productNotOrderable($product->name);
            }

            if ($product->manage_stock && $product->stock_quantity < $quantity) {
                throw InsufficientStockException::for($product, $quantity);
            }

            if (! $product->manage_stock && $product->stock_status === StockStatus::OutOfStock) {
                throw OrderException::outOfStock($product->name);
            }

            $lines[] = ['product' => $product, 'quantity' => $quantity];
        }

        $totals = $this->calculator->calculate($lines, (string) $data['delivery_charge'], (string) ($data['discount_total'] ?? '0'));

        $order = new Order([
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'customer_alternate_phone' => $data['customer_alternate_phone'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'shipping_address' => $data['shipping_address'],
            'shipping_area' => $data['shipping_area'] ?? null,
            'shipping_city' => $data['shipping_city'] ?? null,
            'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
            'payment_method' => $data['payment_method'],
            'source' => $data['source'],
            'customer_note' => $data['customer_note'] ?? null,
            'admin_note' => $data['admin_note'] ?? null,
        ]);

        // Server-controlled fields: never mass-assignable.
        $order->forceFill([
            'order_number' => $this->numbers->generate(),
            'customer_id' => $customer->id,
            'currency' => config('orders.currency'),
            'subtotal' => $totals->subtotal,
            'discount_total' => $totals->discountTotal,
            'delivery_charge' => $totals->deliveryCharge,
            'total' => $totals->total,
            'amount_paid' => '0.00',
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::Pending,
            'created_by' => $placedBy?->id,
        ])->save();

        $order->items()->createMany(array_map(fn ($line) => $line->toArray(), $totals->lines));

        foreach ($quantities as $productId => $quantity) {
            $product = $products[$productId];

            if ($product->manage_stock) {
                $this->stock->adjust($product, -$quantity, StockMovementType::Sale, $placedBy, "Order {$order->order_number}", $order);
            }
        }

        $order->statusHistories()->create([
            'old_status' => null,
            'new_status' => OrderStatus::Pending,
            'changed_by' => $placedBy?->id,
            'note' => 'Order placed',
        ]);

        return $order;
    }

    /**
     * @param  list<array{product_id: int|string, quantity: int|string}>  $items
     * @return array<int, int> product id => quantity, sorted by product id
     *
     * @throws OrderException
     */
    private function quantitiesByProduct(array $items): array
    {
        if ($items === []) {
            throw new OrderException('An order needs at least one item.');
        }

        $quantities = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];

            if ($quantity < 1) {
                throw new OrderException('Every item quantity must be at least 1.');
            }

            if (isset($quantities[$productId])) {
                throw new OrderException('Each product may appear only once in an order; adjust the quantity instead.');
            }

            $quantities[$productId] = $quantity;
        }

        ksort($quantities);

        return $quantities;
    }
}
