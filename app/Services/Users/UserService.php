<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Exceptions\Users\UserManagementException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes for admin user management. Role and status are only ever set here
 * (never mass-assigned), and the application always keeps at least one
 * active Super Admin.
 */
class UserService
{
    /**
     * @param  array{name: string, email: string, password: string, role: UserRole|string, is_active: bool}  $data
     */
    public function create(array $data): User
    {
        $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
        $user->forceFill(['role' => $data['role'], 'is_active' => $data['is_active'], 'email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * @param  array{name: string, email: string, role?: UserRole|string}  $data
     *
     * @throws UserManagementException when it would demote the last active Super Admin
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = isset($data['role']) ? UserRole::from($data['role'] instanceof UserRole ? $data['role']->value : $data['role']) : $locked->role;

            if ($role !== UserRole::SuperAdmin) {
                $this->ensureAnotherActiveSuperAdmin($locked, 'change the role of');
            }

            $locked->fill(['name' => $data['name'], 'email' => Str::lower($data['email'])]);
            $locked->forceFill(['role' => $role])->save();

            return $locked;
        });
    }

    /**
     * Activate or deactivate. Deactivating also ends the user's sessions and
     * remember-me logins, so access stops immediately.
     *
     * @throws UserManagementException when it would deactivate the last active Super Admin
     */
    public function setActive(User $user, bool $active): User
    {
        return DB::transaction(function () use ($user, $active): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $active) {
                $this->ensureAnotherActiveSuperAdmin($locked, 'deactivate');
            }

            $locked->forceFill(['is_active' => $active])->save();

            if (! $active) {
                $this->endSessions($locked);
            }

            return $locked;
        });
    }

    /**
     * Set a new password (hashed by the model's "hashed" cast) and end the
     * user's existing sessions and remember-me logins.
     */
    public function resetPassword(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill(['password' => $password])->save();
            $this->endSessions($locked);

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
