<?php

namespace Tests\Feature\Audit;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Enums\AuditEvent;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StockAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->product = Product::factory()->withStock(10)->create(['sku' => 'STK-1']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function adjust(array $input): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('admin.products.stock.adjust', $this->product), $input);
    }

    public function test_a_restock_records_the_committed_quantities(): void
    {
        $this->adjust(['type' => 'restock', 'quantity_change' => 6])->assertSessionHasNoErrors();

        $movement = StockMovement::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame([AuditEvent::StockAdjusted, $this->manager->id, 'product', $this->product->id, 'STK-1'], [$log->event, $log->actor_id, $log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertEquals(['stock_quantity' => 10, 'stock_status' => 'in_stock'], $log->old_values);
        $this->assertEquals(['stock_quantity' => 16, 'stock_status' => 'in_stock'], $log->new_values);
        $this->assertEquals(['type' => 'restock', 'quantity_change' => 6, 'stock_movement_id' => $movement->id, 'note' => null], $log->context);
        $this->assertSame(16, $this->product->refresh()->stock_quantity);
    }

    public function test_a_decrease_to_zero_records_the_status_change_and_hides_the_reason_text(): void
    {
        $this->adjust(['type' => 'adjustment', 'quantity_change' => -10, 'note' => 'Damaged in the Mirpur warehouse'])->assertSessionHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertEquals(['stock_quantity' => 10, 'stock_status' => 'in_stock'], $log->old_values);
        $this->assertEquals(['stock_quantity' => 0, 'stock_status' => 'out_of_stock'], $log->new_values);
        $this->assertSame([-10, AuditRedactor::CHANGED, 'adjustment'], [$log->context['quantity_change'], $log->context['note'], $log->context['type']]);
        $this->assertStringNotContainsString('Mirpur', (string) json_encode(DB::table('audit_logs')->get()));
        $this->assertSame('Damaged in the Mirpur warehouse', StockMovement::query()->sole()->note, 'The full reason stays in the stock ledger.');
    }

    public function test_a_correction_upwards_is_recorded_once(): void
    {
        $this->adjust(['type' => 'adjustment', 'quantity_change' => 3, 'note' => 'Stock count correction'])->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame([10, 13], [AuditLog::query()->sole()->old_values['stock_quantity'], AuditLog::query()->sole()->new_values['stock_quantity']]);
    }

    public function test_refused_adjustments_record_nothing(): void
    {
        $untracked = Product::factory()->create(['manage_stock' => false]);

        $this->adjust(['type' => 'adjustment', 'quantity_change' => -11, 'note' => 'Too many']);
        $this->adjust(['type' => 'restock', 'quantity_change' => 0])->assertSessionHasErrors('quantity_change');
        $this->adjust(['type' => 'sale', 'quantity_change' => -1])->assertSessionHasErrors('type');
        $this->adjust(['type' => 'adjustment', 'quantity_change' => -1])->assertSessionHasErrors('note');
        $this->actingAs($this->manager)->post(route('admin.products.stock.adjust', $untracked), ['type' => 'restock', 'quantity_change' => 1]);
        $this->actingAs(User::factory()->create())->post(route('admin.products.stock.adjust', $this->product), ['type' => 'restock', 'quantity_change' => 1])->assertForbidden();

        $this->assertSame(10, $this->product->refresh()->stock_quantity);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_failed_audit_write_rolls_the_adjustment_back(): void
    {
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable'));
        $this->instance(AuditLogger::class, $logger);

        try {
            app(StockService::class)->adjustManually($this->product, 5, StockMovementType::Restock, $this->manager);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertSame(10, $this->product->refresh()->stock_quantity);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_order_driven_stock_changes_are_not_recorded_as_manual_adjustments(): void
    {
        $order = app(CreateOrder::class)->handle([
            'customer_name' => 'Rahima', 'customer_phone' => '01712345678', 'shipping_address' => 'Dhaka',
            'items' => [['product_id' => $this->product->id, 'quantity' => 4]], 'delivery_charge' => '60',
            'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Facebook->value,
        ], $this->manager);
        app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled, $this->manager, 'Customer changed mind');

        $this->assertSame(10, $this->product->refresh()->stock_quantity);
        $this->assertSame(['sale', 'cancellation'], StockMovement::query()->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::StockAdjusted)->count());
    }
}
