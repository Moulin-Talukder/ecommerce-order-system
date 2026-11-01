<?php

namespace App\Policies;

use App\Models\User;

/**
 * Admin user management: Super Admin only.
 *
 * - Super Admin accounts are protected: another Super Admin cannot edit,
 *   deactivate or reset them from the Users screen (emergency resets use
 *   `php artisan admin:password-reset`). A Super Admin may edit their own
 *   name and email, but never their own role or status.
 * - New accounts and role changes are limited to Admin, Manager and Staff
 *   (see the requests), so the screen cannot create Super Admins.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin() && $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Name, email and (for staff accounts) role.
     */
    public function update(User $user, User $target): bool
    {
        return $this->viewAny($user) && ($user->is($target) || ! $target->isSuperAdmin());
    }

    /**
     * Activate or deactivate a staff account (never yourself or a Super Admin).
     */
    public function changeStatus(User $user, User $target): bool
    {
        return $this->manageStaffAccount($user, $target);
    }

    /**
     * Set a new password for a staff account (never yourself or a Super Admin).
     */
    public function resetPassword(User $user, User $target): bool
    {
        return $this->manageStaffAccount($user, $target);
    }

    private function manageStaffAccount(User $user, User $target): bool
    {
        return $this->viewAny($user) && ! $user->is($target) && ! $target->isSuperAdmin();
    }
}
