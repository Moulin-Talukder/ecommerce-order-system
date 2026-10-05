<?php

namespace Tests\Feature\Courier;

use App\Enums\OrderStatus;
use App\Models\CourierShipment;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SteadfastWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'webhook-token-abc123';

    private CourierShipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['courier.steadfast.webhook_token' => self::TOKEN]);
        Http::preventStrayRequests();
        $order = Order::factory()->status(OrderStatus::Shipped)->create(['order_number' => 'ORD-261005-HOOK01']);
        $this->shipment = CourierShipment::factory()->for($order)->create([
            'invoice' => 'ORD-261005-HOOK01', 'consignment_id' => '1424107', 'status' => 'pending', 'delivery_fee' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'notification_type' => 'delivery_status',
            'consignment_id' => 1424107,
            'invoice' => 'ORD-261005-HOOK01',
            'status' => 'delivered',
            'cod_amount' => 1060,
            'delivery_charge' => 60,
            'tracking_message' => 'Delivered to recipient',
            'updated_at' => '2026-10-05 12:05:31',
        ], $overrides);
    }

    private function send(array $payload, ?string $token = self::TOKEN)
    {
        return $this->withHeaders($token === null ? [] : ['Authorization' => 'Bearer '.$token])
            ->postJson(route('webhooks.steadfast'), $payload);
    }

    public function test_a_valid_webhook_updates_the_shipment(): void
    {
        $this->send($this->payload())
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'message' => 'Delivery status updated.']);

        $this->shipment->refresh();
        $this->assertSame('delivered', $this->shipment->status);
        $this->assertSame('60.00', $this->shipment->delivery_fee);
        $this->assertNotNull($this->shipment->last_synced_at);
        $this->assertSame('Delivered to recipient', $this->shipment->response_payload['tracking_message']);
    }

    public function test_the_webhook_never_changes_the_order(): void
    {
        $before = DB::table('orders')->where('id', $this->shipment->order_id)->first();

        $this->send($this->payload())->assertOk();

        $this->assertEquals($before, DB::table('orders')->where('id', $this->shipment->order_id)->first());
    }

    public function test_tracking_updates_without_a_status_keep_the_status(): void
    {
        $this->send($this->payload(['notification_type' => 'tracking_update', 'status' => null, 'delivery_charge' => null, 'tracking_message' => 'In transit to Mirpur hub']))->assertOk();

        $this->shipment->refresh();
        $this->assertSame('pending', $this->shipment->status);
        $this->assertSame('In transit to Mirpur hub', $this->shipment->response_payload['tracking_message']);
    }

    public function test_unknown_statuses_are_stored_raw(): void
    {
        $this->send($this->payload(['status' => 'lost_in_space']))->assertOk();

        $this->assertSame('lost_in_space', $this->shipment->refresh()->status);
    }

    public function test_the_shipment_can_be_found_by_invoice(): void
    {
        $this->shipment->forceFill(['consignment_id' => null])->save();

        $this->send($this->payload())->assertOk();

        $this->shipment->refresh();
        $this->assertSame('delivered', $this->shipment->status);
        $this->assertSame('1424107', $this->shipment->consignment_id, 'A missing consignment id is filled from the webhook.');
    }

    public function test_unknown_consignments_are_acknowledged_and_ignored(): void
    {
        $this->send($this->payload(['consignment_id' => 999, 'invoice' => 'ORD-UNKNOWN']))
            ->assertOk()
            ->assertJsonPath('message', 'Unknown consignment; ignored.');

        $this->assertSame('pending', $this->shipment->refresh()->status);
    }

    public function test_duplicate_deliveries_are_ignored(): void
    {
        $this->send($this->payload())->assertOk();
        $syncedAt = $this->shipment->refresh()->last_synced_at;
        $this->travel(5)->minutes();

        $this->send($this->payload())->assertOk()->assertJsonPath('message', 'Already up to date.');

        $this->assertEquals($syncedAt, $this->shipment->refresh()->last_synced_at);
    }

    public function test_out_of_order_deliveries_do_not_overwrite_newer_status(): void
    {
        $this->send($this->payload(['status' => 'delivered', 'updated_at' => '2026-10-05 12:00:00']))->assertOk();

        $this->send($this->payload(['status' => 'hold', 'updated_at' => '2026-10-05 09:00:00']))
            ->assertOk()
            ->assertJsonPath('message', 'Already up to date.');

        $this->assertSame('delivered', $this->shipment->refresh()->status);
    }

    public function test_a_newer_update_is_applied(): void
    {
        $this->send($this->payload(['status' => 'hold', 'updated_at' => '2026-10-05 09:00:00']))->assertOk();
        $this->send($this->payload(['status' => 'delivered', 'updated_at' => '2026-10-05 12:00:00']))->assertOk();

        $this->assertSame('delivered', $this->shipment->refresh()->status);
    }

    public function test_missing_or_wrong_tokens_are_rejected(): void
    {
        $this->send($this->payload(), null)->assertUnauthorized();
        $this->send($this->payload(), 'wrong-token')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.self::TOKEN)])->postJson(route('webhooks.steadfast'), $this->payload())->assertUnauthorized();
        $this->postJson(route('webhooks.steadfast').'?token='.self::TOKEN, $this->payload())->assertUnauthorized();

        $this->assertSame('pending', $this->shipment->refresh()->status);
    }

    public function test_the_webhook_is_disabled_until_a_token_is_configured(): void
    {
        config(['courier.steadfast.webhook_token' => null]);

        $this->send($this->payload(), '')->assertServiceUnavailable();
        $this->assertSame('pending', $this->shipment->refresh()->status);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no identifiers' => [['consignment_id' => null, 'invoice' => null], 'consignment_id'],
            'status with odd characters' => [['status' => 'delivered; DROP TABLE'], 'status'],
            'non-numeric delivery charge' => [['delivery_charge' => 'free'], 'delivery_charge'],
            'negative delivery charge' => [['delivery_charge' => -5], 'delivery_charge'],
            'oversized message' => [['tracking_message' => str_repeat('x', 1001)], 'tracking_message'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_are_rejected(array $overrides, string $field): void
    {
        $this->send($this->payload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame('pending', $this->shipment->refresh()->status);
    }

    public function test_only_validated_fields_are_stored(): void
    {
        $this->send($this->payload(['injected' => '<script>x</script>', 'api_key' => 'nope']))->assertOk();

        $this->assertArrayNotHasKey('injected', $this->shipment->refresh()->response_payload);
        $this->assertArrayNotHasKey('api_key', $this->shipment->response_payload);
    }

    public function test_the_webhook_is_stateless_and_needs_no_csrf_token(): void
    {
        $response = $this->send($this->payload())->assertOk();

        $this->assertEmpty($response->headers->getCookies());
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_the_webhook_is_rate_limited(): void
    {
        foreach (range(1, 120) as $i) {
            $this->send($this->payload(['updated_at' => '2026-10-05 12:'.str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT).':00']));
        }

        $this->send($this->payload())->assertTooManyRequests();
    }
}
