<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_the_admin_panel(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_guests_receive_401_for_json_admin_requests(): void
    {
        $this->getJson(route('admin.dashboard'))->assertUnauthorized();
    }

    #[DataProvider('adminRoles')]
    public function test_every_admin_role_can_view_the_dashboard(UserRole $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee($user->name)
            ->assertSee($role->label());
    }

    public function test_inactive_users_can_not_login_even_with_the_correct_password(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your account has been deactivated. Please contact an administrator.']);
    }

    public function test_users_deactivated_during_a_session_are_logged_out_on_their_next_admin_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_role_middleware_allows_users_with_a_listed_role(): void
    {
        $this->registerRoleRestrictedRoute();

        $this->actingAs(User::factory()->superAdmin()->create())->get('/_test/admin-only')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/_test/admin-only')->assertOk();
    }

    public function test_role_middleware_forbids_users_without_a_listed_role(): void
    {
        $this->registerRoleRestrictedRoute();

        $this->actingAs(User::factory()->manager()->create())->get('/_test/admin-only')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/_test/admin-only')->assertForbidden();
    }

    public function test_role_middleware_still_rejects_inactive_users_with_an_allowed_role(): void
    {
        $this->registerRoleRestrictedRoute();

        $this->actingAs(User::factory()->superAdmin()->inactive()->create())
            ->get('/_test/admin-only')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_new_users_default_to_the_least_privileged_role(): void
    {
        $user = User::create([
            'name' => 'New Staff',
            'email' => 'staff@example.com',
            'password' => 'password',
        ])->refresh();

        $this->assertSame(UserRole::Staff, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_role_and_status_can_not_be_mass_assigned(): void
    {
        $user = User::factory()->create();

        $user->fill(['role' => UserRole::SuperAdmin->value, 'is_active' => false])->save();

        $this->assertSame(UserRole::Staff, $user->refresh()->role);
        $this->assertTrue($user->is_active);
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function adminRoles(): array
    {
        return collect(UserRole::cases())
            ->mapWithKeys(fn (UserRole $role) => [$role->value => [$role]])
            ->all();
    }

    private function registerRoleRestrictedRoute(): void
    {
        Route::middleware(['web', 'auth', 'admin', 'role:super_admin,admin'])
            ->get('/_test/admin-only', fn () => 'ok');
    }
}
