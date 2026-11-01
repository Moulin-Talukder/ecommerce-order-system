<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Services\Tracking\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Light per-IP limits on the add-to-cart form and the consent form. The limits
 * are lowered here so the tests stay fast; the real defaults are generous.
 */
class StorefrontThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->for(Category::factory())->withStock(50)->create(['slug' => 'tee']);
    }

    public function test_the_defaults_are_generous_for_shared_mobile_ips(): void
    {
        $this->assertSame([120, 30], [config('storefront.cart_adds_per_minute_per_ip'), config('storefront.consent_updates_per_minute_per_ip')]);
    }

    public function test_adding_to_the_cart_is_limited_per_ip(): void
    {
        config(['storefront.cart_adds_per_minute_per_ip' => 3]);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('storefront.cart.add'), ['product' => 'tee', 'return_to' => '/'])
                ->assertRedirect(url('/'))
                ->assertSessionHas('success');
        }
        $this->assertSame(3, session('storefront.cart')[$this->product->id]);

        $this->from(route('storefront.home'))->post(route('storefront.cart.add'), ['product' => 'tee', 'return_to' => '/'])
            ->assertRedirect(route('storefront.home'))
            ->assertSessionHas('error', 'Too many requests. Please wait a minute and try again.');
        $this->assertSame(3, session('storefront.cart')[$this->product->id], 'A throttled request adds nothing.');

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->post(route('storefront.cart.add'), ['product' => 'tee', 'return_to' => '/'])
            ->assertSessionHas('success');
    }

    public function test_consent_updates_are_limited_per_ip(): void
    {
        config(['storefront.consent_updates_per_minute_per_ip' => 2]);

        foreach (range(1, 2) as $attempt) {
            $this->post(route('storefront.privacy.consent'), ['decision' => 'reject'])->assertCookie(Consent::COOKIE);
        }

        $this->from(route('storefront.privacy.settings'))->post(route('storefront.privacy.consent'), ['decision' => 'accept'])
            ->assertRedirect(route('storefront.privacy.settings'))
            ->assertSessionHas('error', 'Too many requests. Please wait a minute and try again.')
            ->assertCookieMissing(Consent::COOKIE);

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->post(route('storefront.privacy.consent'), ['decision' => 'accept'])
            ->assertCookie(Consent::COOKIE);
    }
}
