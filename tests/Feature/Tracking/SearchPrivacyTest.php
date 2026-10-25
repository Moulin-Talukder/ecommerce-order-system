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
     * @return array{page_location: string, page_referrer: string|null}
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
        $this->assertSame(['page_location' => url('/').'/?page=1', 'page_referrer' => null], $this->pageContext($search));
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
}
