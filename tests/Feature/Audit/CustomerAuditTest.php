<?php

namespace Tests\Feature\Audit;

use App\Actions\Orders\CreateOrder;
use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\CustomerStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Exceptions\CannotDeleteException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use App\Services\Customers\CustomerService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahima Akter', 'phone' => '01712345678', 'alternate_phone' => '01898765432',
            'email' => 'rahima.akter@example.test', 'address' => 'House 12, Road 5, Dhanmondi',
            'city' => 'Dhaka', 'area' => 'Mirpur', 'postal_code' => '1216', 'notes' => 'Prefers evening calls',
        ], $overrides);
    }

    private function rawAuditRows(): string
    {
        return (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);
    }

    private function failAuditWrites(): void
    {
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable'));
        $this->instance(AuditLogger::class, $logger);
    }

    // --- Admin operations ------------------------------------------------------------

    public function test_creating_a_customer_records_one_masked_snapshot(): void
    {
        $this->actingAs($this->manager)->post(route('admin.customers.store'), $this->payload())->assertSessionHasNoErrors();

        $customer = Customer::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame([AuditEvent::CustomerCreated, AuditOrigin::Admin, $this->manager->id], [$log->event, $log->origin, $log->actor_id]);
        $this->assertSame(['customer', $customer->id, 'R••• A••• · ••••••••678'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertEquals([
            'name' => 'R••• A•••', 'email' => 'r•••@example.test', 'phone' => '••••••••678', 'alternate_phone' => '••••••••432',
            'address' => AuditRedactor::CHANGED, 'notes' => AuditRedactor::CHANGED, 'status' => 'active',
            'city' => AuditRedactor::CHANGED, 'area' => AuditRedactor::CHANGED, 'postal_code' => AuditRedactor::CHANGED,
        ], $log->new_values);
        $this->assertNull($log->old_values);
        $this->assertSame('Customer created', $log->description);
        $this->assertPersonalDataAbsent();
    }

    public function test_an_update_records_only_the_changed_fields_masked(): void
    {
        $customer = Customer::factory()->create($this->payload());

        $this->actingAs($this->manager)->put(route('admin.customers.update', $customer), $this->payload(['city' => 'Chattogram', 'phone' => '01755555999']))
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::CustomerUpdated, $log->event);
        $this->assertEquals(['phone' => '••••••••678', 'city' => AuditRedactor::CHANGED], $log->old_values);
        $this->assertEquals(['phone' => '••••••••999', 'city' => AuditRedactor::CHANGED], $log->new_values);
        $this->assertSame('01755555999', $customer->refresh()->phone);
        $this->assertStringNotContainsString('Chattogram', $this->rawAuditRows());
        $this->assertPersonalDataAbsent();
    }

    public function test_saving_unchanged_details_records_nothing(): void
    {
        $customer = Customer::factory()->create($this->payload());

        $this->actingAs($this->manager)->put(route('admin.customers.update', $customer), $this->payload(['phone' => '+880 1712-345678', 'email' => 'RAHIMA.AKTER@example.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_block_and_unblock_record_only_real_transitions(): void
    {
        $customer = Customer::factory()->create($this->payload());
        $this->actingAs($this->manager);

        foreach (['active', 'blocked', 'blocked', 'active', 'active'] as $status) {
            $this->patch(route('admin.customers.status', $customer), ['status' => $status]);
        }

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([AuditEvent::CustomerBlocked, AuditEvent::CustomerUnblocked], $logs->pluck('event')->all());
        $this->assertSame([['status' => 'active'], ['status' => 'blocked']], [$logs[0]->old_values, $logs[0]->new_values]);
        $this->assertSame([['status' => 'blocked'], ['status' => 'active']], [$logs[1]->old_values, $logs[1]->new_values]);
        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    public function test_deleting_keeps_a_masked_snapshot_and_the_subject_identity(): void
    {
        $customer = Customer::factory()->create($this->payload());

        $this->actingAs(User::factory()->admin()->create())->delete(route('admin.customers.destroy', $customer))->assertSessionHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertModelMissing($customer);
        $this->assertSame([AuditEvent::CustomerDeleted, 'customer', $customer->id, 'R••• A••• · ••••••••678'], [$log->event, $log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame(['R••• A•••', 'active', AuditRedactor::CHANGED], [$log->old_values['name'], $log->old_values['status'], $log->old_values['address']]);
        $this->assertNull($log->new_values);
        $this->assertPersonalDataAbsent();
    }

    public function test_refused_and_invalid_changes_record_nothing(): void
    {
        $existing = Customer::factory()->create($this->payload());
        Order::factory()->for($existing)->create();
        $staff = User::factory()->create();

        $this->actingAs($this->manager)->post(route('admin.customers.store'), $this->payload(['name' => '', 'phone' => '01811111111']))->assertSessionHasErrors('name');
        $this->post(route('admin.customers.store'), $this->payload(['phone' => '01712345678']))->assertSessionHasErrors('phone');
        $this->put(route('admin.customers.update', $existing), $this->payload(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->patch(route('admin.customers.status', $existing), ['status' => 'vip'])->assertSessionHasErrors('status');
        $this->delete(route('admin.customers.destroy', $existing))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->delete(route('admin.customers.destroy', $existing));
        $this->actingAs($staff)->post(route('admin.customers.store'), $this->payload(['phone' => '01822222222']))->assertForbidden();
        $this->patch(route('admin.customers.status', $existing), ['status' => 'blocked'])->assertForbidden();

        $this->assertModelExists($existing);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_duplicate_phone_race_records_nothing_and_saves_nothing(): void
    {
        Customer::creating(fn (Customer $customer) => DB::table('customers')->insert(['name' => 'Concurrent', 'phone' => $customer->phone, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        try {
            app(CustomerService::class)->create($this->payload(), $this->manager);
            $this->fail('The duplicate phone must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        $this->assertSame(0, AuditLog::query()->count());
        // The simulated concurrent row was inserted inside the same transaction, so it rolls back too.
        $this->assertSame(0, Customer::query()->count());
    }

    /**
     * @return array<string, array{Closure(CustomerService, Customer, User): mixed}>
     */
    public static function adminWrites(): array
    {
        return [
            'create' => [fn (CustomerService $service, Customer $customer, User $actor) => $service->create(['name' => 'New Person', 'phone' => '01933333333'], $actor)],
            'update' => [fn (CustomerService $service, Customer $customer, User $actor) => $service->update($customer, ['name' => 'Renamed', 'phone' => $customer->phone], $actor)],
            'block' => [fn (CustomerService $service, Customer $customer, User $actor) => $service->setStatus($customer, CustomerStatus::Blocked, $actor)],
            'delete' => [fn (CustomerService $service, Customer $customer, User $actor) => $service->delete($customer, $actor)],
        ];
    }

    #[DataProvider('adminWrites')]
    public function test_a_failed_audit_write_rolls_the_customer_change_back(Closure $write): void
    {
        $customer = Customer::factory()->create($this->payload());
        $before = DB::table('customers')->orderBy('id')->get();
        $this->failAuditWrites();

        try {
            $write(app(CustomerService::class), $customer, $this->manager);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('customers')->orderBy('id')->get());
        $this->assertSame(0, AuditLog::query()->count());
    }

    // --- Delete error handling --------------------------------------------------------

    public function test_a_genuine_foreign_key_refusal_still_becomes_cannot_delete(): void
    {
        $customer = Customer::factory()->create();
        Customer::deleting(fn (Customer $deleting) => Order::factory()->for($deleting)->create());

        try {
            app(CustomerService::class)->delete($customer, $this->manager);
            $this->fail('A customer with orders must not be deleted.');
        } catch (CannotDeleteException $exception) {
            $this->assertStringContainsString('has orders and cannot be deleted', $exception->getMessage());
            $this->assertSame(['23000', 1451], array_slice($exception->getPrevious()->errorInfo, 0, 2));
        }

        $this->assertModelExists($customer);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_failed_audit_insert_on_delete_is_not_reported_as_a_refusal(): void
    {
        $customer = Customer::factory()->create();

        try {
            app(CustomerService::class)->delete($customer, User::factory()->make(['id' => 999_999]));
            $this->fail('The audit failure must reach the caller.');
        } catch (CannotDeleteException) {
            $this->fail('An audit failure must not be reported as "has orders".');
        } catch (QueryException $exception) {
            $this->assertSame(['23000', 1452], array_slice($exception->errorInfo, 0, 2));
            $this->assertStringContainsString('audit_logs', $exception->getMessage());
        }

        $this->assertModelExists($customer);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_any_other_database_error_on_delete_propagates_unchanged(): void
    {
        $customer = Customer::factory()->create();
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andReturnUsing(fn () => DB::table('audit_logs_unavailable')->insert(['event' => 'x']));
        $this->instance(AuditLogger::class, $logger);

        try {
            app(CustomerService::class)->delete($customer, $this->manager);
            $this->fail('The database error must reach the caller.');
        } catch (CannotDeleteException) {
            $this->fail('An unrelated database error must not be reported as "has orders".');
        } catch (QueryException $exception) {
            $this->assertSame(['42S02', 1146], array_slice($exception->errorInfo, 0, 2));
        }

        $this->assertModelExists($customer);
    }

    // --- Created while an admin places an order -----------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function placeAdminOrder(Product $product, array $overrides = []): Order
    {
        return app(CreateOrder::class)->handle(array_merge([
            'customer_name' => 'Karim Uddin', 'customer_phone' => '01811112222', 'shipping_address' => 'Road 7, Uttara',
            'shipping_city' => 'Dhaka', 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'delivery_charge' => '60',
            'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Phone->value,
        ], $overrides), $this->manager);
    }

    public function test_an_admin_order_for_a_new_customer_records_one_customer_creation(): void
    {
        $order = $this->placeAdminOrder(Product::factory()->withStock(5)->create());

        $log = AuditLog::query()->sole();
        $this->assertSame([AuditEvent::CustomerCreated, AuditOrigin::Admin, $this->manager->id], [$log->event, $log->origin, $log->actor_id]);
        $this->assertSame(['customer', $order->customer_id, 'K••• U••• · ••••••••222'], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame([AuditRedactor::CHANGED, AuditRedactor::CHANGED], [$log->new_values['address'], $log->new_values['city']]);
        $this->assertStringNotContainsString('Uttara', $this->rawAuditRows());
        $this->assertSame(0, AuditLog::query()->where('subject_type', 'order')->count(), 'Order events belong to a later phase.');
    }

    public function test_an_admin_order_for_an_existing_customer_records_nothing(): void
    {
        $existing = Customer::factory()->create(['phone' => '01811112222']);

        $order = $this->placeAdminOrder(Product::factory()->withStock(5)->create(), ['customer_phone' => '+880 1811-112222']);

        $this->assertSame($existing->id, $order->customer_id);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_customer_created_concurrently_is_reused_without_a_creation_record(): void
    {
        Customer::creating(fn (Customer $customer) => DB::table('customers')->insert(['name' => 'Concurrent', 'phone' => $customer->phone, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));

        $order = $this->placeAdminOrder(Product::factory()->withStock(5)->create());

        $this->assertSame('Concurrent', $order->customer->name);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_failed_order_rolls_back_the_new_customer_and_its_audit_row(): void
    {
        try {
            $this->placeAdminOrder(Product::factory()->withStock(1)->create(), ['items' => [['product_id' => Product::query()->value('id'), 'quantity' => 5]]]);
            $this->fail('The order must be refused.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    /**
     * No full name, phone, email, address, area, city, postal code or note
     * text may appear anywhere in the stored audit rows.
     */
    private function assertPersonalDataAbsent(): void
    {
        $raw = $this->rawAuditRows();

        foreach (['Rahima', 'Akter', '01712345678', '01898765432', '1712345678', 'rahima.akter', 'House 12', 'Dhanmondi', 'Mirpur', 'Dhaka', '1216', 'evening calls'] as $personal) {
            $this->assertStringNotContainsString($personal, $raw, "[{$personal}] reached the audit rows.");
        }
    }
}
