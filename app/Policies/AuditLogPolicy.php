<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is read-only and visible to active Super Admins only
 * (same rule as {@see UserPolicy::viewAny()}). There is deliberately no
 * create, update or delete ability: records are written only by AuditLogger.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin() && $user->isSuperAdmin();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->viewAny($user);
    }
}
