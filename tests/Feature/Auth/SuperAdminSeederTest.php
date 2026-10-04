<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Secret-Pass';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.super_admin' => [
            'name' => 'Store Owner',
            'email' => 'Owner@Example.com',
            'password' => self::PASSWORD,
        ]]);
    }

    public function test_it_creates_an_active_super_admin_with_a_hashed_password(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $user = User::firstWhere('email', 'owner@example.com');

        $this->assertNotNull($user);
        $this->assertSame(UserRole::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame(self::PASSWORD, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_the_seeded_super_admin_can_login(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $this->post(route('login'), [
            'email' => 'owner@example.com',
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_rerunning_does_not_overwrite_an_existing_password(): void
    {
        $existing = User::factory()->inactive()->create(['email' => 'owner@example.com']);
        $originalHash = $existing->password;

        $this->seed(SuperAdminSeeder::class);

        $existing->refresh();
        $this->assertSame($originalHash, $existing->password);
        $this->assertSame(UserRole::SuperAdmin, $existing->role);
        $this->assertTrue($existing->is_active);
        $this->assertSame(1, User::count());
    }

    public function test_it_refuses_to_run_without_credentials(): void
    {
        config(['admin.super_admin.password' => null]);

        $this->expectException(RuntimeException::class);

        $this->seed(SuperAdminSeeder::class);
    }

    public function test_it_refuses_a_weak_password(): void
    {
        config(['admin.super_admin.password' => 'password']);

        $this->expectException(RuntimeException::class);

        try {
            $this->seed(SuperAdminSeeder::class);
        } finally {
            $this->assertSame(0, User::count());
        }
    }
}
