<?php

namespace App\Models;

use App\Casts\PhoneNumberCast;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An order with customer/delivery snapshots and server-calculated totals.
 *
 * Only snapshot and descriptive fields are fillable. The order number,
 * customer link, totals, status and payment summary (payment_status,
 * amount_paid) are set exclusively by the order/payment services via
 * forceFill(), so they can never be mass-assigned from request input.
 */
#[Fillable([
    'customer_name', 'customer_phone', 'customer_alternate_phone', 'customer_email',
    'shipping_address', 'shipping_city', 'shipping_area', 'shipping_postal_code',
    'payment_method', 'source', 'customer_note', 'admin_note',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'BDT',
        'subtotal' => '0.00',
        'discount_total' => '0.00',
        'delivery_charge' => '0.00',
        'total' => '0.00',
        'amount_paid' => '0.00',
        'payment_status' => 'pending',
        'status' => 'pending',
        'source' => 'website',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_phone' => PhoneNumberCast::class,
            'customer_alternate_phone' => PhoneNumberCast::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
        ];
    }

    /**
     * Amount still owed (e.g. the cash-on-delivery amount for the courier).
     */
    public function amountDue(): string
    {
        $due = bcsub($this->total, $this->amount_paid, 2);

        return bccomp($due, '0', 2) === 1 ? $due : '0.00';
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Status timeline, oldest first.
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<CourierShipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(CourierShipment::class);
    }

    /**
     * The current (most recent) courier shipment.
     *
     * @return HasOne<CourierShipment, $this>
     */
    public function latestShipment(): HasOne
    {
        return $this->hasOne(CourierShipment::class)->latestOfMany();
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Admin user who entered the order (null for storefront orders).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
