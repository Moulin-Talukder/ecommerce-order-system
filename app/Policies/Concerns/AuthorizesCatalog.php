<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Shared catalog permission split (categories, brands, products):
 * view = every admin-panel role; manage = Super Admin, Admin, Manager;
 * delete = Super Admin, Admin.
 */
trait AuthorizesCatalog
{
    protected function canView(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    protected function canManage(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin, UserRole::Manager);
    }

    protected function canDelete(User $user): bool
    {
        return $user->hasRole(UserRole::SuperAdmin, UserRole::Admin);
    }
}
