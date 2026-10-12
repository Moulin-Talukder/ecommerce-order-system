<?php

namespace Tests\Feature\Tracking;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Tracking\TagManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GtmInstallationTest extends TestCase
{
    use InteractsWithDataLayer, RefreshDatabase;

    // --- Configuration ------------------------------------------------------------------------

    public function test_automated_tests_never_inherit_a_container_id(): void
    {
        $this->assertSame('', (string) config('services.gtm.container_id'));
        $this->assertFalse(app(TagManager::class)->enabled());
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function containerIds(): array
    {
        return [
            'missing' => [null, null],
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'not a GTM id' => ['UA-12345-1', null],
            'GA4 measurement id' => ['G-ABC123XYZ', null],
            'script injection' => ["GTM-AB12');alert(1);//", null],
            'valid' => ['GTM-ABC1234', 'GTM-ABC1234'],
            'lowercase and padded' => [' gtm-abc1234 ', 'GTM-ABC1234'],
        ];
    }

    #[DataProvider('containerIds')]
    public function test_only_well_formed_container_ids_enable_gtm(?string $configured, ?string $expected): void
    {
        config(['services.gtm.container_id' => $configured]);

        $this->assertSame($expected, app(TagManager::class)->containerId());
        $this->assertSame($expected !== null, app(TagManager::class)->enabled());
    }

    // --- Installation -------------------------------------------------------------------------------

    public function test_disabled_gtm_renders_no_tracking_code(): void
    {
        $html = $this->get(route('storefront.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('dataLayer', $html);
    }

    public function test_enabled_gtm_installs_the_official_snippet_once_in_the_right_places(): void
    {
        $this->enableGtm('GTM-TEST123');

        $html = $this->get(route('storefront.home'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, "'dataLayer','GTM-TEST123'"), 'One container snippet.');
        $this->assertSame(1, substr_count($html, 'https://www.googletagmanager.com/gtm.js?id='));
        $this->assertSame(1, substr_count($html, 'https://www.googletagmanager.com/ns.html?id=GTM-TEST123'), 'One noscript iframe.');

        $head = strstr($html, '</head>', true);
        $this->assertStringContainsString("'dataLayer','GTM-TEST123'", $head, 'Snippet is in <head>.');
        $this->assertLessThan(strpos($head, 'gtm.js'), strpos($head, 'window.dataLayer = window.dataLayer || [];'), 'dataLayer is declared before the snippet.');
        $this->assertLessThan(strpos($head, '<title>'), strpos($head, 'gtm.js'), 'Snippet is as high in <head> as possible.');
        $this->assertMatchesRegularExpression('/<body[^>]*>\s*<!-- Google Tag Manager \(noscript\) -->\s*<noscript><iframe/', $html, 'noscript iframe right after <body>.');
        $this->assertLessThan(strpos($html, 'livewire.js'), strpos($html, 'Livewire.on(\'datalayer-push\''), 'Listener registered before Livewire starts.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function storefrontPages(): array
    {
        return [
            'home' => ['/'],
            'category' => ['/categories/panjabi'],
            'product' => ['/products/cotton-panjabi'],
            'cart' => ['/cart'],
        ];
    }

    #[DataProvider('storefrontPages')]
    public function test_every_storefront_page_loads_gtm_when_enabled(string $path): void
    {
        $this->enableGtm();
        $category = Category::factory()->create(['slug' => 'panjabi']);
        Product::factory()->for($category)->create(['slug' => 'cotton-panjabi']);

        $this->get($path)->assertOk()->assertSee('GTM-TEST123');
    }

    public function test_admin_and_login_pages_never_load_gtm(): void
    {
        $this->enableGtm();
        $order = Order::factory()->create();
        $product = Product::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->get(route('login'))->assertOk()->assertDontSee('googletagmanager.com', false)->assertDontSee('dataLayer', false);

        foreach ([route('admin.dashboard'), route('admin.orders.index'), route('admin.orders.show', $order), route('admin.products.show', $product), route('admin.customers.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('googletagmanager.com', false)->assertDontSee('dataLayer', false);
        }
    }

    public function test_a_csp_nonce_is_applied_when_one_is_configured(): void
    {
        $this->enableGtm();
        Vite::useCspNonce('test-nonce-123');

        $html = $this->get(route('storefront.home'))->getContent();

        // Each of the three tracking scripts (dataLayer init, container snippet, event script) carries the nonce.
        $this->assertMatchesRegularExpression('/<script\s+nonce="test-nonce-123"\s*>window\.dataLayer = window\.dataLayer \|\| \[\];/', $html);
        $this->assertMatchesRegularExpression('/<script\s+nonce="test-nonce-123"\s*>\(function\(w,d,s,l,i\)/', $html);
        $this->assertMatchesRegularExpression('/<script\s+nonce="test-nonce-123"\s*>\s*\(function \(\) \{\s*var push/', $html);
    }
}
