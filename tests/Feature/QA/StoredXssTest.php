<?php

namespace Tests\Feature\QA;

use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stored XSS: script and attribute-breaking payloads saved in every free-text
 * field shown back to admins or shoppers must render escaped everywhere.
 */
class StoredXssTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = '<script>alert("xss")</script>';

    private const ATTRIBUTE = '"><img src=x onerror=alert(1)>';

    private function payload(string $label): string
    {
        return $label.' '.self::SCRIPT.self::ATTRIBUTE;
    }

    private function assertEscaped(string $html, string $where): void
    {
        $this->assertStringNotContainsString(self::SCRIPT, $html, "{$where}: a raw <script> reached the page.");
        $this->assertStringNotContainsString('<img src=x onerror', $html, "{$where}: an injected attribute reached the page.");
    }

    public function test_payloads_in_catalog_customer_and_order_fields_render_escaped_everywhere(): void
    {
        $category = Category::factory()->create(['name' => $this->payload('Category'), 'slug' => 'xss-category']);
        $brand = Brand::factory()->create(['name' => $this->payload('Brand')]);
        $product = Product::factory()->for($category)->for($brand)->withStock(3)->create([
            'name' => $this->payload('Product'), 'slug' => 'xss-product', 'short_description' => $this->payload('Short'),
            'description' => $this->payload('Description'), 'meta_description' => $this->payload('Meta'),
        ]);
        $customer = Customer::factory()->create(['name' => $this->payload('Customer'), 'address' => $this->payload('Address'), 'notes' => $this->payload('Notes')]);
        $order = Order::factory()->for($customer)->create([
            'customer_name' => $this->payload('Recipient'), 'shipping_address' => $this->payload('Ship'), 'shipping_area' => 'Area',
            'customer_note' => $this->payload('CustomerNote'), 'admin_note' => $this->payload('AdminNote'), 'status' => OrderStatus::Delivered,
        ]);
        OrderItem::factory()->for($order)->for($product)->create(['product_name' => $this->payload('LineName')]);
        $admin = User::factory()->superAdmin()->create();

        $adminPages = [
            route('admin.dashboard'), route('admin.products.index'), route('admin.products.show', $product), route('admin.products.edit', $product),
            route('admin.brands.index'), route('admin.categories.index'), route('admin.customers.index'), route('admin.customers.show', $customer),
            route('admin.orders.index'), route('admin.orders.show', $order), route('admin.orders.edit', $order),
            route('admin.reports', ['report' => 'products', 'range' => 'last_30_days']), route('admin.reports', ['report' => 'customers', 'range' => 'last_30_days']),
        ];

        foreach ($adminPages as $url) {
            $this->assertEscaped((string) $this->actingAs($admin)->get($url)->assertOk()->getContent(), $url);
        }

        auth()->logout();

        foreach (['/', '/categories/xss-category', '/products/xss-product', '/?q='.urlencode(self::SCRIPT)] as $url) {
            $this->assertEscaped((string) $this->get($url)->assertOk()->getContent(), $url);
        }

        $this->get('/products/xss-product')->assertSee('Product '.self::SCRIPT, escape: true);
    }

    public function test_reflected_search_and_filter_input_is_escaped(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach (['admin.orders.index', 'admin.customers.index', 'admin.products.index', 'admin.brands.index', 'admin.categories.index'] as $name) {
            $this->assertEscaped((string) $this->actingAs($admin)->get(route($name, ['search' => self::SCRIPT.self::ATTRIBUTE]))->assertOk()->getContent(), $name);
        }

        $this->assertEscaped((string) $this->actingAs($admin)->get(route('admin.reports', ['report' => 'orders', 'range' => 'custom', 'from' => self::SCRIPT, 'to' => self::ATTRIBUTE]))->assertOk()->getContent(), 'reports');
    }
}
