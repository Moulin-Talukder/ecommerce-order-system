<?php

namespace App\Services\Users;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\UserRole;
use App\Exceptions\Users\UserManagementException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes for admin user management. Role and status are only ever set here
 * (never mass-assigned), and the application always keeps at least one
 * active Super Admin.
 *
 * Every change is audited inside its own transaction: if the audit row
 * cannot be written, the change is rolled back. Changes that change nothing
 * are not audited.
 */
class UserService
{
    /** Fields recorded for user.created / user.updated. */
    private const PROFILE_FIELDS = ['name', 'email'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, password: string, role: UserRole|string, is_active: bool}  $data
     */
    public function create(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
            $user->forceFill(['role' => $data['role'], 'is_active' => $data['is_active'], 'email_verified_at' => now()])->save();

            $this->audit->record(AuditEvent::UserCreated, $actor, $user, new: $user->only([...self::PROFILE_FIELDS, 'role', 'is_active']));

            return $user;
        });
    }

    /**
     * A role change is recorded as user.role_changed and a name/email change
     * as user.updated, so changing both writes one row of each.
     *
     * @param  array{name: string, email: string, role?: UserRole|string}  $data
     *
     * @throws UserManagementException when it would demote the last active Super Admin
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = isset($data['role']) ? UserRole::from($data['role'] instanceof UserRole ? $data['role']->value : $data['role']) : $locked->role;

            if ($role !== UserRole::SuperAdmin) {
                $this->ensureAnotherActiveSuperAdmin($locked, 'change the role of');
            }

            $locked->fill(['name' => $data['name'], 'email' => Str::lower($data['email'])]);
            $locked->forceFill(['role' => $role]);
            $changed = array_keys($locked->getDirty());
            $before = $locked->getOriginal();
            $locked->save();

            if ($profile = array_values(array_intersect(self::PROFILE_FIELDS, $changed))) {
                $this->audit->record(AuditEvent::UserUpdated, $actor, $locked, Arr::only($before, $profile), $locked->only($profile));
            }

            if (in_array('role', $changed, true)) {
                $this->audit->record(AuditEvent::UserRoleChanged, $actor, $locked, ['role' => $before['role']], ['role' => $locked->role]);
            }

            return $locked;
        });
    }

    /**
     * Activate or deactivate. Deactivating also ends the user's sessions and
     * remember-me logins, so access stops immediately.
     *
     * @throws UserManagementException when it would deactivate the last active Super Admin
     */
    public function setActive(User $user, bool $active, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $active, $actor): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $wasActive = $locked->is_active;

            if (! $active) {
                $this->ensureAnotherActiveSuperAdmin($locked, 'deactivate');
            }

            $locked->forceFill(['is_active' => $active])->save();

            if (! $active) {
                $this->endSessions($locked);
            }

            if ($wasActive !== $active) {
                $this->audit->record(
                    $active ? AuditEvent::UserActivated : AuditEvent::UserDeactivated,
                    $actor,
                    $locked,
                    ['is_active' => $wasActive],
                    ['is_active' => $active],
                    $active ? [] : ['sessions_ended' => true],
                );
            }

            return $locked;
        });
    }

    /**
     * Set a new password (hashed by the model's "hashed" cast) and end the
     * user's existing sessions and remember-me logins. Only the fact of the
     * reset is audited, never the password.
     */
    public function resetPassword(User $user, string $password, ?User $actor = null, AuditOrigin $origin = AuditOrigin::Admin): User
    {
        return DB::transaction(function () use ($user, $password, $actor, $origin): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill(['password' => $password])->save();
            $this->endSessions($locked);

            $this->audit->record(
                AuditEvent::UserPasswordReset,
                $actor,
                $locked,
                context: ['sessions_ended' => true],
                origin: $origin,
                description: $origin === AuditOrigin::Cli ? 'Password reset with the emergency admin:password-reset command' : null,
            );

            return $locked;
        });
    }

    /**
     * Delete stored sessions (database driver) and rotate the remember-me token.
     */
    public function endSessions(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /**
     * @throws UserManagementException
     */
    private function ensureAnotherActiveSuperAdmin(User $user, string $action): void
    {
        if (! $user->isSuperAdmin() || ! $user->is_active) {
            return;
        }

        $others = User::query()
            ->where('role', UserRole::SuperAdmin)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw new UserManagementException("You cannot {$action} the last active Super Admin.");
        }
    }
}
