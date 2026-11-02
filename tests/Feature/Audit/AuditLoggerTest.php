<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = app(AuditLogger::class);
    }

    public function test_it_records_the_event_actor_snapshot_subject_and_origin(): void
    {
        $actor = User::factory()->create(['name' => 'Nadia Manager', 'email' => 'nadia@example.test', 'role' => UserRole::Manager]);
        $brand = Brand::factory()->create(['name' => 'Aarong Heritage']);

        $log = $this->logger->record(AuditEvent::BrandUpdated, $actor, $brand, ['name' => 'Aarong'], ['name' => 'Aarong Heritage']);

        $log = AuditLog::query()->findOrFail($log->id);
        $this->assertSame(AuditEvent::BrandUpdated, $log->event);
        $this->assertSame(AuditOrigin::Admin, $log->origin);
        $this->assertSame([$actor->id, 'Nadia Manager', 'nadia@example.test', 'manager'], [$log->actor_id, $log->actor_name, $log->actor_email, $log->actor_role]);
        $this->assertSame(['brand', $brand->id, 'Aarong Heritage'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame('Brand updated', $log->description);
        $this->assertSame(['name' => 'Aarong'], $log->old_values);
        $this->assertSame(['name' => 'Aarong Heritage'], $log->new_values);
        $this->assertNull($log->context);
        $this->assertNotNull($log->created_at);
        $this->assertTrue($log->actor->is($actor));
    }

    public function test_actor_and_subject_are_optional(): void
    {
        $log = $this->logger->record(AuditEvent::AuthLoginLocked, null, context: ['attempts' => 5], origin: AuditOrigin::System, description: 'Too many attempts');

        $this->assertSame([null, null, null, null], [$log->actor_id, $log->actor_name, $log->actor_email, $log->actor_role]);
        $this->assertSame([null, null, null], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame(['attempts' => 5], $log->context);
        $this->assertSame('Too many attempts', $log->description);
        $this->assertNull($log->old_values, 'Without a subject no attribute values are stored.');
    }

    public function test_subjects_are_stored_under_their_short_type_names(): void
    {
        $customer = Customer::factory()->create(['name' => 'Rahim Uddin', 'phone' => '01712345678']);
        $order = Order::factory()->create();
        $payment = Payment::factory()->for($order)->create();

        $this->assertSame(['customer', 'R••• U••• · ••••••••678'], $this->typeAndLabel($customer));
        $this->assertSame(['order', $order->order_number], $this->typeAndLabel($order));
        $this->assertSame(['payment', 'Payment #'.$payment->id], $this->typeAndLabel($payment));
        $this->assertSame(array_values(AuditLog::SUBJECT_TYPES), array_unique(array_values(AuditLog::SUBJECT_TYPES)));
    }

    public function test_models_that_are_not_audit_subjects_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger->record(AuditEvent::StockAdjusted, null, StockMovement::factory()->create());
    }

    public function test_every_origin_is_stored_and_unknown_origins_are_rejected(): void
    {
        foreach (AuditOrigin::cases() as $origin) {
            $this->assertSame($origin, $this->logger->record(AuditEvent::AuthLogout, null, origin: $origin->value)->refresh()->origin);
        }

        try {
            $this->logger->record(AuditEvent::AuthLogout, null, origin: 'api');
            $this->fail('An unknown origin must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unknown audit origin', $exception->getMessage());
        }

        $this->assertSame(count(AuditOrigin::cases()), AuditLog::query()->count());
    }

    public function test_old_new_and_context_values_are_redacted_before_storage(): void
    {
        $customer = Customer::factory()->create();

        $log = $this->logger->record(
            AuditEvent::CustomerUpdated,
            null,
            $customer,
            ['name' => 'Rahim Uddin', 'phone' => '01712345678', 'address' => 'House 12, Dhanmondi', 'status' => 'active', 'internal_flag' => 1],
            ['name' => 'Rahim U. Khan', 'email' => 'rahim@example.test', 'notes' => 'VIP, call after 6pm', 'password' => 'x'],
            ['order_id' => 5, 'customer_phone' => '01812345678', 'api_key' => 'k', 'note' => 'Called the customer'],
        )->refresh();

        // MySQL's JSON type does not keep key order, so compare ignoring it.
        $this->assertEquals(['name' => 'R••• U•••', 'phone' => '••••••••678', 'address' => AuditRedactor::CHANGED, 'status' => 'active'], $log->old_values);
        $this->assertEquals(['name' => 'R••• U••• K•••', 'email' => 'r•••@example.test', 'notes' => AuditRedactor::CHANGED], $log->new_values);
        $this->assertEquals(['order_id' => 5, 'customer_phone' => '••••••••678', 'note' => AuditRedactor::CHANGED], $log->context);
    }

    public function test_long_descriptions_are_capped(): void
    {
        $log = $this->logger->record(AuditEvent::AuthLogout, null, description: str_repeat('d', 800));

        $this->assertSame(500, mb_strlen($log->refresh()->description));
    }

    public function test_ip_address_and_user_agent_are_captured_from_an_http_request(): void
    {
        Route::post('/_audit-probe', fn (AuditLogger $logger) => (string) $logger->record(AuditEvent::AuthLogout, null)->id);

        $id = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.24'])
            ->withHeader('User-Agent', 'ExampleBrowser/2.0 '.str_repeat('x', 700))
            ->post('/_audit-probe')
            ->assertOk()
            ->getContent();

        $log = AuditLog::query()->findOrFail((int) $id);
        $this->assertSame('203.0.113.24', $log->ip_address);
        $this->assertStringStartsWith('ExampleBrowser/2.0', $log->user_agent);
        $this->assertSame(512, mb_strlen($log->user_agent));
    }

    public function test_command_line_and_requestless_execution_record_no_request_details(): void
    {
        $cli = $this->logger->record(AuditEvent::UserPasswordReset, null, origin: AuditOrigin::Cli);
        $this->assertSame([null, null], [$cli->ip_address, $cli->user_agent]);

        $this->app->forgetInstance('request');
        unset($this->app['request']);

        $system = $this->logger->record(AuditEvent::AuthLogout, null, origin: AuditOrigin::System);
        $this->assertSame([null, null], [$system->ip_address, $system->user_agent]);
    }

    public function test_history_survives_actor_changes_deactivation_and_deletion(): void
    {
        $actor = User::factory()->create(['name' => 'Old Name', 'role' => UserRole::Admin]);
        $log = $this->logger->record(AuditEvent::ProductStatusChanged, $actor, null, context: ['status' => ProductStatus::Archived]);

        $actor->forceFill(['name' => 'New Name', 'role' => UserRole::Staff, 'is_active' => false])->save();
        $log = AuditLog::query()->with('actor')->findOrFail($log->id);
        $this->assertTrue($log->actor->is($actor));
        $this->assertSame(['Old Name', 'admin'], [$log->actor_name, $log->actor_role]);
        $this->assertSame(['status' => 'archived'], $log->context);

        $actor->delete();
        $log = AuditLog::query()->findOrFail($log->id);
        $this->assertNull($log->actor_id, 'The actor link is nulled, never cascaded.');
        $this->assertSame('Old Name', $log->actor_name);
    }

    public function test_history_survives_deletion_of_the_subject(): void
    {
        $brand = Brand::factory()->create(['name' => 'Retired Brand']);
        $log = $this->logger->record(AuditEvent::BrandDeleted, null, $brand, old: ['name' => 'Retired Brand', 'slug' => $brand->slug]);
        $brandId = $brand->id;

        $brand->delete();

        $log = AuditLog::query()->findOrFail($log->id);
        $this->assertSame(['brand', $brandId, 'Retired Brand'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame('Retired Brand', $log->old_values['name']);
    }

    public function test_it_writes_inside_the_callers_transaction_and_commits_with_it(): void
    {
        $level = DB::transactionLevel();

        DB::transaction(function () use ($level): void {
            $this->logger->record(AuditEvent::AuthLogout, null);
            $this->assertSame($level + 1, DB::transactionLevel(), 'The logger opens no transaction of its own.');
            $this->assertSame(1, AuditLog::query()->count());
        });

        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_it_rolls_back_with_the_callers_transaction(): void
    {
        $brand = Brand::factory()->create(['name' => 'Before']);

        try {
            DB::transaction(function () use ($brand): void {
                $brand->update(['name' => 'After']);
                $this->logger->record(AuditEvent::BrandUpdated, null, $brand, ['name' => 'Before'], ['name' => 'After']);

                throw new RuntimeException('Business rule failed after the audit write.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame('Before', $brand->refresh()->name);
    }

    public function test_database_failures_reach_the_caller_and_roll_back_its_work(): void
    {
        $brand = Brand::factory()->create(['name' => 'Before']);
        $ghost = User::factory()->make(['id' => 999_999]);

        try {
            DB::transaction(function () use ($brand, $ghost): void {
                $brand->update(['name' => 'After']);
                $this->logger->record(AuditEvent::BrandUpdated, $ghost, $brand);
            });
            $this->fail('A failed audit write must not be swallowed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('audit_logs_actor_id_foreign', $exception->getMessage());
        }

        $this->assertSame('Before', $brand->refresh()->name, 'The business change is rolled back too.');
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_no_credential_or_secret_can_reach_any_stored_column(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'staff@example.test']);
        $hash = $user->getAttributes()['password'];
        $everything = $user->getAttributes() + [
            'password' => 'Plain-Text-Pass-42',
            'password_confirmation' => 'Plain-Text-Pass-42',
            'current_password' => 'Old-Plain-Pass-42',
            '_token' => 'csrf-token-value-123',
            'session_id' => 'session-id-value-123',
            'laravel_session' => 'session-cookie-value-123',
            'api_key' => 'api-key-value-123',
            'X-Webhook-Token' => 'webhook-token-value-123',
            'Authorization' => 'Bearer bearer-value-123',
            'gateway_response' => ['card' => '4111111111111111', 'secret' => 'gateway-secret-123'],
            'request_payload' => ['recipient_phone' => '01712345678'],
            'response_payload' => ['secret' => 'courier-secret-123'],
        ];
        $failed = new Failed('web', null, ['email' => 'staff@example.test', 'password' => 'Failed-Attempt-Pass-42']);

        $log = $this->logger->record(
            AuditEvent::UserUpdated,
            $user,
            $user,
            $everything,
            $everything,
            $everything + ['nested' => ['deeper' => $everything], 'event' => $failed, 'credentials' => $failed->credentials],
            description: 'Staff account updated',
        );

        $row = json_encode(DB::table('audit_logs')->where('id', $log->id)->first());

        foreach ([$hash, $user->remember_token, 'Plain-Text-Pass-42', 'Old-Plain-Pass-42', 'csrf-token-value', 'session-id-value', 'session-cookie-value', 'api-key-value', 'webhook-token-value', 'bearer-value', '4111111111111111', 'gateway-secret', 'courier-secret', '01712345678', 'Failed-Attempt-Pass-42'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $row, "[{$secret}] reached the audit row.");
        }

        $this->assertEquals(['name' => $user->name, 'email' => 'staff@example.test', 'role' => 'staff', 'is_active' => true], $log->refresh()->new_values);
    }

    /**
     * @return array{?string, ?string}
     */
    private function typeAndLabel(Customer|Order|Payment $subject): array
    {
        $log = $this->logger->record(AuditEvent::OrderUpdated, null, $subject);

        return [$log->subject_type, $log->subject_label];
    }
}
