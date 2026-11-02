<?php

namespace App\Console\Commands;

use App\Enums\AuditOrigin;
use App\Models\User;
use App\Services\Users\UserService;
use App\Support\AdminPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Emergency password reset for an admin-panel account, e.g. a locked-out
 * Super Admin. The password is only ever read from a hidden prompt (never
 * an argument, so it stays out of shell history and process lists), is
 * hashed by the User model, and is never printed or logged. Existing
 * sessions and remember-me logins of the account are ended.
 */
class ResetAdminPassword extends Command
{
    /**
     * @var string
     */
    protected $signature = 'admin:password-reset {email : Email address of the admin-panel account}';

    /**
     * @var string
     */
    protected $description = 'Set a new password for an admin-panel account (prompts for the password)';

    public function handle(UserService $users): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->role->canAccessAdmin()) {
            $this->error('No admin-panel account uses that email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (at least 12 characters, upper- and lower-case letters and a number)');
        $confirmation = (string) $this->secret('Confirm the new password');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'confirmed', AdminPassword::rule()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $users->resetPassword($user, $password, origin: AuditOrigin::Cli);

        $this->info("A new password was set for {$user->email} ({$user->role->label()}). Existing sessions were signed out.");

        if (! $user->is_active) {
            $this->warn('This account is inactive, so it still cannot sign in until it is activated.');
        }

        return self::SUCCESS;
    }
}
