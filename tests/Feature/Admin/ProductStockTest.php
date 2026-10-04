<?php

namespace Tests\Feature\Admin;

use App\Enums\StockMovementType;
use App\Enums\StockStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\StockException;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    // --- StockService ----------------------------------------------------------

    public function test_adjusting_stock_updates_quantity_and_records_a_movement(): void
    {
        $user = User::factory()->manager()->create();
        $product = Product::factory()->withStock(10)->create();

        $movement = $this->stock()->adjust($product, 5, StockMovementType::Restock, $user, 'Supplier delivery');

        $this->assertSame(15, $product->refresh()->stock_quantity);
        $this->assertSame(15, $product->stock_quantity, 'The passed model is refreshed too.');
        $this->assertSame(StockMovementType::Restock, $movement->type);
        $this->assertSame(5, $movement->quantity_change);
        $this->assertSame(15, $movement->quantity_after);
        $this->assertTrue($movement->user->is($user));
        $this->assertSame('Supplier delivery', $movement->note);
    }

    public function test_stock_cannot_become_negative(): void
    {
        $product = Product::factory()->withStock(3)->create();

        try {
            $this->stock()->adjust($product, -4, StockMovementType::Adjustment);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $exception) {
            $this->assertStringContainsString('3 available, 4 requested', $exception->getMessage());
        }

        $this->assertSame(3, $product->refresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stock_can_be_reduced_to_exactly_zero_and_becomes_out_of_stock(): void
    {
        $product = Product::factory()->withStock(2)->create();

        $this->stock()->adjust($product, -2, StockMovementType::Adjustment, note: 'Damaged');

        $product->refresh();
        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(StockStatus::OutOfStock, $product->stock_status);

        $this->stock()->adjust($product, 1, StockMovementType::Restock);
        $this->assertSame(StockStatus::InStock, $product->refresh()->stock_status);
    }

    public function test_zero_changes_are_rejected(): void
    {
        $this->expectException(StockException::class);

        $this->stock()->adjust(Product::factory()->create(), 0, StockMovementType::Adjustment);
    }

    public function test_untracked_products_cannot_have_quantities_adjusted(): void
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $this->expectException(StockException::class);

        $this->stock()->adjust($product, 5, StockMovementType::Restock);
    }

    public function test_availability_of_untracked_products_is_set_manually(): void
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $this->stock()->setManualStatus($product, StockStatus::OutOfStock);

        $this->assertSame(StockStatus::OutOfStock, $product->refresh()->stock_status);
    }

    public function test_availability_of_tracked_products_cannot_be_set_manually(): void
    {
        $product = Product::factory()->outOfStock()->create();

        $this->expectException(StockException::class);

        $this->stock()->setManualStatus($product, StockStatus::InStock);
    }

    public function test_movements_form_a_consistent_ledger(): void
    {
        $product = Product::factory()->withStock(0)->create();

        $this->stock()->adjust($product, 10, StockMovementType::Restock);
        $this->stock()->adjust($product, -3, StockMovementType::Adjustment, note: 'Count');
        $this->stock()->adjust($product, 5, StockMovementType::Restock);

        $movements = $product->stockMovements()->orderBy('id')->get();
        $this->assertSame([10, 7, 12], $movements->pluck('quantity_after')->all());
        $this->assertSame(12, (int) $movements->sum('quantity_change'));
        $this->assertSame(12, $product->refresh()->stock_quantity);
    }

    // --- Product CRUD cannot touch stock ----------------------------------------

    public function test_stock_fields_are_not_mass_assignable(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $product->update(['stock_quantity' => 999, 'stock_status' => 'out_of_stock']);

        $product->refresh();
        $this->assertSame(5, $product->stock_quantity);
        $this->assertSame(StockStatus::InStock, $product->stock_status);
    }

    public function test_product_update_requests_cannot_change_stock(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->withStock(5)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.products.update', $product), [
                'name' => $product->name, 'sku' => $product->sku, 'category_id' => $category->id,
                'selling_price' => '100.00', 'status' => 'active', 'manage_stock' => '1',
                'stock_quantity' => 999, 'stock_status' => 'out_of_stock', 'opening_stock' => 50,
            ])
            ->assertSessionHasErrors(['stock_quantity', 'stock_status', 'opening_stock']);

        $this->assertSame(5, $product->refresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_tampered_stock_fields_are_reported_on_the_form(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $user = User::factory()->manager()->create();

        $this->actingAs($user)->from(route('admin.products.edit', $product))->put(route('admin.products.update', $product), [
            'name' => $product->name, 'sku' => $product->sku, 'category_id' => $category->id,
            'selling_price' => '100.00', 'status' => 'active', 'manage_stock' => '1', 'stock_quantity' => 999,
        ]);

        $this->actingAs($user)->get(route('admin.products.edit', $product))
            ->assertSee('Stock quantity cannot be edited here. Use a stock adjustment on the product page.');
    }

    public function test_product_create_requests_cannot_set_stock_directly(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.products.store'), [
                'name' => 'Sneaky', 'sku' => 'SNEAKY-1', 'category_id' => $category->id,
                'selling_price' => '100.00', 'status' => 'active', 'manage_stock' => '1', 'stock_quantity' => 500,
            ])
            ->assertSessionHasErrors('stock_quantity');

        $this->assertSame(0, Product::count());
    }

    public function test_opening_stock_is_recorded_as_a_restock_movement(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->manager()->create();

        $this->actingAs($user)->post(route('admin.products.store'), [
            'name' => 'Mug', 'sku' => 'MUG-1', 'category_id' => $category->id,
            'selling_price' => '300.00', 'status' => 'active', 'manage_stock' => '1', 'opening_stock' => 12,
        ])->assertSessionHasNoErrors();

        $product = Product::firstWhere('sku', 'MUG-1');
        $movement = $product->stockMovements()->sole();
        $this->assertSame(12, $product->stock_quantity);
        $this->assertSame(StockMovementType::Restock, $movement->type);
        $this->assertSame(12, $movement->quantity_after);
        $this->assertSame('Opening stock', $movement->note);
        $this->assertTrue($movement->user->is($user));
    }

    public function test_zero_opening_stock_creates_no_movement_and_an_out_of_stock_product(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())->post(route('admin.products.store'), [
            'name' => 'Lamp', 'sku' => 'LAMP-1', 'category_id' => $category->id,
            'selling_price' => '300.00', 'status' => 'draft', 'manage_stock' => '1', 'opening_stock' => 0,
        ]);

        $product = Product::firstWhere('sku', 'LAMP-1');
        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(StockStatus::OutOfStock, $product->stock_status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_untracked_products_ignore_opening_stock_and_start_in_stock(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())->post(route('admin.products.store'), [
            'name' => 'Custom Cake', 'sku' => 'CAKE-1', 'category_id' => $category->id,
            'selling_price' => '1500.00', 'status' => 'active', 'manage_stock' => '0', 'opening_stock' => 10,
        ]);

        $product = Product::firstWhere('sku', 'CAKE-1');
        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(StockStatus::InStock, $product->stock_status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_turning_stock_tracking_back_on_recalculates_availability(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['manage_stock' => false, 'stock_quantity' => 0]);

        $this->actingAs(User::factory()->manager()->create())->put(route('admin.products.update', $product), [
            'name' => $product->name, 'sku' => $product->sku, 'category_id' => $category->id,
            'selling_price' => '100.00', 'status' => 'active', 'manage_stock' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockStatus::OutOfStock, $product->refresh()->stock_status);
    }

    // --- HTTP stock adjustments ----------------------------------------------------

    public function test_a_manager_can_restock_a_product(): void
    {
        $product = Product::factory()->withStock(4)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.products.stock.adjust', $product), ['type' => 'restock', 'quantity_change' => 6])
            ->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHas('success', 'Stock increased by 6. New quantity: 10.');

        $this->assertSame(10, $product->refresh()->stock_quantity);
    }

    public function test_a_correction_can_remove_stock_with_a_note(): void
    {
        $product = Product::factory()->withStock(4)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.products.stock.adjust', $product), ['type' => 'adjustment', 'quantity_change' => -1, 'note' => 'Damaged'])
            ->assertSessionHas('success', 'Stock reduced by 1. New quantity: 3.');

        $this->assertSame('Damaged', $product->stockMovements()->sole()->note);
    }

    public function test_a_correction_requires_a_note(): void
    {
        $product = Product::factory()->withStock(4)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.products.stock.adjust', $product), ['type' => 'adjustment', 'quantity_change' => -1])
            ->assertSessionHasErrors('note');

        $this->assertSame(4, $product->refresh()->stock_quantity);
    }

    public function test_a_restock_cannot_be_negative(): void
    {
        $product = Product::factory()->withStock(4)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.products.stock.adjust', $product), ['type' => 'restock', 'quantity_change' => -2])
            ->assertSessionHasErrors('quantity_change');

        $this->assertSame(4, $product->refresh()->stock_quantity);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAdjustments(): array
    {
        return [
            'zero' => [['type' => 'restock', 'quantity_change' => 0], 'quantity_change'],
            'fraction' => [['type' => 'restock', 'quantity_change' => 1.5], 'quantity_change'],
            'missing quantity' => [['type' => 'restock'], 'quantity_change'],
            'sale is reserved for orders' => [['type' => 'sale', 'quantity_change' => -1], 'type'],
            'cancellation is reserved for orders' => [['type' => 'cancellation', 'quantity_change' => 1], 'type'],
            'unknown type' => [['type' => 'gift', 'quantity_change' => 1], 'type'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidAdjustments')]
    public function test_invalid_adjustments_are_rejected(array $input, string $field): void
    {
        $product = Product::factory()->withStock(4)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.products.stock.adjust', $product), $input)
            ->assertSessionHasErrors($field);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_removing_more_than_available_shows_an_error_and_changes_nothing(): void
    {
        $product = Product::factory()->withStock(2)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->from(route('admin.products.show', $product))
            ->post(route('admin.products.stock.adjust', $product), ['type' => 'adjustment', 'quantity_change' => -5, 'note' => 'Count'])
            ->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Not enough stock'));

        $this->assertSame(2, $product->refresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_api_clients_get_422_for_refused_stock_changes(): void
    {
        $product = Product::factory()->withStock(2)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->postJson(route('admin.products.stock.adjust', $product), ['type' => 'adjustment', 'quantity_change' => -5, 'note' => 'Count'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Not enough stock'));
    }

    public function test_availability_endpoint_for_untracked_products(): void
    {
        $product = Product::factory()->create(['manage_stock' => false]);
        $user = User::factory()->manager()->create();

        $this->actingAs($user)
            ->patch(route('admin.products.stock.availability', $product), ['stock_status' => 'out_of_stock'])
            ->assertSessionHas('success');
        $this->assertSame(StockStatus::OutOfStock, $product->refresh()->stock_status);

        $this->actingAs($user)
            ->patch(route('admin.products.stock.availability', $product), ['stock_status' => 'maybe'])
            ->assertSessionHasErrors('stock_status');
    }

    public function test_staff_cannot_change_stock(): void
    {
        $tracked = Product::factory()->withStock(4)->create();
        $untracked = Product::factory()->create(['manage_stock' => false]);
        $staff = User::factory()->create();

        $this->actingAs($staff)->post(route('admin.products.stock.adjust', $tracked), ['type' => 'restock', 'quantity_change' => 5])->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.products.stock.availability', $untracked), ['stock_status' => 'out_of_stock'])->assertForbidden();

        $this->assertSame(4, $tracked->refresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_details_page_shows_stock_history(): void
    {
        $user = User::factory()->manager()->create(['name' => 'Stock Keeper']);
        $product = Product::factory()->withStock(0)->create();
        $this->stock()->adjust($product, 8, StockMovementType::Restock, $user, 'First delivery');

        $this->actingAs($user)
            ->get(route('admin.products.show', $product))
            ->assertSee('Restock')
            ->assertSee('+8 → 8')
            ->assertSee('Stock Keeper')
            ->assertSee('First delivery');
    }
}
