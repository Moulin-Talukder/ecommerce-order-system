<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AdminPasswordResetAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Emergency-Audit-Pass-42';

    private const ASK = 'New password (at least 12 characters, upper- and lower-case letters and a number)';

    private const CONFIRM = 'Confirm the new password';

    public function test_a_successful_emergency_reset_is_recorded_as_a_cli_action(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'owner@example.test']);
        $oldHash = $admin->password;
        $oldToken = $admin->remember_token;

        $this->artisan('admin:password-reset', ['email' => 'owner@example.test'])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::CONFIRM, self::PASSWORD)
            ->assertSuccessful();

        $admin->refresh();
        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::UserPasswordReset, $log->event);
        $this->assertSame(AuditOrigin::Cli, $log->origin);
        $this->assertSame([null, null, null], [$log->actor_id, $log->actor_name, $log->actor_email]);
        $this->assertSame(['user', $admin->id, 'owner@example.test'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame('Password reset with the emergency admin:password-reset command', $log->description);
        $this->assertSame([null, null, ['sessions_ended' => true]], [$log->old_values, $log->new_values, $log->context]);
        $this->assertSame([null, null], [$log->ip_address, $log->user_agent]);

        $raw = (string) json_encode(DB::table('audit_logs')->get());
        foreach ([self::PASSWORD, $oldHash, $admin->password, $oldToken, $admin->remember_token] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_refused_resets_record_nothing(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);

        $this->artisan('admin:password-reset', ['email' => 'nobody@example.test'])->assertFailed();
        $this->artisan('admin:password-reset', ['email' => 'staff@example.test'])
            ->expectsQuestion(self::ASK, self::PASSWORD)
            ->expectsQuestion(self::CONFIRM, 'Different-Pass-42')
            ->assertFailed();
        $this->artisan('admin:password-reset', ['email' => 'staff@example.test'])
            ->expectsQuestion(self::ASK, 'weak')
            ->expectsQuestion(self::CONFIRM, 'weak')
            ->assertFailed();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_the_password_is_not_changed_when_the_audit_record_cannot_be_written(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);
        $this->mock(AuditLogger::class, fn (MockInterface $mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable')));

        try {
            $this->artisan('admin:password-reset', ['email' => 'staff@example.test'])
                ->expectsQuestion(self::ASK, self::PASSWORD)
                ->expectsQuestion(self::CONFIRM, self::PASSWORD)
                ->run();
            $this->fail('The command must fail when the reset cannot be audited.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertTrue(Hash::check('password', $user->refresh()->password), 'The password reset was rolled back.');
    }

    public function test_seeding_the_initial_super_admin_is_not_audited(): void
    {
        config(['admin.super_admin' => ['name' => 'Seeded Owner', 'email' => 'seeded@example.test', 'password' => 'Seeded-Owner-Pass-42']]);

        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $this->assertTrue(User::query()->where('email', 'seeded@example.test')->exists());
        $this->assertSame(0, AuditLog::query()->count());
    }
}
