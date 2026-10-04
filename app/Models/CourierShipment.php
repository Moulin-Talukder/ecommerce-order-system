<?php

namespace App\Models;

use App\Enums\CourierProvider;
use Database\Factories\CourierShipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
