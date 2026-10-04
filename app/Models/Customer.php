<?php

namespace App\Models;

use App\Casts\PhoneNumberCast;
use App\Enums\CustomerStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer, identified by their (normalized, unique) phone number.
 *
 * status is not fillable; blocking a customer is an explicit admin action.
 */
#[Fillable(['name', 'phone', 'alternate_phone', 'email', 'address', 'city', 'area', 'postal_code', 'notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone' => PhoneNumberCast::class,
            'alternate_phone' => PhoneNumberCast::class,
            'status' => CustomerStatus::class,
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
