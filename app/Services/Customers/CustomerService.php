<?php

namespace App\Services\Customers;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\CustomerStatus;
use App\Enums\OrderStatus;
use App\Exceptions\CannotDeleteException;
use App\Models\Customer;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Write operations and server-side summaries for customers.
 *
 * Phone numbers are normalized by PhoneNumberCast on the model; the unique
 * index on customers.phone is the final guard against duplicates.
 *
 * Every change is audited in its transaction, so a failed audit write rolls
 * the change back (storefront checkout included). Saves that change no
 * audited field are not recorded; personal data is masked by AuditRedactor.
 */
class CustomerService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated customer attributes (status is not accepted).
     */
    public function create(array $data, ?User $actor = null): Customer
    {
        return $this->guardUniquePhone(fn () => DB::transaction(function () use ($data, $actor): Customer {
            $customer = Customer::create($data);
            $this->audit->record(AuditEvent::CustomerCreated, $actor, $customer, new: $this->audit->snapshot($customer));

            return $customer;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  Validated customer attributes (status is not accepted).
     */
    public function update(Customer $customer, array $data, ?User $actor = null): Customer
    {
        return $this->guardUniquePhone(fn () => DB::transaction(function () use ($customer, $data, $actor): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $this->audit->snapshot($locked);

            $locked->update($data);
            $this->audit->recordChanges(AuditEvent::CustomerUpdated, $actor, $locked, $before);

            $customer->setRawAttributes($locked->getAttributes(), true);

            return $customer;
        }));
    }

    /**
     * Find a customer by (normalized) phone, or create one. Used by order
     * placement so the same person is never duplicated because their number
     * was typed differently. An existing customer's saved details are not
     * overwritten; each order keeps its own snapshot instead.
     *
     * Only a customer actually created here is audited (inside the caller's
     * order transaction); finding an existing one records nothing.
     *
     * @param  array<string, mixed>  $data  Customer attributes including phone.
     */
    public function findOrCreateByPhone(array $data, ?User $actor = null, AuditOrigin $origin = AuditOrigin::Admin): Customer
    {
        $phone = PhoneNumber::normalize((string) ($data['phone'] ?? ''))
            ?? throw new InvalidArgumentException('A valid phone number is required.');

        $existing = Customer::query()->where('phone', $phone)->lockForUpdate()->first();

        if ($existing) {
            return $existing;
        }

        try {
            $customer = Customer::create(['phone' => $phone] + $data);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently by another request: use that record.
            return Customer::query()->where('phone', $phone)->firstOrFail();
        }

        $this->audit->record(AuditEvent::CustomerCreated, $actor, $customer, new: $this->audit->snapshot($customer), origin: $origin);

        return $customer;
    }

    /**
     * Block or unblock a customer. Blocking never deletes anything.
     */
    public function setStatus(Customer $customer, CustomerStatus $status, ?User $actor = null): Customer
    {
        return DB::transaction(function () use ($customer, $status, $actor): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $this->audit->snapshot($locked);

            $locked->forceFill(['status' => $status])->save();
            $this->audit->recordChanges(
                $status === CustomerStatus::Blocked ? AuditEvent::CustomerBlocked : AuditEvent::CustomerUnblocked,
                $actor,
                $locked,
                $before,
            );

            $customer->setRawAttributes($locked->getAttributes(), true);

            return $customer;
        });
    }

    /**
     * Delete a customer who has never placed an order.
     *
     * @throws CannotDeleteException
     */
    public function delete(Customer $customer, ?User $actor = null): void
    {
        if ($customer->orders()->exists()) {
            throw new CannotDeleteException("{$customer->name} has orders, so the customer record must be kept. Block the customer instead if needed.");
        }

        try {
            DB::transaction(function () use ($customer, $actor): void {
                $customer->delete();
                $this->audit->record(AuditEvent::CustomerDeleted, $actor, $customer, old: $this->audit->snapshot($customer));
            });
        } catch (QueryException $exception) {
            if (! CannotDeleteException::isReferencedRowError($exception)) {
                throw $exception;
            }

            // An order was placed concurrently; the FK RESTRICT caught it.
            throw new CannotDeleteException("{$customer->name} has orders and cannot be deleted.", previous: $exception);
        }
    }

    /**
     * Order statistics computed from the orders table (never from client input).
     *
     * @return array{orders_count: int, completed_orders_count: int, cancelled_or_returned_count: int, total_spent: string, first_order_at: ?Carbon, last_order_at: ?Carbon}
     */
    public function summary(Customer $customer): array
    {
        $nonRevenue = array_map(fn (OrderStatus $status) => $status->value, OrderStatus::nonRevenue());

        $row = $customer->orders()
            ->toBase()
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_orders_count', [OrderStatus::Delivered->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS cancelled_or_returned_count', $nonRevenue)
            ->selectRaw('COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN total ELSE 0 END), 0) AS total_spent', $nonRevenue)
            ->selectRaw('MIN(created_at) AS first_order_at, MAX(created_at) AS last_order_at')
            ->first();

        return [
            'orders_count' => (int) $row->orders_count,
            'completed_orders_count' => (int) $row->completed_orders_count,
            'cancelled_or_returned_count' => (int) $row->cancelled_or_returned_count,
            'total_spent' => bcadd((string) $row->total_spent, '0', 2),
            'first_order_at' => $row->first_order_at ? Carbon::parse($row->first_order_at) : null,
            'last_order_at' => $row->last_order_at ? Carbon::parse($row->last_order_at) : null,
        ];
    }

    /**
     * Turn a lost race on the unique phone index into a normal validation error.
     *
     * @param  Closure(): Customer  $callback
     */
    private function guardUniquePhone(Closure $callback): Customer
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'customers_phone_unique')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'phone' => 'Another customer already uses this phone number.',
            ]);
        }
    }
}
