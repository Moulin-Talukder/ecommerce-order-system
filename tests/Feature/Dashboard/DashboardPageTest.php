<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 14:00:00', 'Asia/Dhaka'));
    }

    private function user(UserRole $role = UserRole::Manager): User
    {
        return User::factory()->role($role)->create();
    }

    private function order(string $createdAt, OrderStatus $status, string $total): Order
    {
        $order = Order::factory()->status($status)->create(['total' => $total]);
        $order->forceFill(['created_at' => CarbonImmutable::parse($createdAt, 'Asia/Dhaka')])->save();

        return $order;
    }

    // --- Access ------------------------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function roles(): array
    {
        return collect(UserRole::cases())->mapWithKeys(fn (UserRole $role) => [$role->value => [$role]])->all();
    }

    #[DataProvider('roles')]
    public function test_every_admin_role_sees_the_dashboard_including_sales(UserRole $role): void
    {
        $this->order('2026-10-15 10:00:00', OrderStatus::Pending, '1234.00');

        $this->actingAs($this->user($role))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Work queues')
            ->assertSee("Today's sales", false)
            ->assertSee('৳1,234.00');
    }

    public function test_inactive_users_cannot_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->inactive()->create())
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_money_figures_are_not_even_computed_when_the_policy_denies_them(): void
    {
        Gate::before(fn (User $user, string $ability) => $ability === 'viewSalesSummary' ? false : null);
        $order = $this->order('2026-10-15 10:00:00', OrderStatus::Delivered, '1234.00');
        Payment::factory()->for($order)->completed()->create(['amount' => '1234.00', 'paid_at' => now()]);

        DB::enableQueryLog();
        $response = $this->actingAs($this->user(UserRole::Staff))->get(route('admin.dashboard'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $response->assertSee("Today's orders", false)
            ->assertDontSee("Today's sales", false)
            ->assertDontSee('Collected')
            ->assertDontSee('Delivered but not fully paid')
            ->assertDontSee('SUM(total)', false);
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, '`payments`')), 'No payment aggregates are queried.');
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, 'amount_paid')), 'No outstanding-amount aggregates are queried.');
    }

    // --- Date ranges ------------------------------------------------------------------------------

    public function test_the_default_range_is_the_last_7_days(): void
    {
        $this->actingAs($this->user())
            ->get(route('admin.dashboard'))
            ->assertSee('Last 7 days (09 Oct 2026 – 15 Oct 2026)');
    }

    public function test_the_range_is_read_from_the_url(): void
    {
        $this->order('2026-10-14 12:00:00', OrderStatus::Delivered, '500.00');
        $this->order('2026-10-15 12:00:00', OrderStatus::Delivered, '70.00');

        $this->actingAs($this->user())
            ->get(route('admin.dashboard', ['range' => 'yesterday']))
            ->assertSee('Yesterday (14 Oct 2026)')
            ->assertSeeInOrder(['Sales · Yesterday', '৳500.00']);
    }

    public function test_a_valid_custom_range_from_the_url(): void
    {
        $this->order('2026-09-10 12:00:00', OrderStatus::Pending, '321.00');

        $this->actingAs($this->user())
            ->get(route('admin.dashboard', ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertSee('Custom range (01 Sep 2026 – 30 Sep 2026)')
            ->assertSeeInOrder(['Sales · Custom range', '৳321.00'])
            ->assertDontSee('Showing');
    }

    public function test_an_invalid_custom_range_shows_an_error_and_falls_back(): void
    {
        $this->actingAs($this->user())
            ->get(route('admin.dashboard', ['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-05']))
            ->assertOk()
            ->assertSee('Enter a valid start date (YYYY-MM-DD).')
            ->assertSee('Showing Last 7 days (09 Oct 2026 – 15 Oct 2026) instead.');
    }

    public function test_the_range_can_be_changed_interactively(): void
    {
        $this->order('2026-10-02 12:00:00', OrderStatus::Delivered, '900.00');

        Livewire::actingAs($this->user())
            ->test('pages::admin.dashboard')
            ->assertSet('range', 'last_7_days')
            ->set('range', 'this_month')
            ->assertSee('This month (01 Oct 2026 – 15 Oct 2026)')
            ->assertSee('৳900.00')
            ->set('from', '2026-10-01')
            ->set('to', '2026-09-01')
            ->call('applyCustomRange')
            ->assertSet('range', 'custom')
            ->assertHasErrors('to')
            ->set('to', '2026-10-05')
            ->call('applyCustomRange')
            ->assertHasNoErrors()
            ->assertSee('Custom range (01 Oct 2026 – 05 Oct 2026)');
    }

    // --- Content ------------------------------------------------------------------------------------

    public function test_headline_metrics_match_the_data(): void
    {
        $this->order('2026-10-15 09:00:00', OrderStatus::Pending, '1000.00');
        $this->order('2026-10-15 10:00:00', OrderStatus::Cancelled, '400.00');
        $this->order('2026-10-12 10:00:00', OrderStatus::Delivered, '3000.00');

        $this->actingAs($this->user())
            ->get(route('admin.dashboard'))
            ->assertSeeInOrder(["Today's orders", '2', '1 cancelled / returned', "Today's sales", '৳1,000.00'], false)
            ->assertSeeInOrder(['Orders · Last 7 days', '3', '1 cancelled / returned', 'Sales · Last 7 days', '৳4,000.00', 'Avg. order ৳2,000.00'], false);
    }

    public function test_queue_cards_show_counts_and_link_to_the_order_list(): void
    {
        Order::factory()->count(3)->create();
        Order::factory()->status(OrderStatus::Shipped)->create();

        Livewire::actingAs($user = $this->user())
            ->test('pages::admin.dashboard')
            ->assertViewHas('queues', fn ($queues) => $queues['needs_confirmation'] === 3 && $queues['in_transit'] === 1);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertSee(route('admin.orders.index', ['view' => 'needs_confirmation']))
            ->assertSee(route('admin.orders.index', ['view' => 'awaiting_payment']));
    }

    public function test_outstanding_cod_is_shown_and_links_to_the_queue(): void
    {
        $this->order('2026-10-12 10:00:00', OrderStatus::Delivered, '1060.00');

        $this->actingAs($this->user())
            ->get(route('admin.dashboard'))
            ->assertSeeInOrder(['Delivered but not fully paid', '৳1,060.00', '1 order']);
    }

    public function test_recent_orders_link_by_order_number(): void
    {
        $order = $this->order('2026-10-15 09:00:00', OrderStatus::Pending, '10.00');

        $this->actingAs($this->user())
            ->get(route('admin.dashboard'))
            ->assertSee($order->order_number)
            ->assertSee('/admin/orders/'.$order->order_number)
            ->assertDontSee('/admin/orders/'.$order->id.'"', false);
    }

    public function test_products_needing_restock_are_listed(): void
    {
        $product = Product::factory()->withStock(2)->create(['name' => 'Silk Saree', 'low_stock_threshold' => 5]);
        Product::factory()->withStock(40)->create(['name' => 'Plenty Panjabi', 'low_stock_threshold' => 5]);

        $this->actingAs($this->user(UserRole::Staff))
            ->get(route('admin.dashboard'))
            ->assertSee('Silk Saree')
            ->assertSee('2 / 5')
            ->assertSee('Low stock')
            ->assertSee(route('admin.products.show', $product))
            ->assertDontSee('Plenty Panjabi');
    }

    public function test_empty_states(): void
    {
        $this->actingAs($this->user())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('No orders yet.')
            ->assertSee('All products are sufficiently stocked.')
            ->assertSee('৳0.00');
    }

    // --- Performance ----------------------------------------------------------------------------------

    public function test_the_dashboard_runs_a_constant_number_of_queries(): void
    {
        $user = $this->user();
        $this->order('2026-10-15 09:00:00', OrderStatus::Pending, '10.00');
        Product::factory()->withStock(1)->create(['low_stock_threshold' => 5]);
        $few = $this->countQueries(fn () => $this->actingAs($user)->get(route('admin.dashboard'))->assertOk());

        foreach (range(1, 25) as $i) {
            $order = $this->order('2026-10-1'.($i % 6).' 09:00:00', OrderStatus::cases()[$i % 7], '10.00');
            Payment::factory()->for($order)->completed()->create(['amount' => '5.00', 'paid_at' => now()]);
        }
        Product::factory()->count(15)->withStock(1)->create(['low_stock_threshold' => 5]);
        $many = $this->countQueries(fn () => $this->actingAs($user)->get(route('admin.dashboard'))->assertOk());

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(20, $many, 'The dashboard should stay within a small, fixed query budget.');
    }

    private function countQueries(\Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
