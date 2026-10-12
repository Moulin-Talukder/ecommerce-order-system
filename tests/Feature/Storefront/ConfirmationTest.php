<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dddddddddddddddddddddddddddddddddddddddd';

    private function placeOrder(): Order
    {
        $category = Category::factory()->create();
        $panjabi = Product::factory()->for($category)->withStock(10)->create(['name' => 'Cotton Panjabi', 'selling_price' => '1500.00', 'purchase_price' => '901.23']);

        $this->withSession(['storefront.cart' => [$panjabi->id => 2], 'storefront.checkout_tokens' => [self::TOKEN]])
            ->post(route('storefront.checkout.store'), [
                'checkout_token' => self::TOKEN, 'name' => 'Rahima Akter', 'phone' => '01712345678',
                'alternate_phone' => '01812345678', 'email' => 'rahima@example.com',
                'address' => 'House 12, Road 5, Block C', 'area' => 'Mirpur', 'city' => 'Dhaka',
                'delivery_zone' => 'outside_dhaka', 'note' => 'Secret gate code 4455',
            ]);

        return Order::sole();
    }

    public function test_the_customer_sees_their_order_summary(): void
    {
        $order = $this->placeOrder();

        $this->get(route('storefront.thank-you', $order->order_number))
            ->assertOk()
            ->assertSee('Thank you! Your order has been placed.')
            ->assertSee($order->order_number)
            ->assertSee('Cotton Panjabi × 2')
            ->assertSeeInOrder(['Subtotal', '৳3,000.00', 'Delivery', '৳120.00', 'Total to pay', '৳3,120.00'])
            ->assertSee('Cash on Delivery')
            ->assertSee('Pending')
            ->assertSee('017•••••678')
            ->assertSee('Mirpur, Dhaka');
    }

    public function test_the_page_does_not_leak_sensitive_details(): void
    {
        $order = $this->placeOrder();
        $order->forceFill(['admin_note' => 'Internal: suspicious buyer'])->save();

        $this->get(route('storefront.thank-you', $order->order_number))
            ->assertDontSee('01712345678')
            ->assertDontSee('01812345678')
            ->assertDontSee('rahima@example.com')
            ->assertDontSee('House 12, Road 5')
            ->assertDontSee('Secret gate code')
            ->assertDontSee('Internal: suspicious buyer')
            ->assertDontSee('901.23')
            ->assertDontSee('/admin/orders/'.$order->order_number);
    }

    public function test_refreshing_the_page_does_not_create_another_order(): void
    {
        $order = $this->placeOrder();

        $this->get(route('storefront.thank-you', $order->order_number))->assertOk();
        $this->get(route('storefront.thank-you', $order->order_number))->assertOk();

        $this->assertSame(1, Order::count());
    }

    public function test_another_session_gets_a_404_without_the_order_being_looked_up(): void
    {
        $order = $this->placeOrder();
        $this->flushSession();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(route('storefront.thank-you', $order->order_number))->assertNotFound();
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, '`orders`')), 'Ownership is checked before any order query.');
    }

    public function test_existing_and_nonexistent_orders_are_indistinguishable_to_strangers(): void
    {
        $order = $this->placeOrder();
        $this->flushSession();

        $existing = $this->get(route('storefront.thank-you', $order->order_number));
        $missing = $this->get(route('storefront.thank-you', 'ORD-261012-ZZZZZZ'));

        $existing->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($existing->getContent(), $missing->getContent());
    }

    public function test_admin_entered_orders_are_not_visible_on_the_storefront(): void
    {
        $adminOrder = Order::factory()->create();

        $this->get(route('storefront.thank-you', $adminOrder->order_number))->assertNotFound();
    }
}
