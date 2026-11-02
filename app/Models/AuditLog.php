<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Exceptions\Audit\AuditLogImmutableException;
use App\Services\Audit\AuditLogger;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One append-only audit record, written only by
 * {@see AuditLogger}.
 *
 * Nothing is mass-assignable (the logger force-fills redacted values), and
 * existing rows can never be changed or deleted through Eloquent: the model
 * and its query builder both refuse updates and deletes.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Short, stable names stored in subject_type (never PHP class names).
     *
     * @var array<class-string<Model>, string>
     */
    public const SUBJECT_TYPES = [
        User::class => 'user',
        Product::class => 'product',
        ProductImage::class => 'product_image',
        Category::class => 'category',
        Brand::class => 'brand',
        Customer::class => 'customer',
        Order::class => 'order',
        Payment::class => 'payment',
        CourierShipment::class => 'courier_shipment',
    ];

    /**
     * @throws InvalidArgumentException when the model is not an audit subject
     */
    public static function subjectTypeFor(Model $subject): string
    {
        return self::SUBJECT_TYPES[$subject::class]
            ?? throw new InvalidArgumentException(class_basename($subject).' is not an audit subject.');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'origin' => AuditOrigin::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * The user who acted, while their account exists. The actor_* snapshot
     * columns keep their identity as it was at the time.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @throws AuditLogImmutableException for an existing record
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw AuditLogImmutableException::forOperation('updating a record');
        }

        return parent::save($options);
    }

    /**
     * @throws AuditLogImmutableException for an existing record
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw AuditLogImmutableException::forOperation('updating a record');
        }

        return parent::update($attributes, $options);
    }

    /**
     * @throws AuditLogImmutableException
     */
    public function delete(): ?bool
    {
        throw AuditLogImmutableException::forOperation('deleting a record');
    }

    /**
     * @throws AuditLogImmutableException
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method): int
    {
        throw AuditLogImmutableException::forOperation('updating a record');
    }

    /**
     * A query builder that refuses bulk updates and deletes, so
     * AuditLog::query()->update()/delete() cannot bypass the model guards.
     *
     * @return Builder<static>
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw AuditLogImmutableException::forOperation('updating records');
            }

            public function upsert(array $values, $uniqueBy, $update = null)
            {
                throw AuditLogImmutableException::forOperation('upserting records');
            }

            public function touch($column = null)
            {
                throw AuditLogImmutableException::forOperation('updating records');
            }

            public function increment($column, $amount = 1, array $extra = [])
            {
                throw AuditLogImmutableException::forOperation('updating records');
            }

            public function decrement($column, $amount = 1, array $extra = [])
            {
                throw AuditLogImmutableException::forOperation('updating records');
            }

            public function incrementEach(array $columns, array $extra = [])
            {
                throw AuditLogImmutableException::forOperation('updating records');
            }

            public function decrementEach(array $columns, array $extra = [])
            {
                throw AuditLogImmutableException::forOperation('updating records');
            }

            public function delete()
            {
                throw AuditLogImmutableException::forOperation('deleting records');
            }

            public function forceDelete()
            {
                throw AuditLogImmutableException::forOperation('deleting records');
            }
        };
    }
}
