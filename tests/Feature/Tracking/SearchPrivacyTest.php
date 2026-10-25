<?php

namespace Tests\Feature\Tracking;

use App\Models\Category;
use App\Models\Product;
use App\Services\Tracking\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Storefront search text is free text that may contain a phone number,
 * email or name. It must never reach analytics through page_location,
 * page_referrer, page_title or any dataLayer event, while search itself
 * keeps working.
 */
class SearchPrivacyTest extends TestCase
{
    use InteractsWithDataLayer, RefreshDatabase;

    private const PERSONAL_SEARCH = 'Rahima 01712345678 rahima@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGtm();
        $category = Category::factory()->create(['name' => 'Panjabi', 'slug' => 'panjabi']);
        Product::factory()->for($category)->withStock(5)->create(['name' => 'Cotton Panjabi', 'slug' => 'cotton-panjabi']);
    }

    /**
     * @return array{page_location: string, page_referrer: string|null, page_has_search_text: bool}
     */
    private function pageContext(TestResponse $response): array
    {
        preg_match("/window\.dataLayer\.push\(JSON\.parse\('([^']*)'\)\);<\/script>/", (string) $response->getContent(), $match);

        return json_decode(json_decode('"'.$match[1].'"'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function urls(): array
    {
        return [
            'search text is removed' => ['https://shop.test/?q=01712345678', 'https://shop.test/'],
            'other parameters are kept' => ['https://shop.test/categories/panjabi?q=Rahima+Akter&page=2&utm_source=facebook', 'https://shop.test/categories/panjabi?page=2&utm_source=facebook'],
            'array and mixed-case names' => ['https://shop.test/?Q=x&q[]=y&page=3', 'https://shop.test/?page=3'],
            'email-like values are redacted' => ['https://shop.test/?utm_content=rahima%40example.com&gclid=Cj0KCQ1234567', 'https://shop.test/?utm_content=redacted&gclid=Cj0KCQ1234567'],
            'phone-like values are redacted' => ['https://shop.test/?ref=%2B880+1712-345678&page=12', 'https://shop.test/?ref=redacted&page=12'],
            'email-like bare names are dropped' => ['https://shop.test/?rahima@example.com', 'https://shop.test/'],
            'credentials and fragments are dropped' => ['https://user:secret@shop.test:8443/cart#q=hidden', 'https://shop.test:8443/cart'],
            'external referrers keep their origin' => ['https://www.google.com/', 'https://www.google.com/'],
            'not a web URL' => ['javascript:alert(1)', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_urls_are_sanitized_for_analytics(string $url, ?string $expected): void
    {
        $this->assertSame($expected, app(PageContext::class)->sanitize($url));
    }

    public function test_search_still_works_but_its_text_never_reaches_analytics(): void
    {
        $search = $this->get(route('storefront.home', ['q' => self::PERSONAL_SEARCH, 'page' => 1]))->assertOk();

        $html = (string) $search->getContent();
        $title = strstr(strstr($html, '<title>'), '</title>', true);
        $this->assertStringContainsString('Search results', $title);
        $this->assertStringNotContainsString('Rahima', $title, 'Analytics tags collect the page title.');
        $search->assertSee('Results for “'.self::PERSONAL_SEARCH.'”');
        $this->assertSame(['page_location' => url('/').'/?page=1', 'page_referrer' => null, 'page_has_search_text' => true], $this->pageContext($search));
        $this->assertStringNotContainsString('01712345678', json_encode([$this->pageContext($search), $this->dataLayerEvents($search)]));

        $found = $this->get(route('storefront.home', ['q' => 'panjabi']))->assertOk()->assertSee('Cotton Panjabi');
        $list = $this->dataLayerEvents($found)[0]['ecommerce'];
        $this->assertSame(['search_results', 'Search results'], [$list['item_list_id'], $list['item_list_name']], 'The list is reported, never the search text.');
    }

    public function test_category_search_still_filters_without_exposing_the_text(): void
    {
        $response = $this->get(route('storefront.category', ['panjabi', 'q' => 'rahima@example.com']))->assertOk();

        $response->assertSee('No products match your search.');
        $this->assertSame(route('storefront.category', 'panjabi'), $this->pageContext($response)['page_location']);
        $this->assertStringNotContainsString('rahima', strstr(strstr((string) $response->getContent(), '<title>'), '</title>', true));
    }

    public function test_the_page_after_a_search_does_not_leak_it_through_the_referrer(): void
    {
        $response = $this->get(route('storefront.product', 'cotton-panjabi'), ['Referer' => route('storefront.home', ['q' => self::PERSONAL_SEARCH])]);

        $this->assertSame(url('/').'/', $this->pageContext($response)['page_referrer']);
    }

    public function test_page_context_is_pushed_before_the_container_loads_and_only_when_enabled(): void
    {
        $html = (string) $this->get(route('storefront.home'))->getContent();
        $this->assertLessThan(strpos($html, 'gtm.js'), strpos($html, 'page_location'));

        config(['services.gtm.container_id' => '']);
        $this->get(route('storefront.home', ['q' => 'panjabi']))->assertOk()->assertSee('Cotton Panjabi')->assertDontSee('page_location');
    }

    // --- Search-text pages: referrer policy and the GTM blocking flag ---------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function searchTextUrls(): array
    {
        return [
            'home search' => ['/?q=01712345678', true],
            'category search' => ['/categories/panjabi?q=rahima%40example.com', true],
            'array and mixed-case names' => ['/?Q[]=Rahima', true],
            'other page with search text' => ['/products/cotton-panjabi?q=x', true],
            'blank search' => ['/?q=+', false],
            'empty search' => ['/?q=', false],
            'direct visit' => ['/', false],
            'pagination only' => ['/?page=2&utm_source=facebook', false],
            'category' => ['/categories/panjabi', false],
            'product' => ['/products/cotton-panjabi', false],
            'cart' => ['/cart', false],
        ];
    }

    #[DataProvider('searchTextUrls')]
    public function test_pages_with_search_text_send_only_their_origin_as_referrer(string $path, bool $hasSearchText): void
    {
        foreach (['GTM-TEST123', ''] as $containerId) {
            config(['services.gtm.container_id' => $containerId]);

            $response = $this->get($path)->assertOk();

            $this->assertSame($hasSearchText ? 'origin' : null, $response->headers->get('Referrer-Policy'), "{$path} with GTM [{$containerId}]");

            if ($containerId !== '') {
                $this->assertSame($hasSearchText, $this->pageContext($response)['page_has_search_text']);
            }
        }
    }

    public function test_the_blocking_flag_never_carries_the_search_text_and_ga4_values_stay_clean(): void
    {
        $search = $this->get('/?q='.urlencode(self::PERSONAL_SEARCH).'&page=2', ['Referer' => url('/').'/?q=rahima%40example.com'])->assertOk();
        $context = $this->pageContext($search);

        $this->assertSame(['page_location' => url('/').'/?page=2', 'page_referrer' => url('/').'/', 'page_has_search_text' => true], $context);
        $this->assertStringNotContainsStringIgnoringCase('rahima', json_encode($context));
        $this->assertStringContainsString('Search results', strstr(strstr((string) $search->getContent(), '<title>'), '</title>', true));

        $direct = $this->pageContext($this->get(route('storefront.product', 'cotton-panjabi'))->assertOk());
        $this->assertSame(['page_location' => route('storefront.product', 'cotton-panjabi'), 'page_referrer' => null, 'page_has_search_text' => false], $direct);
    }

    public function test_admin_and_login_pages_are_untouched(): void
    {
        $this->get(route('login', ['q' => 'x']))->assertOk()->assertHeaderMissing('Referrer-Policy');
    }

    // --- Adding to the cart from search results -------------------------------------------------

    public function test_adding_from_search_results_returns_to_the_same_search(): void
    {
        $results = $this->get('/?q=panjabi&page=1')->assertOk();
        $this->assertStringContainsString('name="return_to" value="/?q=panjabi&amp;page=1"', (string) $results->getContent());

        // The browser sends only the origin as Referer from a search page.
        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi', 'return_to' => '/?q=panjabi&page=1'], ['Referer' => url('/').'/'])
            ->assertRedirect(url('/?q=panjabi&page=1'))
            ->assertSessionHas('success');

        $this->post(route('storefront.cart.add'), ['product' => 'missing', 'return_to' => '/categories/panjabi?q=tee'], ['Referer' => url('/').'/'])
            ->assertRedirect(url('/categories/panjabi?q=tee'))
            ->assertSessionHas('error');
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unsafeReturnPaths(): array
    {
        return [
            'absolute URL' => ['https://evil.example/phish'],
            'protocol-relative' => ['//evil.example/phish'],
            'backslash trick' => ['/\\evil.example'],
            'not a path' => ['javascript:alert(1)'],
            'inner whitespace' => ['/cart evil'],
            'missing' => [null],
        ];
    }

    #[DataProvider('unsafeReturnPaths')]
    public function test_unsafe_return_paths_fall_back_to_the_previous_page(?string $returnTo): void
    {
        $this->post(route('storefront.cart.add'), array_filter(['product' => 'cotton-panjabi', 'return_to' => $returnTo]), ['Referer' => route('storefront.category', 'panjabi')])
            ->assertRedirect(route('storefront.category', 'panjabi'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function failedSubmissions(): array
    {
        return [
            'zero quantity' => [['quantity' => 0], 'quantity'],
            'quantity above the limit' => [['quantity' => 500], 'quantity'],
            'non-numeric quantity' => [['quantity' => 'abc'], 'quantity'],
            'missing product' => [['product' => null], 'product'],
            'oversized return path' => [['return_to' => '/?q='.str_repeat('a', 2050)], 'return_to'],
        ];
    }

    #[DataProvider('failedSubmissions')]
    public function test_invalid_submissions_from_search_results_return_to_the_search(array $overrides, string $invalidField): void
    {
        $data = array_filter(array_merge(['product' => 'cotton-panjabi', 'return_to' => '/categories/panjabi?q=panjabi&page=2'], $overrides), fn ($value) => $value !== null);
        $expected = $invalidField === 'return_to' ? route('storefront.category', 'panjabi') : url('/categories/panjabi?q=panjabi&page=2');

        $this->post(route('storefront.cart.add'), $data, ['Referer' => $invalidField === 'return_to' ? route('storefront.category', 'panjabi') : url('/').'/'])
            ->assertRedirect($expected)
            ->assertSessionHasErrors($invalidField)
            ->assertSessionMissing('_old_input.return_to');

        $this->assertSame([], session('storefront.cart', []), 'Nothing was added.');
    }

    public function test_a_refused_addition_from_search_results_returns_to_the_search(): void
    {
        $this->withSession(['storefront.cart' => [Product::where('slug', 'cotton-panjabi')->value('id') => 5]]);

        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi', 'return_to' => '/?q=panjabi'], ['Referer' => url('/').'/'])
            ->assertRedirect(url('/?q=panjabi'))
            ->assertSessionHas('error', 'You already have the maximum quantity of “Cotton Panjabi” in your cart.');
    }

    #[DataProvider('unsafeReturnPaths')]
    public function test_unsafe_return_paths_are_ignored_when_validation_fails(?string $returnTo): void
    {
        $this->post(route('storefront.cart.add'), array_filter(['product' => 'cotton-panjabi', 'quantity' => 500, 'return_to' => $returnTo]), ['Referer' => route('storefront.category', 'panjabi')])
            ->assertRedirect(route('storefront.category', 'panjabi'))
            ->assertSessionHasErrors('quantity');
    }

    public function test_a_malformed_return_path_is_ignored(): void
    {
        $this->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi', 'return_to' => ['/?q=x']], ['Referer' => route('storefront.category', 'panjabi')])
            ->assertRedirect(route('storefront.category', 'panjabi'))
            ->assertSessionHasErrors('return_to');
    }
}
