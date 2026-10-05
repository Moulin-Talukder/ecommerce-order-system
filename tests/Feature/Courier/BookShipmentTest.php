<?php

namespace Tests\Feature\Courier;

use App\Actions\Courier\BookShipment;
use App\Actions\Orders\CreateOrder;
use App\Enums\CourierProvider;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\Courier\CourierException;
use App\Models\CourierShipment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderSearch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://steadfast.test/api/v1';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'courier.steadfast.base_url' => self::BASE,
            'courier.steadfast.api_key' => 'test-api-key-123',
            'courier.steadfast.secret_key' => 'test-secret-key-456',
            'courier.steadfast.lookup_retries' => 0,
        ]);
        Http::preventStrayRequests();
        $this->manager = User::factory()->manager()->create();
    }

    private function processingOrder(array $attributes = []): Order
    {
        return Order::factory()->status(OrderStatus::Processing)->create(array_merge([
            'order_number' => 'ORD-261005-ABCDEF', 'customer_name' => 'Rahima Akter', 'customer_phone' => '01712345678',
            'shipping_address' => 'House 12, Road 5', 'shipping_area' => 'Mirpur', 'shipping_city' => 'Dhaka',
            'shipping_postal_code' => '1216', 'total' => '1060.00', 'customer_note' => 'Call first',
        ], $attributes));
    }

    private function accepted(string $consignmentId = '1424107'): array
    {
        return ['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => [
            'consignment_id' => (int) $consignmentId, 'invoice' => 'ORD-261005-ABCDEF', 'tracking_code' => '15BAEB8A', 'status' => 'in_review', 'cod_amount' => 1060,
        ]];
    }

    private function book(Order $order): CourierShipment
    {
        return app(BookShipment::class)->handle($order, CourierProvider::Steadfast, $this->manager);
    }

    // --- Success & persistence --------------------------------------------------------------

    public function test_a_successful_booking_is_persisted(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);
        $order = $this->processingOrder();

        $shipment = $this->book($order)->refresh();

        $this->assertSame(CourierProvider::Steadfast, $shipment->courier);
        $this->assertSame('ORD-261005-ABCDEF', $shipment->invoice);
        $this->assertSame('1424107', $shipment->consignment_id);
        $this->assertSame('15BAEB8A', $shipment->tracking_code);
        $this->assertSame('in_review', $shipment->status);
        $this->assertSame('1060.00', $shipment->cod_amount);
        $this->assertNull($shipment->delivery_fee);
        $this->assertNull($shipment->last_error);
        $this->assertTrue($shipment->submittedBy->is($this->manager));
        $this->assertNotNull($shipment->submitted_at);
        $this->assertNotNull($shipment->last_synced_at);
        $this->assertTrue($shipment->isBooked());
        // MySQL JSON columns do not preserve key order, so compare as an unordered map.
        $this->assertEquals([
            'invoice' => 'ORD-261005-ABCDEF', 'recipient_name' => 'Rahima Akter', 'recipient_phone' => '01712345678',
            'recipient_address' => 'House 12, Road 5, Mirpur, Dhaka, 1216', 'cod_amount' => '1060.00', 'note' => 'Call first',
        ], $shipment->request_payload);
        $this->assertSame(1424107, $shipment->response_payload['consignment']['consignment_id']);
    }

    public function test_cod_is_the_amount_still_due(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);
        $order = $this->processingOrder();
        $order->forceFill(['amount_paid' => '60.00', 'payment_status' => PaymentStatus::PartiallyPaid])->save();

        $this->book($order);

        Http::assertSent(fn (Request $request) => $request['cod_amount'] === '1000.00');
        $this->assertSame('1000.00', CourierShipment::sole()->cod_amount);
    }

    public function test_stored_payloads_never_contain_credentials(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);

        $shipment = $this->book($this->processingOrder());

        $stored = json_encode(DB::table('courier_shipments')->find($shipment->id));
        $this->assertStringNotContainsString('test-api-key-123', $stored);
        $this->assertStringNotContainsString('test-secret-key-456', $stored);
    }

    public function test_a_booked_order_leaves_the_ready_for_courier_queue(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);
        $order = $this->processingOrder();
        $this->assertSame(1, app(OrderSearch::class)->viewCounts()['ready_for_courier']);

        $this->book($order);

        $this->assertSame(0, app(OrderSearch::class)->viewCounts()['ready_for_courier']);
    }

    // --- Rules checked before anything is sent -----------------------------------------------------

    public function test_missing_credentials_stop_the_booking_before_anything_is_stored_or_sent(): void
    {
        config(['courier.steadfast.api_key' => null]);
        Http::fake();

        try {
            $this->book($this->processingOrder());
            $this->fail('Expected CourierException.');
        } catch (CourierException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, CourierShipment::count());
    }

    public function test_only_processing_orders_can_be_booked(): void
    {
        Http::fake();

        foreach ([OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Cancelled] as $status) {
            $order = Order::factory()->status($status)->create();

            try {
                $this->book($order);
                $this->fail("{$status->value} should not be bookable.");
            } catch (CourierException $exception) {
                $this->assertStringContainsString('Only orders in Processing', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, CourierShipment::count());
    }

    public function test_an_order_without_a_delivery_address_is_refused(): void
    {
        Http::fake();
        $order = $this->processingOrder();
        DB::table('orders')->where('id', $order->id)->update(['shipping_address' => '']);

        $this->expectException(CourierException::class);

        $this->book($order->refresh());
    }

    // --- Duplicates --------------------------------------------------------------------------------

    public function test_an_already_booked_order_is_not_sent_again(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);
        $order = $this->processingOrder();
        $this->book($order);

        try {
            $this->book($order);
            $this->fail('Expected CourierException.');
        } catch (CourierException $exception) {
            $this->assertStringContainsString('already booked', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertSame(1, CourierShipment::count());
    }

    public function test_a_second_attempt_while_a_booking_is_pending_is_refused(): void
    {
        $order = $this->processingOrder();
        CourierShipment::factory()->for($order)->create([
            'invoice' => $order->order_number, 'consignment_id' => null, 'tracking_code' => null,
            'status' => CourierShipment::STATUS_BOOKING,
        ]);
        Http::fake();

        try {
            $this->book($order);
            $this->fail('Expected CourierException.');
        } catch (CourierException $exception) {
            $this->assertStringContainsString('Check booking', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_database_allows_only_one_shipment_row_per_courier_invoice(): void
    {
        $order = $this->processingOrder();
        CourierShipment::factory()->for($order)->create(['invoice' => $order->order_number]);

        $this->expectException(UniqueConstraintViolationException::class);

        CourierShipment::factory()->for($order)->create(['invoice' => $order->order_number]);
    }

    public function test_competing_attempts_send_only_one_booking(): void
    {
        $order = $this->processingOrder();
        $secondAttempt = null;

        // While the first booking is "in flight", a second request (e.g. a double-click) arrives.
        Http::fake([self::BASE.'/create_order' => function () use ($order, &$secondAttempt) {
            try {
                app(BookShipment::class)->handle(Order::find($order->id), CourierProvider::Steadfast, $this->manager);
                $secondAttempt = 'sent';
            } catch (CourierException $exception) {
                $secondAttempt = $exception->getMessage();
            }

            return Http::response($this->accepted());
        }]);

        $this->book($order);

        $this->assertStringContainsString('already in progress', $secondAttempt);
        $this->assertSame(1, CourierShipment::count());
        $this->assertSame('1424107', CourierShipment::sole()->consignment_id);
    }

    // --- Failures ----------------------------------------------------------------------------------------

    public function test_a_rejected_booking_is_recorded_and_can_be_retried(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::sequence()
            ->push(['status' => 400, 'errors' => ['recipient_address' => ['The recipient address field is required.']]])
            ->push($this->accepted())]);
        $order = $this->processingOrder();

        $failed = $this->book($order);
        $this->assertTrue($failed->isBookingFailed());
        $this->assertSame(CourierShipment::STATUS_BOOKING_FAILED, $failed->status);
        $this->assertStringContainsString('The recipient address field is required.', $failed->last_error);
        $this->assertNull($failed->consignment_id);

        $retried = $this->book($order);
        $this->assertSame($failed->id, $retried->id, 'The same shipment row is reused.');
        $this->assertSame('1424107', $retried->consignment_id);
        $this->assertNull($retried->last_error);
        $this->assertSame(1, CourierShipment::count());
    }

    public function test_an_authentication_failure_is_recorded_without_exposing_credentials(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response(['message' => 'Unauthorized'], 401)]);

        $shipment = $this->book($this->processingOrder());

        $this->assertTrue($shipment->isBookingFailed());
        $this->assertStringContainsString('rejected the API credentials', $shipment->last_error);
        $this->assertStringNotContainsString('test-api-key-123', $shipment->last_error);
    }

    public function test_an_uncertain_outcome_is_confirmed_by_invoice_lookup_instead_of_resending(): void
    {
        Http::fake([
            self::BASE.'/create_order' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            self::BASE.'/status_by_invoice/ORD-261005-ABCDEF' => Http::response(['status' => 200, 'delivery_status' => 'in_review']),
        ]);

        $shipment = $this->book($this->processingOrder());

        $this->assertTrue($shipment->isBooked(), 'Steadfast had accepted the parcel before the timeout.');
        $this->assertSame('in_review', $shipment->status);
        $this->assertNull($shipment->consignment_id, 'The lookup does not return the consignment id.');
        $this->assertNull($shipment->last_error);
        Http::assertSentCount(1);
    }

    public function test_an_uncertain_outcome_with_no_parcel_at_the_courier_becomes_retryable(): void
    {
        Http::fake([
            self::BASE.'/create_order' => Http::response('Bad Gateway', 502),
            self::BASE.'/status_by_invoice/*' => Http::response(['status' => 404, 'message' => 'Not found'], 404),
        ]);

        $shipment = $this->book($this->processingOrder());

        $this->assertTrue($shipment->isBookingFailed());
        $this->assertStringContainsString('safe to book again', $shipment->last_error);
    }

    public function test_an_unconfirmable_outcome_stays_pending_and_blocks_blind_retries(): void
    {
        Http::fake([
            self::BASE.'/create_order' => Http::response('Server Error', 500),
            self::BASE.'/status_by_invoice/*' => Http::response('Server Error', 500),
        ]);
        $order = $this->processingOrder();

        $shipment = $this->book($order);

        $this->assertTrue($shipment->isBookingPending());
        $this->assertStringContainsString('Check booking', $shipment->last_error);

        $this->expectException(CourierException::class);
        $this->book($order);
    }

    // --- Transaction boundary -------------------------------------------------------------------------

    public function test_the_courier_call_happens_outside_any_database_transaction(): void
    {
        $levelOutside = DB::transactionLevel();
        $levelDuringCall = null;
        Http::fake([self::BASE.'/create_order' => function () use (&$levelDuringCall) {
            $levelDuringCall = DB::transactionLevel();

            return Http::response($this->accepted());
        }]);

        $this->book($this->processingOrder());

        $this->assertSame($levelOutside, $levelDuringCall);
    }

    public function test_courier_failures_never_change_the_order_stock_or_payments(): void
    {
        $product = Product::factory()->withStock(10)->create(['selling_price' => '1000.00']);
        $order = app(CreateOrder::class)->handle([
            'customer_name' => 'Rahima', 'customer_phone' => '01712345678', 'shipping_address' => 'Mirpur',
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'delivery_charge' => '60',
            'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Phone->value,
        ], $this->manager);
        $order->forceFill(['status' => OrderStatus::Processing])->save();
        $before = DB::table('orders')->where('id', $order->id)->first();

        foreach ([
            Http::response('Server Error', 500),
            Http::response(['status' => 400, 'errors' => ['x']]),
        ] as $reply) {
            Http::fake([self::BASE.'/create_order' => $reply, self::BASE.'/status_by_invoice/*' => Http::response([], 404)]);
            $this->book($order->refresh());
        }

        $this->assertEquals($before, DB::table('orders')->where('id', $order->id)->first());
        $this->assertSame(8, $product->refresh()->stock_quantity);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, Payment::count());
        $this->assertModelExists($order);
    }
}
