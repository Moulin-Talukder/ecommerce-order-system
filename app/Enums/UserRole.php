<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';

    /**
     * Human-readable name for display in the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Staff => 'Staff',
        };
    }

    /**
     * Whether users with this role may sign in to the admin panel.
     */
    public function canAccessAdmin(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Admin, self::Manager, self::Staff => true,
        };
    }
}
