<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\UserRole;
use App\Exceptions\Users\UserManagementException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Users\UserService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserManagementAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Audit-Staff-Pass-42';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
    }

    public function test_creating_an_account_records_one_event_with_safe_fields_only(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.users.store'), [
            'name' => 'Rahim Staff', 'email' => 'Rahim@Example.TEST', 'role' => 'manager', 'is_active' => '1',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('admin.users.index'));

        $created = User::query()->where('email', 'rahim@example.test')->firstOrFail();
        $log = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::UserCreated, $log->event);
        $this->assertSame(AuditOrigin::Admin, $log->origin);
        $this->assertSame([$this->superAdmin->id, 'Owner', 'owner@example.test', 'super_admin'], [$log->actor_id, $log->actor_name, $log->actor_email, $log->actor_role]);
        $this->assertSame(['user', $created->id, 'rahim@example.test'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertEquals(['name' => 'Rahim Staff', 'email' => 'rahim@example.test', 'role' => 'manager', 'is_active' => true], $log->new_values);
        $this->assertNull($log->old_values);
        $this->assertNotNull($log->ip_address);
        $this->assertSecretsAbsent([self::PASSWORD, $created->password, $created->remember_token]);
    }

    public function test_a_profile_change_records_only_the_changed_fields(): void
    {
        $staff = User::factory()->create(['name' => 'Karim', 'email' => 'karim@example.test']);

        $this->actingAs($this->superAdmin)->put(route('admin.users.update', $staff), ['name' => 'Karim Uddin', 'email' => 'karim@example.test', 'role' => 'staff']);

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::UserUpdated, $log->event);
        $this->assertSame(['name' => 'Karim'], $log->old_values);
        $this->assertSame(['name' => 'Karim Uddin'], $log->new_values);
        $this->assertSame($staff->id, $log->subject_id);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $staff = User::factory()->create(['name' => 'Karim', 'email' => 'karim@example.test']);

        $this->actingAs($this->superAdmin)->put(route('admin.users.update', $staff), ['name' => 'Karim', 'email' => 'KARIM@example.test', 'role' => 'staff'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->superAdmin)->put(route('admin.users.update', $this->superAdmin), ['name' => 'Owner', 'email' => 'owner@example.test']);

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_role_only_change_records_one_role_changed_event(): void
    {
        $staff = User::factory()->create(['name' => 'Karim', 'email' => 'karim@example.test']);

        $this->actingAs($this->superAdmin)->put(route('admin.users.update', $staff), ['name' => 'Karim', 'email' => 'karim@example.test', 'role' => 'manager']);

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::UserRoleChanged, $log->event);
        $this->assertSame(['role' => 'staff'], $log->old_values);
        $this->assertSame(['role' => 'manager'], $log->new_values);
    }

    public function test_a_role_and_profile_change_records_one_event_of_each_kind(): void
    {
        $staff = User::factory()->create(['name' => 'Karim', 'email' => 'karim@example.test']);

        $this->actingAs($this->superAdmin)->put(route('admin.users.update', $staff), ['name' => 'Karim', 'email' => 'karim.uddin@example.test', 'role' => 'admin']);

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([AuditEvent::UserUpdated, AuditEvent::UserRoleChanged], $logs->pluck('event')->all());
        $this->assertSame([['email' => 'karim@example.test'], ['email' => 'karim.uddin@example.test']], [$logs[0]->old_values, $logs[0]->new_values]);
        $this->assertSame([['role' => 'staff'], ['role' => 'admin']], [$logs[1]->old_values, $logs[1]->new_values]);
    }

    public function test_activation_and_deactivation_record_only_real_transitions(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($this->superAdmin);

        $this->patch(route('admin.users.status', $staff), ['is_active' => '1']);
        $this->assertSame(0, AuditLog::query()->count(), 'Activating an active account is not a change.');

        $this->patch(route('admin.users.status', $staff), ['is_active' => '0']);
        $this->patch(route('admin.users.status', $staff), ['is_active' => '0']);
        $this->patch(route('admin.users.status', $staff), ['is_active' => '1']);
        $this->patch(route('admin.users.status', $staff), ['is_active' => '1']);

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([AuditEvent::UserDeactivated, AuditEvent::UserActivated], $logs->pluck('event')->all());
        $this->assertSame([['is_active' => true], ['is_active' => false], ['sessions_ended' => true]], [$logs[0]->old_values, $logs[0]->new_values, $logs[0]->context]);
        $this->assertSame([['is_active' => false], ['is_active' => true], null], [$logs[1]->old_values, $logs[1]->new_values, $logs[1]->context]);
    }

    public function test_a_password_reset_records_only_that_it_happened(): void
    {
        $staff = User::factory()->create();
        $oldHash = $staff->password;
        $oldToken = $staff->remember_token;

        $this->actingAs($this->superAdmin)->put(route('admin.users.password', $staff), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::UserPasswordReset, $log->event);
        $this->assertSame([$this->superAdmin->id, $staff->id, AuditOrigin::Admin], [$log->actor_id, $log->subject_id, $log->origin]);
        $this->assertSame([null, null, ['sessions_ended' => true]], [$log->old_values, $log->new_values, $log->context]);
        $this->assertSame('User password reset', $log->description);
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->password));
        $this->assertSecretsAbsent([self::PASSWORD, $oldHash, $staff->password, $oldToken, $staff->remember_token]);
    }

    public function test_existing_protections_still_apply_and_leave_no_audit_rows(): void
    {
        $otherOwner = User::factory()->superAdmin()->create();
        $staff = User::factory()->create();

        $this->actingAs($this->superAdmin)->patch(route('admin.users.status', $this->superAdmin), ['is_active' => '0'])->assertForbidden();
        $this->put(route('admin.users.update', $otherOwner), ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
        $this->put(route('admin.users.update', $this->superAdmin), ['name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'staff'])->assertSessionHasErrors('role');
        $this->actingAs($staff)->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'staff', 'is_active' => '1', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertForbidden();

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertTrue($this->superAdmin->refresh()->is_active);
    }

    public function test_refusing_to_remove_the_last_active_super_admin_leaves_no_audit_row(): void
    {
        $service = app(UserService::class);

        foreach ([fn () => $service->setActive($this->superAdmin, false, $this->superAdmin), fn () => $service->update($this->superAdmin, ['name' => 'Owner', 'email' => 'owner@example.test', 'role' => UserRole::Admin], $this->superAdmin)] as $attempt) {
            try {
                $attempt();
                $this->fail('The last active Super Admin must stay.');
            } catch (UserManagementException) {
            }
        }

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame([UserRole::SuperAdmin, true], [$this->superAdmin->refresh()->role, $this->superAdmin->is_active]);
    }

    /**
     * @return array<string, array{Closure(UserService, User, User): mixed}>
     */
    public static function auditedWrites(): array
    {
        return [
            'create' => [fn (UserService $service, User $actor) => $service->create(['name' => 'New', 'email' => 'new@example.test', 'password' => self::PASSWORD, 'role' => 'staff', 'is_active' => true], $actor)],
            'update' => [fn (UserService $service, User $actor, User $staff) => $service->update($staff, ['name' => 'Renamed', 'email' => $staff->email, 'role' => 'manager'], $actor)],
            'deactivate' => [fn (UserService $service, User $actor, User $staff) => $service->setActive($staff, false, $actor)],
            'reset password' => [fn (UserService $service, User $actor, User $staff) => $service->resetPassword($staff, self::PASSWORD, $actor)],
        ];
    }

    #[DataProvider('auditedWrites')]
    public function test_a_failed_audit_write_rolls_the_change_back(Closure $write): void
    {
        $staff = User::factory()->create(['name' => 'Karim']);
        $before = DB::table('users')->orderBy('id')->get();

        $this->mock(AuditLogger::class, fn (MockInterface $mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable')));

        try {
            $write(app(UserService::class), $this->superAdmin, $staff);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('users')->orderBy('id')->get(), 'The user change is rolled back.');
        $this->assertSame(0, AuditLog::query()->count());
    }

    /**
     * Scan every column of every stored audit row.
     *
     * @param  list<?string>  $secrets
     */
    private function assertSecretsAbsent(array $secrets): void
    {
        $raw = (string) json_encode(DB::table('audit_logs')->get());

        foreach (array_filter($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        $this->assertStringNotContainsString('password_confirmation', $raw);
        $this->assertStringNotContainsString('remember_token', $raw);
    }
}
