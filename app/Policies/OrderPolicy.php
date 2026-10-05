<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

/**
 * - View orders and the dashboard sales summary: every admin-panel role.
 * - Create orders, edit delivery details/notes, change status, record
 *   payments, book/sync courier shipments: Super Admin, Admin, Manager.
 * - Record refunds (money leaving the business): Super Admin, Admin.
 * - Delete: nobody. Orders are permanent; cancel instead.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, Order $order): bool
    {
        return $user->canAccessAdmin();
    }

    /**
     * Aggregated money figures (sales, collections, outstanding amounts) on
     * the dashboard. Every admin role can already see individual order
     * totals, so this currently matches viewAny; restrict it here if needed.
     */
    public function viewSalesSummary(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function create(User $user): bool
    {
        return $this->canOperate($user);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->canOperate($user);
    }

    public function changeStatus(User $user, Order $order): bool
    {
        return $this->canOperate($user);
    }

    public function recordPayment(User $user, Order $order): bool
    {
        return $this->canOperate($user);
    }

    /**
     * Book the order with a courier (sends data to the courier's API).
     */
    public function bookCourier(User $user, Order $order): bool
    {
        return $this->canOperate($user);
    }

    /**
     * Refresh the courier status / confirm an uncertain booking.
     */
    public function syncCourier(User $user, Order $order): bool
    {
        return $this->canOperate($user);
    }

    public function refund(User $user, Order $order): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin);
    }

    private function canOperate(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }
}
