<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG_PASSWORD = 'Wrong-Guess-Pass-42';

    private const IP = '203.0.113.50';

    private const USER_AGENT = 'ExampleBrowser/3.0';

    private function fromClient(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::IP])->withHeader('User-Agent', self::USER_AGENT);
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        return AuditLog::query()->orderBy('id')->pluck('event')->map(fn (AuditEvent $event) => $event->value)->all();
    }

    private function rawAuditRows(): string
    {
        return (string) json_encode(DB::table('audit_logs')->get());
    }

    private function failAuditWrites(): void
    {
        $this->mock(AuditLogger::class, fn (MockInterface $mock) => $mock->shouldReceive('record')->andThrow(
            new RuntimeException('SQLSTATE[HY000]: insert into audit_logs ... '.self::WRONG_PASSWORD),
        ));
    }

    public function test_an_interactive_login_is_recorded_once(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        $this->fromClient()->post(route('login'), ['email' => 'staff@example.test', 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.orders.index'))->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::AuthLoginSucceeded, $log->event);
        $this->assertSame(AuditOrigin::Admin, $log->origin);
        $this->assertSame([$user->id, $user->id, 'user'], [$log->actor_id, $log->subject_id, $log->subject_type]);
        $this->assertSame(['remember' => false], $log->context);
        $this->assertSame([self::IP, self::USER_AGENT], [$log->ip_address, $log->user_agent]);
        $this->assertStringNotContainsString('"password"', $this->rawAuditRows());
    }

    public function test_an_interactive_login_with_remember_me_ticked_is_still_recorded(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);

        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => 'password', 'remember' => 'on']);

        $this->assertSame(['auth.login_succeeded'], $this->events());
        $this->assertSame(['remember' => true], AuditLog::query()->sole()->context);
    }

    public function test_a_session_restored_from_the_remember_me_cookie_is_not_recorded(): void
    {
        $user = User::factory()->create();
        $recaller = auth()->guard('web')->getRecallerName();

        $this->withCookie($recaller, $user->id.'|'.$user->remember_token.'|'.$user->password)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(auth()->guard('web')->viaRemember(), 'The session really was restored from the cookie.');
        $this->assertSame([], $this->events());
    }

    public function test_a_failed_login_for_a_known_account_names_the_account_but_not_the_attempt(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        $this->fromClient()->post(route('login'), ['email' => 'STAFF@example.test', 'password' => self::WRONG_PASSWORD])->assertSessionHasErrors('email');

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::AuthLoginFailed, $log->event);
        $this->assertNull($log->actor_id, 'Whoever typed the password is not known to be the account owner.');
        $this->assertSame(['user', $user->id], [$log->subject_type, $log->subject_id]);
        $this->assertSame([null, null, null], [$log->old_values, $log->new_values, $log->context]);
        $this->assertStringNotContainsString(self::WRONG_PASSWORD, $this->rawAuditRows());
        $this->assertStringNotContainsString('STAFF@', $this->rawAuditRows());
    }

    public function test_a_failed_login_for_an_unknown_email_records_only_ip_and_user_agent(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);

        $this->fromClient()->post(route('login'), ['email' => 'ghost.visitor@unknown-domain.test', 'password' => self::WRONG_PASSWORD])->assertSessionHasErrors('email');

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::AuthLoginFailed, $log->event);
        $this->assertSame([null, null, null, null], [$log->actor_id, $log->actor_name, $log->actor_email, $log->actor_role]);
        $this->assertSame([null, null, null], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame([null, null, null], [$log->old_values, $log->new_values, $log->context]);
        $this->assertSame([self::IP, self::USER_AGENT], [$log->ip_address, $log->user_agent]);
        $this->assertSame('Failed sign-in attempt', $log->description);

        $raw = $this->rawAuditRows();
        foreach (['ghost.visitor', 'unknown-domain', self::WRONG_PASSWORD] as $attempted) {
            $this->assertStringNotContainsString($attempted, $raw);
        }
    }

    public function test_a_lockout_is_recorded_after_the_failed_attempts(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), ['email' => 'staff@example.test', 'password' => self::WRONG_PASSWORD]);
        }
        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => 'password'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertSame([...array_fill(0, 5, 'auth.login_failed'), 'auth.login_locked'], $this->events());
        $locked = AuditLog::query()->where('event', AuditEvent::AuthLoginLocked)->sole();
        $this->assertSame([null, $user->id, null], [$locked->actor_id, $locked->subject_id, $locked->context]);
        $this->assertGuest();
        $this->assertStringNotContainsString(self::WRONG_PASSWORD, $this->rawAuditRows());
    }

    public function test_a_lockout_for_an_unknown_email_stores_no_email(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->post(route('login'), ['email' => 'ghost.visitor@unknown-domain.test', 'password' => self::WRONG_PASSWORD]);
        }

        $locked = AuditLog::query()->where('event', AuditEvent::AuthLoginLocked)->sole();
        $this->assertSame([null, null], [$locked->actor_id, $locked->subject_id]);
        $this->assertStringNotContainsString('ghost.visitor', $this->rawAuditRows());
    }

    public function test_a_deactivated_account_with_the_right_password_is_recorded_as_such(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'gone@example.test']);

        $this->post(route('login'), ['email' => 'gone@example.test', 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(['auth.deactivated_login_attempt'], $this->events());
        $this->assertSame([null, $user->id], [AuditLog::query()->sole()->actor_id, AuditLog::query()->sole()->subject_id]);
    }

    public function test_other_failures_are_not_labelled_as_deactivated_account_attempts(): void
    {
        User::factory()->inactive()->create(['email' => 'gone@example.test']);
        User::factory()->create(['email' => 'staff@example.test']);

        $this->post(route('login'), ['email' => 'gone@example.test', 'password' => self::WRONG_PASSWORD]);
        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => self::WRONG_PASSWORD]);
        $this->post(route('login'), ['email' => 'nobody@example.test', 'password' => self::WRONG_PASSWORD]);
        $this->post(route('login'), ['email' => '', 'password' => ''])->assertSessionHasErrors(['email', 'password']);

        $this->assertSame(['auth.login_failed', 'auth.login_failed', 'auth.login_failed'], $this->events());
    }

    public function test_an_explicit_logout_is_recorded_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('logout'));

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::AuthLogout, $log->event);
        $this->assertSame([AuditOrigin::Admin, $user->id, $user->id, null], [$log->origin, $log->actor_id, $log->subject_id, $log->context]);
        $this->assertGuest();
    }

    public function test_a_forced_logout_of_a_deactivated_user_is_recorded_as_a_system_action(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('login'));

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::AuthLogout, $log->event);
        $this->assertSame(AuditOrigin::System, $log->origin);
        $this->assertSame([null, $user->id], [$log->actor_id, $log->subject_id]);
        $this->assertSame(['forced' => true, 'reason' => 'account_deactivated'], $log->context);
        $this->assertSame('Signed out automatically by the system', $log->description);
        $this->assertGuest();
    }

    public function test_a_forced_logout_does_not_mark_a_later_explicit_logout(): void
    {
        $inactive = User::factory()->inactive()->create();
        $active = User::factory()->create();

        $this->actingAs($inactive)->get(route('admin.dashboard'));
        $this->actingAs($active)->post(route('logout'));

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([AuditOrigin::System, AuditOrigin::Admin], $logs->pluck('origin')->all());
        $this->assertNull($logs[1]->context);
    }

    public function test_an_audit_failure_never_blocks_a_successful_login(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.test']);
        $this->failAuditWrites();

        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => 'password'])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_audit_failure_never_turns_a_failed_login_into_a_server_error(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);
        User::factory()->inactive()->create(['email' => 'gone@example.test']);
        $this->failAuditWrites();

        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => self::WRONG_PASSWORD])->assertRedirect()->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'nobody@example.test', 'password' => self::WRONG_PASSWORD])->assertRedirect()->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'gone@example.test', 'password' => 'password'])->assertRedirect()->assertSessionHasErrors('email');

        foreach (range(1, 4) as $attempt) {
            $this->post(route('login'), ['email' => 'staff@example.test', 'password' => self::WRONG_PASSWORD]);
        }
        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => self::WRONG_PASSWORD])->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_an_audit_failure_never_blocks_a_logout(): void
    {
        $user = User::factory()->create();
        $inactive = User::factory()->inactive()->create();
        $this->failAuditWrites();

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($inactive)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_audit_failures_are_logged_without_credentials(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);
        $this->failAuditWrites();
        Log::spy();

        $this->post(route('login'), ['email' => 'staff@example.test', 'password' => self::WRONG_PASSWORD]);

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $logged = $message.json_encode($context);

            return $context === ['event' => 'auth.login_failed', 'exception' => RuntimeException::class]
                && ! str_contains($logged, self::WRONG_PASSWORD)
                && ! str_contains($logged, 'staff@example.test')
                && ! str_contains($logged, 'SQLSTATE');
        });
    }
}
