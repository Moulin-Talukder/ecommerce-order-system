<?php

namespace Tests\Feature\Courier;

use App\Actions\Courier\SyncShipment;
use App\Enums\OrderStatus;
use App\Exceptions\Courier\CourierException;
use App\Models\CourierShipment;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://steadfast.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'courier.steadfast.base_url' => self::BASE,
            'courier.steadfast.api_key' => 'k',
            'courier.steadfast.secret_key' => 's',
            'courier.steadfast.lookup_retries' => 0,
        ]);
        Http::preventStrayRequests();
    }

    private function shipment(array $attributes = []): CourierShipment
    {
        $order = Order::factory()->status(OrderStatus::Shipped)->create(['order_number' => 'ORD-261005-SYNC01']);

        return CourierShipment::factory()->for($order)->create(array_merge([
            'invoice' => 'ORD-261005-SYNC01', 'consignment_id' => '777', 'tracking_code' => 'TRK777', 'status' => 'in_review', 'last_synced_at' => null,
        ], $attributes));
    }

    private function sync(CourierShipment $shipment): CourierShipment
    {
        return app(SyncShipment::class)->handle($shipment);
    }

    public function test_sync_by_consignment_id_updates_the_raw_status(): void
    {
        Http::fake([self::BASE.'/status_by_cid/777' => Http::response(['status' => 200, 'delivery_status' => 'delivered_approval_pending'])]);

        $shipment = $this->sync($this->shipment())->refresh();

        $this->assertSame('delivered_approval_pending', $shipment->status);
        $this->assertSame('Delivered (awaiting approval)', $shipment->statusLabel());
        $this->assertNotNull($shipment->last_synced_at);
        $this->assertNull($shipment->last_error);
    }

    public function test_sync_falls_back_to_tracking_code_then_invoice(): void
    {
        Http::fake([
            self::BASE.'/status_by_trackingcode/TRK777' => Http::response(['status' => 200, 'delivery_status' => 'hold']),
            self::BASE.'/status_by_invoice/ORD-261005-SYNC01' => Http::response(['status' => 200, 'delivery_status' => 'pending']),
        ]);

        $this->assertSame('hold', $this->sync($this->shipment(['consignment_id' => null]))->status);

        CourierShipment::query()->delete();
        Order::query()->delete();
        $this->assertSame('pending', $this->sync($this->shipment(['consignment_id' => null, 'tracking_code' => null]))->status);
    }

    public function test_unknown_statuses_are_stored_exactly_as_received(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['status' => 200, 'delivery_status' => 'returned_to_hub_v2'])]);

        $shipment = $this->sync($this->shipment())->refresh();

        $this->assertSame('returned_to_hub_v2', $shipment->status);
        $this->assertSame('Returned to hub v2 (unrecognised)', $shipment->statusLabel());
        $this->assertTrue($shipment->isBooked());
    }

    public function test_a_failed_sync_records_the_error_and_keeps_the_last_known_status(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('Server Error', 500)]);
        $shipment = $this->shipment();

        $this->sync($shipment);

        $shipment->refresh();
        $this->assertSame('in_review', $shipment->status);
        $this->assertNull($shipment->last_synced_at);
        $this->assertStringContainsString('Status sync failed', $shipment->last_error);
    }

    public function test_a_malformed_response_is_a_failure_not_a_status(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['status' => 200, 'unexpected' => true])]);

        $shipment = $this->sync($this->shipment())->refresh();

        $this->assertSame('in_review', $shipment->status);
        $this->assertNotNull($shipment->last_error);
    }

    public function test_syncing_never_changes_the_order(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);
        $shipment = $this->shipment();
        $before = DB::table('orders')->where('id', $shipment->order_id)->first();

        $this->sync($shipment);

        $this->assertEquals($before, DB::table('orders')->where('id', $shipment->order_id)->first());
        $this->assertSame(OrderStatus::Shipped, $shipment->order->refresh()->status);
    }

    public function test_a_pending_booking_is_reconciled_by_invoice(): void
    {
        Http::fake([self::BASE.'/status_by_invoice/ORD-261005-SYNC01' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);

        $shipment = $this->sync($this->shipment(['consignment_id' => null, 'tracking_code' => null, 'status' => CourierShipment::STATUS_BOOKING]));

        $this->assertTrue($shipment->isBooked());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/status_by_invoice/ORD-261005-SYNC01'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'create_order'));
    }

    public function test_failed_bookings_cannot_be_synced(): void
    {
        Http::fake();

        $this->expectException(CourierException::class);

        $this->sync($this->shipment(['consignment_id' => null, 'tracking_code' => null, 'status' => CourierShipment::STATUS_BOOKING_FAILED]));
    }
}
