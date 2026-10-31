<?php

namespace Tests\Feature\QA;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SQL injection payloads in every search box and report filter: each request
 * succeeds, matches nothing it should not, leaks no credentials, does not
 * stall (time-based payloads) and leaves every table intact.
 */
class SqlInjectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'tautology' => ["' OR '1'='1"],
            'double-quoted tautology' => ['" OR 1=1 -- '],
            'stacked drop' => ['1; DROP TABLE orders; --'],
            'union select' => ["%' UNION SELECT password, email FROM users -- "],
            'time based' => ["1' AND SLEEP(3)-- "],
            'comment and wildcard' => ['%_%\'/*'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_injection_payloads_are_treated_as_plain_text(string $payload): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => 'Secret-Value-123']);
        Category::factory()->create(['name' => 'Panjabi', 'slug' => 'panjabi']);
        Brand::factory()->create(['name' => 'Aarong']);
        Product::factory()->withStock(5)->create(['name' => 'Cotton Panjabi']);
        Customer::factory()->create(['name' => 'Rahima Akter']);
        Order::factory()->create(['customer_name' => 'Karim Uddin']);
        $counts = $this->counts();

        $requests = [
            [true, route('admin.orders.index', ['search' => $payload])],
            [true, route('admin.customers.index', ['search' => $payload])],
            [true, route('admin.products.index', ['search' => $payload])],
            [true, route('admin.brands.index', ['search' => $payload])],
            [true, route('admin.categories.index', ['search' => $payload])],
            [true, route('admin.reports', ['report' => 'orders', 'status' => $payload, 'source' => $payload, 'method' => $payload])],
            [true, route('admin.reports', ['report' => 'sales', 'range' => 'custom', 'from' => $payload, 'to' => $payload])],
            [true, route('admin.dashboard', ['range' => $payload])],
            [false, '/?q='.urlencode($payload)],
            [false, '/categories/panjabi?q='.urlencode($payload)],
        ];

        foreach ($requests as [$asAdmin, $url]) {
            $started = microtime(true);
            $response = ($asAdmin ? $this->actingAs($admin) : $this)->get($url)->assertOk();
            $html = (string) $response->getContent();

            $this->assertLessThan(2.5, microtime(true) - $started, "{$url} must not execute time-based SQL.");
            $this->assertStringNotContainsString('$2y$', $html, "{$url} leaked a password hash.");
            $this->assertStringNotContainsString('SQLSTATE', $html, "{$url} raised a database error.");

            foreach (['Cotton Panjabi', 'Rahima Akter', 'Karim Uddin'] as $record) {
                if (str_contains($url, 'search=') || str_contains($url, 'q=')) {
                    $this->assertStringNotContainsString(">{$record}<", $html, "{$url} matched unrelated records.");
                }
            }
        }

        $this->assertSame($counts, $this->counts(), 'No table was modified or dropped.');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(['users', 'orders', 'customers', 'products', 'brands', 'categories'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
