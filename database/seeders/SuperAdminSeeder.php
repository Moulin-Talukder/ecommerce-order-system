<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Create the initial Super Admin from config/admin.php (.env).
     *
     * Idempotent: if the account already exists it is promoted to Super Admin
     * and re-activated, but its password is left untouched.
     */
    public function run(): void
    {
        $credentials = config('admin.super_admin');

        $validator = Validator::make($credentials, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException(
                'Cannot seed Super Admin. Set valid ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD in .env: '
                .implode(' ', $validator->errors()->all())
            );
        }

        $email = Str::lower($credentials['email']);
        $user = User::query()->firstWhere('email', $email);

        if ($user) {
            $user->forceFill([
                'name' => $credentials['name'],
                'role' => UserRole::SuperAdmin,
                'is_active' => true,
            ])->save();

            $this->command?->info("Super Admin [{$email}] already exists; role and status updated, password unchanged.");

            return;
        }

        // The `hashed` cast on User::$password hashes the plain-text value before it is stored.
        User::query()->forceCreate([
            'name' => $credentials['name'],
            'email' => $email,
            'password' => $credentials['password'],
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Super Admin [{$email}] created. You can now remove ADMIN_PASSWORD from .env.");
    }
}
