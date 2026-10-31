<?php

namespace Tests\Feature\QA;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sweeps the live route table, so routes added later are covered too:
 *  - every admin route runs the web, auth and admin middleware;
 *  - guests are sent to login from every admin URL;
 *  - Staff (view-only) cannot change anything through any admin write route;
 *  - every non-API write route is in the web group (sessions + CSRF), and the
 *    only API route is the token-authenticated courier webhook.
 */
class RouteProtectionSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Tables a refused request must leave untouched. */
    private const TABLES = ['brands', 'categories', 'customers', 'orders', 'payments', 'products', 'product_images', 'stock_movements', 'order_status_histories', 'courier_shipments'];

    /**
     * @return list<RoutingRoute>
     */
    private function adminRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route) => str_starts_with((string) $route->getName(), 'admin.')));
    }

    /**
     * @return array<string, int|string>
     */
    private function bindings(): array
    {
        $product = Product::factory()->create();

        return [
            'brand' => Brand::factory()->create()->getRouteKey(),
            'category' => Category::factory()->create()->getRouteKey(),
            'customer' => Customer::factory()->create()->getRouteKey(),
            'order' => Order::factory()->create()->getRouteKey(),
            'product' => $product->getRouteKey(),
            'image' => ProductImage::factory()->for($product)->create()->getRouteKey(),
            'direction' => 'up',
        ];
    }

    /**
     * @param  array<string, int|string>  $bindings
     */
    private function url(RoutingRoute $route, array $bindings): string
    {
        return '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn (array $match) => (string) ($bindings[$match[1]] ?? 1), $route->uri()), '/');
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return array_combine(self::TABLES, array_map(fn (string $table) => DB::table($table)->count(), self::TABLES));
    }

    public function test_the_sweep_covers_the_expected_routes(): void
    {
        $this->assertGreaterThan(40, count($this->adminRoutes()), 'Admin routes were found.');
    }

    public function test_every_admin_route_requires_web_auth_and_admin_middleware(): void
    {
        foreach ($this->adminRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            foreach (['web', 'auth', 'admin'] as $required) {
                $this->assertContains($required, $middleware, "{$route->getName()} is missing [{$required}].");
            }
        }
    }

    public function test_guests_are_sent_to_login_from_every_admin_route(): void
    {
        $bindings = $this->bindings();

        foreach ($this->adminRoutes() as $route) {
            $method = $route->methods()[0];
            $response = $this->call($method, $this->url($route, $bindings));

            $this->assertTrue($response->isRedirect(route('login')), "{$method} {$route->uri()} must redirect guests to login, got {$response->status()}.");
        }

        $this->assertGuest();
    }

    public function test_staff_cannot_change_anything_through_any_admin_write_route(): void
    {
        $bindings = $this->bindings();
        $staff = User::factory()->role(UserRole::Staff)->create();
        $before = $this->tableCounts();
        $checked = 0;

        foreach ($this->adminRoutes() as $route) {
            $method = $route->methods()[0];

            if (in_array($method, ['GET', 'HEAD'], true)) {
                continue;
            }

            $response = $this->actingAs($staff)->call($method, $this->url($route, $bindings), ['is_active' => 0, 'status' => 'confirmed', 'amount' => '10.00', 'method' => 'cod', 'note' => 'x', 'name' => 'Changed']);

            $this->assertSame(403, $response->status(), "{$method} {$route->uri()} must be forbidden for Staff.");
            $checked++;
        }

        $this->assertGreaterThan(20, $checked);
        $this->assertSame($before, $this->tableCounts(), 'No refused request changed any data.');
        $this->assertNotSame('Changed', Brand::query()->value('name'));
        $this->assertNotSame('Changed', Product::query()->value('name'));
        $this->assertNotSame('Changed', Customer::query()->value('name'));
    }

    public function test_every_browser_write_route_uses_the_web_stack_and_the_api_only_exposes_the_authenticated_webhook(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
            $middleware = $route->gatherMiddleware();

            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertSame('webhooks.steadfast', $route->getName(), "Unexpected API route {$route->uri()}.");

                continue;
            }

            if ($writes && ! str_starts_with($route->uri(), 'livewire') && ! str_starts_with($route->uri(), 'storage/')) {
                $this->assertContains('web', $middleware, "{$route->uri()} writes data outside the web (CSRF) stack.");
            }
        }
    }
}
