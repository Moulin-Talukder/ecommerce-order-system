<?php

namespace Tests\Feature\Courier;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\CourierShipment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderCourierUiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://steadfast.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'courier.steadfast.base_url' => self::BASE,
            'courier.steadfast.api_key' => 'test-api-key-123',
            'courier.steadfast.secret_key' => 'test-secret-key-456',
            'courier.steadfast.webhook_token' => 'test-webhook-token-789',
            'courier.steadfast.lookup_retries' => 0,
        ]);
        Http::preventStrayRequests();
    }

    private function user(UserRole $role = UserRole::Manager): User
    {
        return User::factory()->role($role)->create();
    }

    private function processing(): Order
    {
        return Order::factory()->status(OrderStatus::Processing)->create(['total' => '1060.00']);
    }

    private function fakeAccepted(Order $order): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response(['status' => 200, 'consignment' => [
            'consignment_id' => 5550001, 'invoice' => $order->order_number, 'tracking_code' => 'SF5550001', 'status' => 'in_review',
        ]])]);
    }

    public function test_a_manager_can_book_from_the_order_page(): void
    {
        $order = $this->processing();
        $this->fakeAccepted($order);
        $manager = $this->user();

        $this->actingAs($manager)->get(route('admin.orders.show', $order))
            ->assertSee('Book with Steadfast')
            ->assertSee('Not booked yet.');

        $this->actingAs($manager)->post(route('admin.orders.courier.book', $order))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('success', 'Booked with Steadfast. Consignment 5550001, tracking SF5550001.');

        $this->actingAs($manager)->get(route('admin.orders.show', $order))
            ->assertSee('5550001')
            ->assertSee('SF5550001')
            ->assertSee('In review')
            ->assertSee('Sync status')
            ->assertDontSee('Book with Steadfast');
    }

    public function test_a_booking_confirmed_by_reconciliation_explains_the_missing_consignment_id(): void
    {
        $order = $this->processing();
        Http::fake([
            self::BASE.'/create_order' => fn () => throw new ConnectionException('cURL error 28: timed out'),
            self::BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review']),
        ]);

        $this->actingAs($this->user())->post(route('admin.orders.courier.book', $order))
            ->assertSessionHas('success', "Steadfast confirmed the booking for invoice {$order->order_number} (status: In review). The consignment ID will be filled in by Steadfast's status webhook, or can be read in the Steadfast panel.");
    }

    public function test_a_failed_booking_is_reported_and_offers_a_retry(): void
    {
        $order = $this->processing();
        Http::fake([self::BASE.'/create_order' => Http::response(['status' => 400, 'errors' => ['The recipient address field is required.']])]);
        $manager = $this->user();

        $this->actingAs($manager)->post(route('admin.orders.courier.book', $order))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'The recipient address field is required.'));

        $this->actingAs($manager)->get(route('admin.orders.show', $order))
            ->assertSee('Booking failed')
            ->assertSee('Retry booking')
            ->assertDontSee('Sync status');
    }

    public function test_an_unconfirmed_booking_offers_check_booking_not_retry(): void
    {
        $order = $this->processing();
        CourierShipment::factory()->for($order)->create([
            'invoice' => $order->order_number, 'consignment_id' => null, 'tracking_code' => null,
            'status' => CourierShipment::STATUS_BOOKING, 'last_error' => 'Steadfast did not respond in time.',
        ]);

        $this->actingAs($this->user())->get(route('admin.orders.show', $order))
            ->assertSee('Booking not confirmed yet')
            ->assertSee('Check booking')
            ->assertDontSee('Retry booking')
            ->assertDontSee('Book with Steadfast');
    }

    public function test_sync_from_the_order_page(): void
    {
        $order = Order::factory()->status(OrderStatus::Shipped)->create();
        CourierShipment::factory()->for($order)->create(['invoice' => $order->order_number, 'consignment_id' => '42', 'status' => 'pending']);
        Http::fake([self::BASE.'/status_by_cid/42' => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);

        $this->actingAs($this->user())->post(route('admin.orders.courier.sync', $order))
            ->assertSessionHas('success', 'Courier status: Delivered.');

        $this->actingAs($this->user())->get(route('admin.orders.show', $order))
            ->assertSee('Steadfast reports this parcel as delivered');
    }

    public function test_syncing_an_unbooked_order_is_refused(): void
    {
        $order = $this->processing();

        $this->actingAs($this->user())->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.courier.sync', $order))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'has not been booked'));
    }

    public function test_non_processing_orders_show_guidance_instead_of_a_book_button(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->actingAs($this->user())->get(route('admin.orders.show', $order))
            ->assertDontSee('Book with Steadfast')
            ->assertSee('Move the order to Processing');

        Http::fake();
        $this->actingAs($this->user())->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.courier.book', $order))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Only orders in Processing'));
        Http::assertNothingSent();
    }

    public function test_missing_credentials_are_explained_and_booking_is_hidden(): void
    {
        config(['courier.steadfast.api_key' => null]);
        $order = $this->processing();

        $this->actingAs($this->user())->get(route('admin.orders.show', $order))
            ->assertSee('Steadfast is not configured on this server')
            ->assertDontSee('Book with Steadfast');
    }

    public function test_the_page_never_contains_courier_secrets(): void
    {
        $order = $this->processing();
        CourierShipment::factory()->for($order)->create(['invoice' => $order->order_number]);

        $this->actingAs($this->user(UserRole::SuperAdmin))->get(route('admin.orders.show', $order))
            ->assertDontSee('test-api-key-123')
            ->assertDontSee('test-secret-key-456')
            ->assertDontSee('test-webhook-token-789');
    }

    public function test_the_order_list_shows_courier_status_and_the_ready_queue(): void
    {
        $booked = $this->processing();
        CourierShipment::factory()->for($booked)->create(['invoice' => $booked->order_number, 'status' => 'hold']);
        $failed = $this->processing();
        CourierShipment::factory()->for($failed)->create(['invoice' => $failed->order_number, 'consignment_id' => null, 'status' => CourierShipment::STATUS_BOOKING_FAILED]);
        $fresh = $this->processing();

        $this->actingAs($this->user())->get(route('admin.orders.index'))->assertSee('Courier: On hold')->assertSee('Courier: Booking failed');
        $this->actingAs($this->user())->get(route('admin.orders.index', ['view' => 'ready_for_courier']))
            ->assertSee($fresh->order_number)
            ->assertSee($failed->order_number)
            ->assertDontSee($booked->order_number);
    }

    /**
     * @return array<string, array{UserRole, bool}>
     */
    public static function roles(): array
    {
        return [
            'super admin' => [UserRole::SuperAdmin, true],
            'admin' => [UserRole::Admin, true],
            'manager' => [UserRole::Manager, true],
            'staff' => [UserRole::Staff, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_courier_permissions_by_role(UserRole $role, bool $allowed): void
    {
        $order = $this->processing();
        $this->fakeAccepted($order);
        $user = $this->user($role);

        $page = $this->actingAs($user)->get(route('admin.orders.show', $order))->assertOk()->assertSee('Courier · Steadfast');
        $allowed ? $page->assertSee('Book with Steadfast') : $page->assertDontSee('Book with Steadfast');

        $response = $this->actingAs($user)->post(route('admin.orders.courier.book', $order));
        $allowed ? $response->assertRedirect() : $response->assertForbidden();

        $sync = $this->actingAs($user)->post(route('admin.orders.courier.sync', $order));
        if (! $allowed) {
            $sync->assertForbidden();
            Http::assertNothingSent();
            $this->assertSame(0, CourierShipment::count());
        }
    }

    public function test_guests_cannot_book_or_sync(): void
    {
        $order = $this->processing();
        Http::fake();

        $this->post(route('admin.orders.courier.book', $order))->assertRedirect(route('login'));
        $this->post(route('admin.orders.courier.sync', $order))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }
}
