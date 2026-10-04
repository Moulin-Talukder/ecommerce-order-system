<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;

/**
 * Viewing: every admin-panel role. Creating, editing, blocking/unblocking:
 * Super Admin, Admin, Manager. Deleting (customers without orders only):
 * Super Admin, Admin.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->canAccessAdmin();
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }

    public function changeStatus(User $user, Customer $customer): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin);
    }
}
