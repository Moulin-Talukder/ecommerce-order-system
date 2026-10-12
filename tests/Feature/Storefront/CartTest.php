<?php

namespace Tests\Feature\Storefront;

use App\Enums\StockStatus;
use App\Exceptions\Storefront\CartException;
use App\Models\Category;
use App\Models\Product;
use App\Services\Storefront\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->for($this->category)->withStock(10)->create(array_merge(['selling_price' => '500.00'], $attributes));
    }

    private function cart(): Cart
    {
        return app(Cart::class);
    }

    // --- Service ---------------------------------------------------------------------------------

    public function test_the_session_stores_only_product_ids_and_quantities(): void
    {
        $product = $this->product();

        $this->cart()->add($product, 2);

        $this->assertSame([$product->id => 2], session('storefront.cart'));
    }

    public function test_adding_accumulates_and_is_capped_at_stock_and_the_per_item_limit(): void
    {
        config(['storefront.max_quantity_per_item' => 10]);
        $scarce = $this->product(['stock_quantity' => 3]);
        $plenty = $this->product(['stock_quantity' => 500]);

        $this->assertSame(2, $this->cart()->add($scarce, 2));
        $this->assertSame(3, $this->cart()->add($scarce, 5));
        $this->assertSame(10, $this->cart()->add($plenty, 25));

        $this->expectException(CartException::class);
        $this->cart()->add($scarce, 1);
    }

    public function test_untracked_in_stock_products_use_the_per_item_limit(): void
    {
        config(['storefront.max_quantity_per_item' => 4]);
        $madeToOrder = $this->product(['manage_stock' => false, 'stock_quantity' => 0]);

        $this->assertSame(4, $this->cart()->add($madeToOrder, 9));
    }

    public function test_unavailable_products_cannot_be_added(): void
    {
        $outOfStock = Product::factory()->for($this->category)->outOfStock()->create();
        $draft = Product::factory()->for($this->category)->draft()->withStock(5)->create();
        $hidden = Product::factory()->for(Category::factory()->inactive())->withStock(5)->create();

        foreach ([$outOfStock, $draft, $hidden] as $product) {
            try {
                $this->cart()->add($product);
                $this->fail("{$product->name} should not be addable.");
            } catch (CartException $exception) {
                $this->assertStringContainsString('not available', $exception->getMessage());
            }
        }

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_the_number_of_cart_lines_is_limited(): void
    {
        config(['storefront.max_cart_lines' => 2]);
        $this->cart()->add($this->product());
        $this->cart()->add($this->product());

        $this->expectException(CartException::class);
        $this->cart()->add($this->product());
    }

    public function test_update_remove_and_clear(): void
    {
        $a = $this->product(['stock_quantity' => 5]);
        $b = $this->product();
        $this->cart()->add($a, 1);
        $this->cart()->add($b, 1);

        $this->cart()->update($a->id, 99);
        $this->assertSame(5, $this->cart()->contents()[$a->id], 'Capped at stock.');

        $this->cart()->update($a->id, 0);
        $this->assertArrayNotHasKey($a->id, $this->cart()->contents());

        $this->cart()->update(999999, 3);
        $this->assertSame([$b->id => 1], $this->cart()->contents(), 'Products not in the cart cannot be injected.');

        $this->cart()->remove($b->id);
        $this->assertTrue($this->cart()->isEmpty());

        $this->cart()->add($b, 2);
        $this->cart()->clear();
        $this->assertSame(0, $this->cart()->count());
    }

    public function test_the_summary_prices_lines_from_the_database(): void
    {
        $shirt = $this->product(['selling_price' => '500.00']);
        $saree = $this->product(['selling_price' => '3000.00', 'discount_price' => '2500.00']);
        $this->cart()->add($shirt, 3);
        $this->cart()->add($saree, 1);

        $summary = $this->cart()->summary('60.00');

        $this->assertSame(4, $summary->itemCount());
        $this->assertSame('4000.00', $summary->totals->subtotal);
        $this->assertSame('60.00', $summary->totals->deliveryCharge);
        $this->assertSame('4060.00', $summary->totals->total);
        $this->assertSame('500.00', $summary->lines[1]->pricing->discountAmount);
        $this->assertFalse($summary->changed());
    }

    public function test_price_changes_after_adding_are_reflected(): void
    {
        $product = $this->product(['selling_price' => '500.00']);
        $this->cart()->add($product, 2);

        $product->update(['selling_price' => '650.00']);

        $this->assertSame('1300.00', $this->cart()->summary()->totals->subtotal);
    }

    public function test_tampered_session_values_are_sanitised(): void
    {
        $product = $this->product();
        session(['storefront.cart' => [$product->id => '2', 999999 => 5, 'x' => -3, $product->id + 1000 => 0]]);

        $summary = $this->cart()->summary();

        $this->assertCount(1, $summary->lines);
        $this->assertSame(2, $summary->lines[0]->quantity);
    }

    public function test_stale_products_are_removed_and_quantities_reduced_with_notices(): void
    {
        $archived = $this->product(['name' => 'Discontinued Kurta']);
        $soldOut = $this->product(['name' => 'Popular Saree']);
        $scarce = $this->product(['name' => 'Last Panjabi', 'stock_quantity' => 5]);
        $keep = $this->product(['name' => 'Plain Tee']);
        foreach ([$archived, $soldOut, $keep] as $product) {
            $this->cart()->add($product, 1);
        }
        $this->cart()->add($scarce, 4);

        $archived->update(['status' => 'archived']);
        $soldOut->forceFill(['stock_quantity' => 0])->save();
        $scarce->forceFill(['stock_quantity' => 2])->save();

        $summary = $this->cart()->summary();

        $this->assertTrue($summary->changed());
        // Lines keep the order in which products were added.
        $this->assertSame(['Plain Tee', 'Last Panjabi'], array_map(fn ($line) => $line->product->name, $summary->lines));
        $this->assertSame(2, $summary->lines[1]->quantity);
        $this->assertContains('An item in your cart is no longer available and was removed.', $summary->notices);
        $this->assertContains('“Popular Saree” is out of stock and was removed from your cart.', $summary->notices);
        $this->assertContains('Only 2 of “Last Panjabi” can be ordered right now; your cart was updated.', $summary->notices);
        $this->assertEquals([$scarce->id => 2, $keep->id => 1], $this->cart()->contents(), 'The corrected cart is saved.');
        $this->assertFalse($this->cart()->summary()->changed());
    }

    public function test_an_empty_cart_summary(): void
    {
        $summary = $this->cart()->summary();

        $this->assertTrue($summary->isEmpty());
        $this->assertNull($summary->totals);
    }

    // --- Plain form (product cards) -------------------------------------------------------------

    public function test_the_card_form_adds_a_product_by_slug(): void
    {
        $product = $this->product(['name' => 'Cotton Panjabi', 'slug' => 'cotton-panjabi']);

        $this->from(route('storefront.home'))
            ->post(route('storefront.cart.add'), ['product' => 'cotton-panjabi'])
            ->assertRedirect(route('storefront.home'))
            ->assertSessionHas('success', '“Cotton Panjabi” was added to your cart.');

        $this->assertSame([$product->id => 1], session('storefront.cart'));
    }

    public function test_the_card_form_ignores_submitted_prices_and_rejects_unavailable_products(): void
    {
        $product = $this->product(['slug' => 'tee']);
        Product::factory()->for($this->category)->outOfStock()->create(['slug' => 'gone']);

        $this->post(route('storefront.cart.add'), ['product' => 'tee', 'quantity' => 2, 'price' => '1.00', 'unit_price' => '1.00']);
        $this->assertSame([$product->id => 2], session('storefront.cart'));
        $this->assertSame('1000.00', app(Cart::class)->summary()->totals->subtotal);

        $this->from(route('storefront.home'))->post(route('storefront.cart.add'), ['product' => 'gone'])->assertSessionHas('error');
        $this->from(route('storefront.home'))->post(route('storefront.cart.add'), ['product' => 'nope'])->assertSessionHas('error', 'Sorry, this product is not available.');
        $this->post(route('storefront.cart.add'), ['product' => 'tee', 'quantity' => 500])->assertSessionHasErrors('quantity');
    }

    // --- Livewire --------------------------------------------------------------------------------

    public function test_the_product_page_component_adds_the_chosen_quantity(): void
    {
        $product = $this->product(['stock_quantity' => 3]);

        Livewire::test('storefront.add-to-cart', ['productId' => $product->id, 'maxQuantity' => 3])
            ->call('increment')
            ->call('increment')
            ->call('increment')
            ->assertSet('quantity', 3)
            ->call('decrement')
            ->call('add')
            ->assertSet('message', 'Added to cart (2 in your cart).')
            ->assertDispatched('cart-updated');

        $this->assertSame([$product->id => 2], session('storefront.cart'));
    }

    public function test_the_product_id_cannot_be_changed_from_the_browser(): void
    {
        $product = $this->product();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('storefront.add-to-cart', ['productId' => $product->id, 'maxQuantity' => 5])->set('productId', 999);
    }

    public function test_a_tampered_quantity_is_still_capped_server_side(): void
    {
        $product = $this->product(['stock_quantity' => 4]);

        Livewire::test('storefront.add-to-cart', ['productId' => $product->id, 'maxQuantity' => 4])
            ->set('quantity', 1000)
            ->call('add');

        $this->assertSame([$product->id => 4], session('storefront.cart'));
    }

    public function test_the_component_reports_a_product_that_became_unavailable(): void
    {
        $product = $this->product();
        $component = Livewire::test('storefront.add-to-cart', ['productId' => $product->id, 'maxQuantity' => 5]);
        $product->forceFill(['stock_quantity' => 0, 'stock_status' => StockStatus::OutOfStock])->save();

        $component->call('add')->assertSet('error', "Sorry, “{$product->name}” is not available right now.");
        $this->assertTrue(app(Cart::class)->isEmpty());
    }

    public function test_the_cart_page_changes_quantities_and_removes_lines(): void
    {
        $a = $this->product(['name' => 'Cotton Panjabi', 'selling_price' => '1500.00', 'stock_quantity' => 3]);
        $b = $this->product(['name' => 'Plain Tee', 'selling_price' => '300.00']);
        $this->cart()->add($a, 1);
        $this->cart()->add($b, 2);

        $page = Livewire::test('pages::storefront.cart')
            ->assertSee('Cotton Panjabi')
            ->assertSee('৳2,100.00')
            ->call('increment', $a->id)
            ->call('increment', $a->id)
            ->call('increment', $a->id)
            ->assertDispatched('cart-updated');
        $this->assertSame(3, $this->cart()->contents()[$a->id], 'Capped at stock (3).');

        $page->call('decrement', $b->id)->call('decrement', $b->id);
        $this->assertArrayNotHasKey($b->id, $this->cart()->contents());

        $page->call('remove', $a->id)->assertSee('Your cart is empty.');
    }

    public function test_the_cart_page_can_be_cleared_and_shows_notices(): void
    {
        $product = $this->product(['name' => 'Fading Product']);
        $this->cart()->add($product, 1);
        $product->update(['status' => 'archived']);

        $this->get(route('storefront.cart'))
            ->assertOk()
            ->assertSee('An item in your cart is no longer available and was removed.')
            ->assertSee('Your cart is empty.');

        $this->cart()->add($this->product(), 1);
        Livewire::test('pages::storefront.cart')->call('clear')->assertSee('Your cart is empty.');
        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_the_header_badge_shows_the_item_count(): void
    {
        $this->cart()->add($this->product(), 3);

        Livewire::test('storefront.cart-count')->assertSee('3');
        $this->get(route('storefront.home'))->assertSeeLivewire('storefront.cart-count');
    }
}
