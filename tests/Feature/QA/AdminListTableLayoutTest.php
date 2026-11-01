<?php

namespace Tests\Feature\QA;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The visually hidden "Actions" header is absolutely positioned (sr-only), so
 * each list's horizontal scroll container must be positioned too. Otherwise
 * the hidden header escapes the scroll area and widens the whole page on
 * phones.
 */
class AdminListTableLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function listRoutes(): array
    {
        return [
            'users' => ['admin.users.index'],
            'categories' => ['admin.categories.index'],
            'brands' => ['admin.brands.index'],
            'customers' => ['admin.customers.index'],
            'products' => ['admin.products.index'],
        ];
    }

    #[DataProvider('listRoutes')]
    public function test_the_table_scroll_container_contains_the_hidden_actions_header(string $route): void
    {
        Category::factory()->create();
        Brand::factory()->create();
        Customer::factory()->create();
        Product::factory()->create();

        $html = (string) $this->actingAs(User::factory()->superAdmin()->create())->get(route($route))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="relative overflow-x-auto[^"]*">\s*<table/', $html);
        $this->assertStringNotContainsString('<div class="overflow-x-auto', $html);
    }
}
