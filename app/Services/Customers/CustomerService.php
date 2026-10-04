<?php

namespace App\Services\Customers;

use App\Enums\CustomerStatus;
use App\Enums\OrderStatus;
use App\Exceptions\CannotDeleteException;
use App\Models\Customer;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Write operations and server-side summaries for customers.
 *
 * Phone numbers are normalized by PhoneNumberCast on the model; the unique
 * index on customers.phone is the final guard against duplicates.
 */
class CustomerService
{
    /**
     * @param  array<string, mixed>  $data  Validated customer attributes (status is not accepted).
     */
    public function create(array $data): Customer
    {
        return $this->guardUniquePhone(fn () => Customer::create($data));
    }

    /**
     * @param  array<string, mixed>  $data  Validated customer attributes (status is not accepted).
     */
    public function update(Customer $customer, array $data): Customer
    {
        return $this->guardUniquePhone(function () use ($customer, $data): Customer {
            $customer->update($data);

            return $customer;
        });
    }

    /**
     * Find a customer by (normalized) phone, or create one. Used by order
     * placement so the same person is never duplicated because their number
     * was typed differently. An existing customer's saved details are not
     * overwritten; each order keeps its own snapshot instead.
     *
     * @param  array<string, mixed>  $data  Customer attributes including phone.
     */
    public function findOrCreateByPhone(array $data): Customer
    {
        $phone = PhoneNumber::normalize((string) ($data['phone'] ?? ''))
            ?? throw new InvalidArgumentException('A valid phone number is required.');

        $existing = Customer::query()->where('phone', $phone)->lockForUpdate()->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Customer::create(['phone' => $phone] + $data);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently by another request: use that record.
            return Customer::query()->where('phone', $phone)->firstOrFail();
        }
    }

    /**
     * Block or unblock a customer. Blocking never deletes anything.
     */
    public function setStatus(Customer $customer, CustomerStatus $status): Customer
    {
        $customer->forceFill(['status' => $status])->save();

        return $customer;
    }

    /**
     * Delete a customer who has never placed an order.
     *
     * @throws CannotDeleteException
     */
    public function delete(Customer $customer): void
    {
        if ($customer->orders()->exists()) {
            throw new CannotDeleteException("{$customer->name} has orders, so the customer record must be kept. Block the customer instead if needed.");
        }

        try {
            $customer->delete();
        } catch (QueryException $exception) {
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
