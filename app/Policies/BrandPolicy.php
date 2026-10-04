<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;

/**
 * Viewing: every admin-panel role. Creating, editing, (de)activating:
 * Super Admin, Admin, Manager. Deleting: Super Admin and Admin only.
 */
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin);
    }
}
