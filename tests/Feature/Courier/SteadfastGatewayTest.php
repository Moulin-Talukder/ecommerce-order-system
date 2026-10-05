<?php

namespace Tests\Feature\Courier;

use App\Enums\SteadfastStatus;
use App\Services\Courier\BookingOutcome;
use App\Services\Courier\ConsignmentRequest;
use App\Services\Courier\LookupKey;
use App\Services\Courier\Steadfast\SteadfastGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SteadfastGatewayTest extends TestCase
{
    private const BASE = 'https://steadfast.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'courier.steadfast.base_url' => self::BASE,
            'courier.steadfast.api_key' => 'test-api-key-123',
            'courier.steadfast.secret_key' => 'test-secret-key-456',
            'courier.steadfast.lookup_retry_delay_ms' => 0,
        ]);
        Http::preventStrayRequests();
    }

    private function gateway(): SteadfastGateway
    {
        return new SteadfastGateway;
    }

    private function request(): ConsignmentRequest
    {
        return new ConsignmentRequest('ORD-261005-ABCDEF', 'Rahima Akter', '01712345678', 'House 12, Mirpur, Dhaka', '1060.00', 'Call first');
    }

    private function accepted(): array
    {
        return ['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => [
            'consignment_id' => 1424107, 'invoice' => 'ORD-261005-ABCDEF', 'tracking_code' => '15BAEB8A',
            'recipient_name' => 'Rahima Akter', 'recipient_phone' => '01712345678', 'recipient_address' => 'House 12, Mirpur, Dhaka',
            'cod_amount' => 1060, 'status' => 'in_review', 'note' => 'Call first',
            'created_at' => '2026-10-05T07:05:31.000000Z', 'updated_at' => '2026-10-05T07:05:31.000000Z',
        ]];
    }

    // --- Configuration ----------------------------------------------------------------------

    public function test_it_reports_missing_credentials(): void
    {
        $this->assertTrue($this->gateway()->isConfigured());

        config(['courier.steadfast.secret_key' => null]);
        $this->assertFalse($this->gateway()->isConfigured());

        config(['courier.steadfast.secret_key' => 'x', 'courier.steadfast.api_key' => '']);
        $this->assertFalse($this->gateway()->isConfigured());
    }

    public function test_the_default_base_url_is_the_verified_packzy_endpoint(): void
    {
        $this->assertSame('https://portal.packzy.com/api/v1', rtrim((string) env('STEADFAST_BASE_URL_UNSET', 'https://portal.packzy.com/api/v1'), '/'));
        $this->assertStringContainsString("env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1')", file_get_contents(config_path('courier.php')));
    }

    // --- Booking -------------------------------------------------------------------------------

    public function test_a_successful_booking_sends_the_verified_request_and_parses_the_consignment(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);

        $result = $this->gateway()->createConsignment($this->request());

        $this->assertSame(BookingOutcome::Accepted, $result->outcome);
        $this->assertSame(['1424107', '15BAEB8A', 'in_review'], [$result->consignmentId, $result->trackingCode, $result->status]);
        $this->assertSame(1060, $result->response['consignment']['cod_amount']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::BASE.'/create_order'
            && $request->hasHeader('Api-Key', 'test-api-key-123')
            && $request->hasHeader('Secret-Key', 'test-secret-key-456')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === [
                'invoice' => 'ORD-261005-ABCDEF',
                'recipient_name' => 'Rahima Akter',
                'recipient_phone' => '01712345678',
                'recipient_address' => 'House 12, Mirpur, Dhaka',
                'cod_amount' => '1060.00',
                'note' => 'Call first',
            ]);
    }

    public function test_an_empty_note_is_not_sent(): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response($this->accepted())]);

        $this->gateway()->createConsignment(new ConsignmentRequest('INV-1', 'A', '01712345678', 'Addr', '0.00', null));

        Http::assertSent(fn (Request $request) => ! array_key_exists('note', $request->data()));
    }

    /**
     * @return array<string, array{\Closure(): mixed, BookingOutcome, string}>
     */
    public static function bookingFailures(): array
    {
        return [
            'validation (body status 400)' => [fn () => Http::response(['status' => 400, 'errors' => ['recipient_phone' => ['The recipient phone must be 11 digits.']]]), BookingOutcome::Rejected, 'The recipient phone must be 11 digits.'],
            'validation (HTTP 422)' => [fn () => Http::response(['errors' => ['invoice' => ['The invoice has already been taken.']]], 422), BookingOutcome::Rejected, 'The invoice has already been taken.'],
            'authentication (HTTP 401)' => [fn () => Http::response(['message' => 'Unauthorized'], 401), BookingOutcome::Rejected, 'rejected the API credentials'],
            'rate limited (HTTP 429)' => [fn () => Http::response([], 429), BookingOutcome::Rejected, 'rate limit'],
            'server error (HTTP 500)' => [fn () => Http::response('Server Error', 500), BookingOutcome::Uncertain, 'may or may not have been created'],
            'gateway timeout (HTTP 504)' => [fn () => Http::response('', 504), BookingOutcome::Uncertain, 'may or may not have been created'],
            'malformed 200 (HTML)' => [fn () => Http::response('<html>maintenance</html>', 200), BookingOutcome::Uncertain, 'unexpected response'],
            '200 without consignment id' => [fn () => Http::response(['status' => 200, 'consignment' => []]), BookingOutcome::Uncertain, 'unexpected response'],
            'read timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds'), BookingOutcome::Uncertain, 'did not respond in time'],
            'connection refused' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect to portal.packzy.com port 443'), BookingOutcome::Rejected, 'nothing was sent'],
            'DNS failure' => [fn () => throw new ConnectionException('cURL error 6: Could not resolve host: portal.packzy.com'), BookingOutcome::Rejected, 'nothing was sent'],
        ];
    }

    #[DataProvider('bookingFailures')]
    public function test_booking_failures_are_classified_and_never_retried(\Closure $reply, BookingOutcome $outcome, string $message): void
    {
        $calls = 0;
        Http::fake([self::BASE.'/create_order' => function () use ($reply, &$calls) {
            $calls++;

            return $reply();
        }]);

        $result = $this->gateway()->createConsignment($this->request());

        $this->assertSame($outcome, $result->outcome);
        $this->assertStringContainsString($message, $result->message);
        $this->assertNull($result->consignmentId);
        $this->assertSame(1, $calls, 'Bookings are never retried automatically.');
    }

    // --- Lookup ------------------------------------------------------------------------------------

    /**
     * @return array<string, array{LookupKey, string, string}>
     */
    public static function lookupKeys(): array
    {
        return [
            'consignment id' => [LookupKey::ConsignmentId, '1424107', '/status_by_cid/1424107'],
            'invoice' => [LookupKey::Invoice, 'ORD-261005-ABCDEF', '/status_by_invoice/ORD-261005-ABCDEF'],
            'tracking code' => [LookupKey::TrackingCode, '15BAEB8A', '/status_by_trackingcode/15BAEB8A'],
        ];
    }

    #[DataProvider('lookupKeys')]
    public function test_each_supported_lookup_method(LookupKey $key, string $value, string $path): void
    {
        Http::fake([self::BASE.$path => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);

        $result = $this->gateway()->lookup($key, $value);

        $this->assertTrue($result->found());
        $this->assertSame('delivered', $result->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::BASE.$path && $request->hasHeader('Api-Key'));
    }

    public function test_lookup_values_are_url_encoded(): void
    {
        Http::fake([self::BASE.'/status_by_invoice/ORD%2F1%20X' => Http::response(['status' => 200, 'delivery_status' => 'pending'])]);

        $this->assertTrue($this->gateway()->lookup(LookupKey::Invoice, 'ORD/1 X')->found());
    }

    public function test_unknown_statuses_are_returned_raw(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['status' => 200, 'delivery_status' => 'teleported_to_mars'])]);

        $result = $this->gateway()->lookup(LookupKey::ConsignmentId, '1');

        $this->assertSame('teleported_to_mars', $result->status);
        $this->assertNull(SteadfastStatus::tryFrom($result->status));
        $this->assertSame('Teleported to mars (unrecognised)', SteadfastStatus::describe($result->status));
    }

    public function test_lookup_not_found_and_malformed_responses(): void
    {
        Http::fake([
            self::BASE.'/status_by_cid/404' => Http::response(['status' => 404, 'message' => 'Not found'], 404),
            self::BASE.'/status_by_cid/500' => Http::response('oops', 500),
            self::BASE.'/status_by_cid/bad' => Http::response(['status' => 200]),
            self::BASE.'/status_by_cid/html' => Http::response('<html></html>'),
            self::BASE.'/status_by_cid/auth' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->assertTrue($this->gateway()->lookup(LookupKey::ConsignmentId, '404')->notFound());
        $this->assertSame('failed', $this->gateway()->lookup(LookupKey::ConsignmentId, '500')->result);
        $this->assertSame('failed', $this->gateway()->lookup(LookupKey::ConsignmentId, 'bad')->result);
        $this->assertSame('failed', $this->gateway()->lookup(LookupKey::ConsignmentId, 'html')->result);
        $this->assertStringContainsString('credentials', $this->gateway()->lookup(LookupKey::ConsignmentId, 'auth')->message);
    }

    public function test_lookups_retry_transient_failures(): void
    {
        Http::fake([self::BASE.'/*' => Http::sequence()
            ->push('busy', 503)
            ->push(['status' => 200, 'delivery_status' => 'hold'])]);

        $this->assertSame('hold', $this->gateway()->lookup(LookupKey::ConsignmentId, '1')->status);
        Http::assertSentCount(2);
    }

    public function test_lookups_give_up_after_the_configured_retries(): void
    {
        config(['courier.steadfast.lookup_retries' => 2]);
        Http::fake([self::BASE.'/*' => Http::response('down', 503)]);

        $this->assertSame('failed', $this->gateway()->lookup(LookupKey::ConsignmentId, '1')->result);
        Http::assertSentCount(3);
    }

    public function test_lookup_connection_failures_are_reported_not_thrown(): void
    {
        config(['courier.steadfast.lookup_retries' => 0]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->assertSame('failed', $this->gateway()->lookup(LookupKey::ConsignmentId, '1')->result);
    }

    // --- Secrets -------------------------------------------------------------------------------------

    public function test_credentials_never_appear_in_logs_or_results(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = json_encode([$message->message, $message->context]);
        });
        Http::fake([self::BASE.'/create_order' => Http::response(['status' => 400, 'errors' => ['bad']])]);

        $result = $this->gateway()->createConsignment($this->request());

        $everything = implode(' ', $logged).json_encode((array) $result);
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('test-api-key-123', $everything);
        $this->assertStringNotContainsString('test-secret-key-456', $everything);
        $this->assertStringContainsString('ORD-261005-ABCDEF', implode(' ', $logged));
    }
}
