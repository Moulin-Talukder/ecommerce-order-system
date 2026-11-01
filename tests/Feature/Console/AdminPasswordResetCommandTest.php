<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Exception\RuntimeException;
use Tests\TestCase;

class AdminPasswordResetCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Emergency-Pass-42';

    private const ASK = 'New password (at least 12 characters, upper- and lower-case letters and a number)';

    private const CONFIRM = 'Confirm the new password';

    public function test_it_resets_a_super_admins_password_without_printing_it(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->superAdmin()->create(['email' => 'owner@example.test']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()]);
        $token = $admin->remember_token;

        $this->artisan('admin:password-reset', ['email' => 'OWNER@example.test'])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::CONFIRM, self::PASSWORD)
            ->expectsOutputToContain('A new password was set for owner@example.test (Super Admin).')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNotSame(self::PASSWORD, $admin->getRawOriginal('password'));
        $this->assertNotSame($token, $admin->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
    }

    public function test_unknown_emails_are_refused_without_asking_for_a_password(): void
    {
        $this->artisan('admin:password-reset', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('No admin-panel account uses that email address.')
            ->assertFailed();
    }

    public function test_a_mismatched_or_weak_password_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        $this->artisan('admin:password-reset', ['email' => 'staff@example.test'])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::CONFIRM, 'Different-Pass-42')
            ->expectsOutputToContain('confirmation does not match')
            ->assertFailed();

        $this->artisan('admin:password-reset', ['email' => 'staff@example.test'])
            ->expectsQuestion(self::ASK, 'weak')
            ->expectsQuestion(self::CONFIRM, 'weak')
            ->assertFailed();

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_inactive_accounts_get_a_new_password_but_stay_inactive(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'gone@example.test']);

        $this->artisan('admin:password-reset', ['email' => 'gone@example.test'])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::CONFIRM, self::PASSWORD)
            ->expectsOutputToContain('This account is inactive')
            ->assertSuccessful();

        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_passwords_cannot_be_given_on_the_command_line(): void
    {
        User::factory()->superAdmin()->create(['email' => 'owner@example.test']);

        $this->expectException(InvalidOptionException::class);
        $this->artisan('admin:password-reset', ['email' => 'owner@example.test', '--password' => self::PASSWORD]);
    }

    public function test_the_email_is_required(): void
    {
        $this->expectException(RuntimeException::class);
        $this->artisan('admin:password-reset');
    }
}
