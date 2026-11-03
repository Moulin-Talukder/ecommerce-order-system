<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Guest checkout creating a customer: audited with the storefront origin,
 * no actor, the guest's IP and user agent (D16), and fail-closed (D15).
 */
class CheckoutAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const IP = '203.0.113.77';

    private const USER_AGENT = 'GuestBrowser/5.0 (Android 14)';

    private Product $panjabi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panjabi = Product::factory()->for(Category::factory()->create())->withStock(10)->create(['selling_price' => '1500.00']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function checkout(array $overrides = [], int $quantity = 2): TestResponse
    {
        return $this->withSession(['storefront.cart' => [$this->panjabi->id => $quantity], 'storefront.checkout_tokens' => [self::TOKEN]])
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->withHeader('Authorization', 'Bearer guest-secret-token-123')
            ->withHeader('X-CSRF-TOKEN', 'csrf-header-value-123')
            ->withCookie('tracking_consent', 'cookie-value-123')
            ->post(route('storefront.checkout.store'), array_merge([
                'checkout_token' => self::TOKEN, 'name' => 'Rahima Akter', 'phone' => '01712345678', 'alternate_phone' => '',
                'email' => 'rahima.akter@example.test', 'address' => 'House 12, Road 5, Block C', 'area' => 'Mirpur',
                'city' => 'Dhaka', 'delivery_zone' => 'inside_dhaka', 'note' => 'Call before coming', 'website' => '',
            ], $overrides));
    }

    private function rawAuditRows(): string
    {
        return (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);
    }

    public function test_a_guest_checkout_records_the_new_customer_as_a_storefront_action(): void
    {
        $this->checkout()->assertRedirect();

        $order = Order::query()->sole();
        $log = AuditLog::query()->where('event', AuditEvent::CustomerCreated)->sole();
        $this->assertSame([AuditEvent::CustomerCreated, AuditOrigin::Storefront], [$log->event, $log->origin]);
        $this->assertSame([null, null, null, null], [$log->actor_id, $log->actor_name, $log->actor_email, $log->actor_role]);
        $this->assertSame(['customer', $order->customer_id, 'R••• A••• · ••••••••678'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame([self::IP, self::USER_AGENT], [$log->ip_address, $log->user_agent]);
        $this->assertEquals([
            'name' => 'R••• A•••', 'email' => 'r•••@example.test', 'phone' => '••••••••678', 'alternate_phone' => null,
            'address' => AuditRedactor::CHANGED, 'notes' => null, 'status' => 'active',
            'city' => AuditRedactor::CHANGED, 'area' => AuditRedactor::CHANGED, 'postal_code' => null,
        ], $log->new_values);
        $this->assertNull($log->context);
    }

    public function test_no_request_secrets_or_raw_personal_data_are_stored(): void
    {
        $this->checkout()->assertRedirect();
        $sessionId = $this->app['session.store']->getId();

        $raw = $this->rawAuditRows();
        foreach (['guest-secret-token', 'csrf-header-value', 'cookie-value-123', self::TOKEN, $sessionId, 'Rahima', 'Akter', '01712345678', 'rahima.akter', 'House 12', 'Mirpur', 'Dhaka', 'Call before coming'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "[{$secret}] reached the audit rows.");
        }
    }

    public function test_an_existing_customer_checking_out_records_no_customer_creation(): void
    {
        $existing = Customer::factory()->create(['phone' => '01712345678']);

        $this->checkout(['phone' => '+880 1712-345678'])->assertRedirect();

        $this->assertSame($existing->id, Order::query()->sole()->customer_id);
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::CustomerCreated)->count());
    }

    public function test_a_repeated_submission_creates_one_order_and_one_customer_record(): void
    {
        $this->checkout()->assertRedirect();
        $this->checkout()->assertRedirect();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditEvent::CustomerCreated)->count());
    }

    public function test_a_failed_checkout_rolls_back_the_new_customer_and_its_audit_row(): void
    {
        $this->checkout(quantity: 11)->assertRedirect(route('storefront.cart'));

        $this->assertSame([0, 0, 0], [Order::query()->count(), Customer::query()->count(), AuditLog::query()->count()]);
    }

    public function test_an_audit_failure_blocks_the_checkout_instead_of_placing_an_unaudited_order(): void
    {
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable'));
        $this->instance(AuditLogger::class, $logger);

        $this->checkout()->assertServerError();

        $this->assertSame([0, 0, 0], [Order::query()->count(), Customer::query()->count(), AuditLog::query()->count()]);
        $this->assertSame(10, $this->panjabi->refresh()->stock_quantity, 'No stock was taken.');
        $this->assertSame([$this->panjabi->id => 2], $this->app['session.store']->get('storefront.cart'), 'The cart is kept for another attempt.');
    }
}
