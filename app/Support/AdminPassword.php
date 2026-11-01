<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Password strength for admin-panel accounts: at least 12 characters with
 * upper- and lower-case letters and a number. Shared by the Super Admin
 * seeder, the Users screen and the admin:password-reset command.
 */
final class AdminPassword
{
    public static function rule(): Password
    {
        return Password::min(12)->letters()->mixedCase()->numbers();
    }
}
