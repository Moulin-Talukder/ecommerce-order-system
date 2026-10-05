<?php

namespace App\Models;

use App\Enums\CourierProvider;
use App\Enums\SteadfastStatus;
use Database\Factories\CourierShipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking with a courier. status holds the courier's raw status string
 * (not an enum cast) so unknown future statuses never break the application;
 * provider-specific enums map known values where needed.
 */
#[Fillable([
    'courier', 'invoice', 'consignment_id', 'tracking_code', 'status', 'cod_amount', 'delivery_fee',
    'request_payload', 'response_payload', 'last_error', 'submitted_at', 'last_synced_at',
])]
#[Hidden(['request_payload', 'response_payload'])]
class CourierShipment extends Model
{
    /** @use HasFactory<CourierShipmentFactory> */
    use HasFactory;

    /**
     * Local booking states stored in `status` before the courier has given us
     * its own status. Prefixed so they can never collide with provider values.
     */
    public const STATUS_BOOKING = 'local_booking';

    public const STATUS_BOOKING_FAILED = 'local_booking_failed';

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'courier' => 'steadfast',
        'status' => 'pending',
        'cod_amount' => '0.00',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'courier' => CourierProvider::class,
            'cod_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'submitted_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Shipments the courier has accepted (we hold its consignment id, or it
     * has reported a status for our invoice).
     *
     * @param  Builder<CourierShipment>  $query
     */
    #[Scope]
    protected function booked(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('consignment_id')
            ->orWhereNotIn('status', [self::STATUS_BOOKING, self::STATUS_BOOKING_FAILED]));
    }

    public function isBooked(): bool
    {
        return $this->consignment_id !== null || ! in_array($this->status, [self::STATUS_BOOKING, self::STATUS_BOOKING_FAILED], true);
    }

    public function isBookingFailed(): bool
    {
        return $this->consignment_id === null && $this->status === self::STATUS_BOOKING_FAILED;
    }

    /**
     * A booking request was sent (or is being sent) and its outcome is not yet known.
     */
    public function isBookingPending(): bool
    {
        return $this->consignment_id === null && $this->status === self::STATUS_BOOKING;
    }

    /**
     * Readable courier status, tolerant of unknown provider values.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isBookingPending() => 'Booking not confirmed yet',
            $this->isBookingFailed() => 'Booking failed',
            default => SteadfastStatus::describe($this->status),
        };
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
