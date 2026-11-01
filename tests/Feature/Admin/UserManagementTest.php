<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Exceptions\Users\UserManagementException;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin user management: Super Admin only; Super Admin accounts are
 * protected; at least one active Super Admin always remains.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'New-Staff-Pass-42';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
    }

    /**
     * @return array<string, mixed>
     */
    private function newAccount(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Staff', 'email' => 'Rahim@Example.TEST', 'role' => 'staff', 'is_active' => '1',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    private function storeSession(User $user): void
    {
        DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);
    }

    // --- Access ---------------------------------------------------------------------------------

    public function test_the_super_admin_can_open_the_users_page_and_sees_themselves_marked(): void
    {
        $staff = User::factory()->create(['name' => 'Karim Staff']);

        $html = (string) $this->actingAs($this->superAdmin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Owner')->assertSee('You')->assertSee('Karim Staff')->assertSee('Super Admin')
            ->assertSee(route('admin.users.edit', $staff), false)
            ->getContent();

        $this->assertStringNotContainsString($staff->password, $html, 'Password hashes are never shown.');
        $this->assertStringNotContainsString('$2y$', $html);

        $this->actingAs($this->superAdmin)->get(route('admin.dashboard'))->assertSee(route('admin.users.index'), false);
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function otherRoles(): array
    {
        return ['admin' => [UserRole::Admin], 'manager' => [UserRole::Manager], 'staff' => [UserRole::Staff]];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_get_403_everywhere_and_change_nothing(UserRole $role): void
    {
        $actor = User::factory()->role($role)->create();
        $target = User::factory()->create(['name' => 'Target']);
        $this->actingAs($actor);

        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.users.create'))->assertForbidden();
        $this->get(route('admin.users.edit', $target))->assertForbidden();
        $this->post(route('admin.users.store'), $this->newAccount())->assertForbidden();
        $this->put(route('admin.users.update', $target), ['name' => 'Hacked', 'email' => 'x@example.test', 'role' => 'admin'])->assertForbidden();
        $this->patch(route('admin.users.status', $target), ['is_active' => '0'])->assertForbidden();
        $this->put(route('admin.users.password', $target), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertForbidden();
        $this->put(route('admin.users.update', $actor), ['name' => $actor->name, 'email' => $actor->email, 'role' => 'admin'])->assertForbidden();
        $this->patch(route('admin.users.status', $target), [])->assertForbidden()->assertSessionHasNoErrors();

        $this->assertSame(['Target', true], [$target->refresh()->name, $target->is_active]);
        $this->assertSame($role, $actor->refresh()->role, 'Nobody can change their own role.');
        $this->assertSame(3, User::count());
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.users.index'), false);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->post(route('admin.users.store'), $this->newAccount())->assertRedirect(route('login'));
        $this->assertSame(1, User::count());
    }

    // --- Listing ----------------------------------------------------------------------------------

    public function test_users_can_be_searched_filtered_and_paginated(): void
    {
        User::factory()->manager()->create(['name' => 'Sumi Manager', 'email' => 'sumi@example.test']);
        User::factory()->inactive()->create(['name' => 'Old Staff', 'email' => 'old@example.test']);
        User::factory()->count(30)->create();
        $this->actingAs($this->superAdmin);

        $this->get(route('admin.users.index', ['search' => 'sumi@']))->assertSee('Sumi Manager')->assertDontSee('Old Staff');
        $this->get(route('admin.users.index', ['role' => 'manager']))->assertSee('Sumi Manager')->assertDontSee('Old Staff');
        $this->get(route('admin.users.index', ['status' => 'inactive']))->assertSee('Old Staff')->assertDontSee('Sumi Manager');
        $this->get(route('admin.users.index', ['role' => 'emperor', 'status' => 'weird']))->assertOk();
        $this->get(route('admin.users.index', ['search' => '%']))->assertSee('No accounts match your filters.');

        $this->assertSame(33, $this->get(route('admin.users.index'))->viewData('users')->total());
        $this->assertCount(25, $this->get(route('admin.users.index'))->viewData('users')->items());
    }

    // --- Creating ---------------------------------------------------------------------------------

    public function test_the_super_admin_can_create_a_staff_account_with_a_hashed_password(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.users.create'))->assertOk()
            ->assertSee('Create account')
            ->assertSee('<option value="staff"', false)
            ->assertDontSee('<option value="super_admin"', false);

        $this->actingAs($this->superAdmin)->post(route('admin.users.store'), $this->newAccount())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $user = User::where('email', 'rahim@example.test')->sole();
        $this->assertSame([UserRole::Staff, true], [$user->role, $user->is_active]);
        $this->assertNotSame(self::PASSWORD, $user->getRawOriginal('password'), 'Never stored in plain text.');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertTrue(Hash::isHashed($user->password));

        auth()->logout();
        $this->post(route('login'), ['email' => 'rahim@example.test', 'password' => self::PASSWORD])->assertRedirect(route('admin.dashboard'));
    }

    public function test_new_accounts_can_start_inactive(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.users.store'), $this->newAccount(['is_active' => '0', 'role' => 'manager']));

        $this->assertSame([UserRole::Manager, false], [User::latest('id')->first()->role, User::latest('id')->first()->is_active]);
    }

    public function test_duplicate_emails_are_rejected_whatever_the_case(): void
    {
        User::factory()->create(['email' => 'rahim@example.test']);

        $this->actingAs($this->superAdmin)->post(route('admin.users.store'), $this->newAccount(['email' => 'RAHIM@example.test']))
            ->assertSessionHasErrors('email');
        $this->assertSame(2, User::count());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidAccounts(): array
    {
        return [
            'super admin role' => [['role' => 'super_admin'], 'role'],
            'unknown role' => [['role' => 'owner'], 'role'],
            'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'no number' => [['password' => 'NoNumbersHere', 'password_confirmation' => 'NoNumbersHere'], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'Different-Pass-42'], 'password'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'missing name' => [['name' => ''], 'name'],
        ];
    }

    #[DataProvider('invalidAccounts')]
    public function test_invalid_accounts_are_rejected(array $overrides, string $field): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.users.store'), $this->newAccount($overrides))
            ->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.password');

        $this->assertSame(1, User::count(), 'Nothing was created.');
    }

    public function test_extra_fields_cannot_be_mass_assigned(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.users.store'), $this->newAccount(['remember_token' => 'planted', 'email_verified_at' => '2000-01-01', 'id' => 999]));

        $user = User::where('email', 'rahim@example.test')->sole();
        $this->assertNotSame('planted', $user->remember_token);
        $this->assertNotSame(999, $user->id);
    }

    // --- Editing ----------------------------------------------------------------------------------

    public function test_the_super_admin_can_edit_a_staff_account(): void
    {
        $staff = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.test']);

        $this->actingAs($this->superAdmin)->get(route('admin.users.edit', $staff))->assertOk()->assertSee('Set a new password')->assertSee('Deactivate account');
        $this->put(route('admin.users.update', $staff), ['name' => 'New Name', 'email' => 'New@Example.test', 'role' => 'manager', 'is_active' => '0', 'password' => 'Ignored-Pass-42'])
            ->assertRedirect(route('admin.users.index'));

        $staff->refresh();
        $this->assertSame(['New Name', 'new@example.test', UserRole::Manager, true], [$staff->name, $staff->email, $staff->role, $staff->is_active]);
        $this->assertTrue(Hash::check('password', $staff->password), 'The edit form never changes the password.');

        $this->put(route('admin.users.update', $staff), ['name' => 'New Name', 'email' => 'new@example.test', 'role' => 'super_admin'])->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Manager, $staff->refresh()->role, 'No promotion to Super Admin.');
    }

    public function test_a_super_admin_can_edit_their_own_details_but_not_their_role(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.users.edit', $this->superAdmin))->assertOk()
            ->assertSee("A Super Admin's role and status cannot be changed here.", false)
            ->assertDontSee('Set a new password')->assertDontSee('Deactivate account');

        $this->put(route('admin.users.update', $this->superAdmin), ['name' => 'Owner Renamed', 'email' => 'owner@example.test'])->assertSessionHasNoErrors();
        $this->assertSame('Owner Renamed', $this->superAdmin->refresh()->name);

        $this->put(route('admin.users.update', $this->superAdmin), ['name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'staff'])->assertSessionHasErrors('role');
        $this->assertSame(UserRole::SuperAdmin, $this->superAdmin->refresh()->role);
    }

    public function test_a_tampered_own_role_change_shows_a_visible_error_and_changes_nothing(): void
    {
        $this->actingAs($this->superAdmin)->from(route('admin.users.edit', $this->superAdmin))
            ->followingRedirects()
            ->put(route('admin.users.update', $this->superAdmin), ['name' => 'Owner Renamed', 'email' => 'owner@example.test', 'role' => 'admin'])
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('The changes were not saved.')
            ->assertSee('A Super Admin cannot change their own role.')
            ->assertDontSee('name="role"', false);

        $this->superAdmin->refresh();
        $this->assertSame([UserRole::SuperAdmin, 'Owner'], [$this->superAdmin->role, $this->superAdmin->name]);
    }

    public function test_other_super_admin_accounts_are_protected(): void
    {
        $other = User::factory()->superAdmin()->create(['name' => 'Co Owner']);
        $this->actingAs($this->superAdmin);

        $this->get(route('admin.users.index'))->assertSee('Protected');
        $this->get(route('admin.users.edit', $other))->assertForbidden();
        $this->put(route('admin.users.update', $other), ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
        $this->patch(route('admin.users.status', $other), ['is_active' => '0'])->assertForbidden();
        $this->put(route('admin.users.password', $other), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertForbidden();

        $this->assertSame(['Co Owner', true], [$other->refresh()->name, $other->is_active]);
    }

    // --- Status -----------------------------------------------------------------------------------

    public function test_deactivating_ends_access_immediately_and_activating_restores_it(): void
    {
        config(['session.driver' => 'database']); // production setting: stored sessions are deleted
        $staff = User::factory()->create();
        $this->storeSession($staff);
        $token = $staff->remember_token;

        $this->actingAs($this->superAdmin)->patch(route('admin.users.status', $staff), ['is_active' => '0'])->assertSessionHas('success');

        $this->assertFalse($staff->refresh()->is_active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count(), 'Stored sessions are deleted.');
        $this->assertNotSame($token, $staff->remember_token, 'Remember-me logins are invalidated.');

        $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->superAdmin)->patch(route('admin.users.status', $staff), ['is_active' => '1']);
        $this->assertTrue($staff->refresh()->is_active);
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_nobody_can_deactivate_their_own_account(): void
    {
        $this->actingAs($this->superAdmin)->patch(route('admin.users.status', $this->superAdmin), ['is_active' => '0'])->assertForbidden();

        $this->assertTrue($this->superAdmin->refresh()->is_active);
    }

    public function test_the_last_active_super_admin_can_never_be_deactivated_or_demoted(): void
    {
        $service = app(UserService::class);

        foreach ([fn () => $service->setActive($this->superAdmin, false), fn () => $service->update($this->superAdmin, ['name' => 'Owner', 'email' => 'owner@example.test', 'role' => UserRole::Admin])] as $attempt) {
            try {
                $attempt();
                $this->fail('The last active Super Admin was removed.');
            } catch (UserManagementException $exception) {
                $this->assertStringContainsString('last active Super Admin', $exception->getMessage());
            }
        }

        $this->assertSame([UserRole::SuperAdmin, true], [$this->superAdmin->refresh()->role, $this->superAdmin->is_active]);

        $second = User::factory()->superAdmin()->create();
        $service->setActive($second, false);
        $this->assertFalse($second->refresh()->is_active, 'Allowed while another active Super Admin remains.');
        User::factory()->superAdmin()->inactive()->create();
        $this->expectException(UserManagementException::class);
        $service->setActive($this->superAdmin, false);
    }

    // --- Passwords --------------------------------------------------------------------------------

    public function test_the_super_admin_can_set_a_new_password_which_ends_existing_sessions(): void
    {
        config(['session.driver' => 'database']); // production setting: stored sessions are deleted
        $staff = User::factory()->create();
        $this->storeSession($staff);
        $token = $staff->remember_token;

        $this->actingAs($this->superAdmin)->put(route('admin.users.password', $staff), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('admin.users.edit', $staff))
            ->assertSessionHas('success');

        $staff->refresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->password));
        $this->assertFalse(Hash::check('password', $staff->password), 'The old password no longer works.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->assertNotSame($token, $staff->remember_token);

        auth()->logout();
        $this->post(route('login'), ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => $staff->email, 'password' => self::PASSWORD])->assertRedirect(route('admin.dashboard'));
    }

    public function test_password_resets_are_validated_and_never_echoed(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($this->superAdmin)->from(route('admin.users.edit', $staff))
            ->put(route('admin.users.password', $staff), ['password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');
        $this->assertTrue(Hash::check('password', $staff->refresh()->password));

        $this->put(route('admin.users.password', $staff), ['password' => self::PASSWORD, 'password_confirmation' => 'Mismatch-Pass-42'])->assertSessionHasErrors('password');
        $this->put(route('admin.users.password', $this->superAdmin), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertForbidden();

        $html = (string) $this->get(route('admin.users.edit', $staff))->getContent();
        $this->assertStringNotContainsString($staff->password, $html);
        $this->assertStringNotContainsString((string) $staff->remember_token, $html);
    }
}
