<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use App\Services\Catalog\BrandService;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CatalogAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Arif', 'email' => 'arif@example.test']);
    }

    private function sole(): AuditLog
    {
        return AuditLog::query()->sole();
    }

    // --- Categories -------------------------------------------------------------

    public function test_category_create_update_status_and_delete_are_each_recorded_once(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categories.store'), [
            'name' => 'Panjabi', 'slug' => '', 'parent_id' => '', 'description' => 'Festive wear for Eid', 'is_active' => '1', 'sort_order' => '2',
        ])->assertSessionHasNoErrors();

        $category = Category::query()->sole();
        $created = $this->sole();
        $this->assertSame([AuditEvent::CategoryCreated, AuditOrigin::Admin, $this->admin->id], [$created->event, $created->origin, $created->actor_id]);
        $this->assertSame(['category', $category->id, 'Panjabi'], [$created->subject_type, $created->subject_id, $created->subject_label]);
        $this->assertEquals(['parent_id' => null, 'name' => 'Panjabi', 'slug' => 'panjabi', 'is_active' => true, 'sort_order' => 2, 'description' => AuditRedactor::CHANGED, 'meta_title' => null, 'meta_description' => null], $created->new_values);

        $this->put(route('admin.categories.update', $category), ['name' => 'Panjabi & Kurta', 'slug' => 'panjabi', 'description' => 'Festive wear for Eid', 'is_active' => '1', 'sort_order' => '5']);
        $updated = AuditLog::query()->where('event', AuditEvent::CategoryUpdated)->sole();
        $this->assertEquals(['name' => 'Panjabi', 'sort_order' => 2], $updated->old_values);
        $this->assertEquals(['name' => 'Panjabi & Kurta', 'sort_order' => 5], $updated->new_values);

        $this->patch(route('admin.categories.status', $category), ['is_active' => '0']);
        $this->patch(route('admin.categories.status', $category), ['is_active' => '0']);
        $status = AuditLog::query()->where('event', AuditEvent::CategoryStatusChanged)->sole();
        $this->assertSame([['is_active' => true], ['is_active' => false]], [$status->old_values, $status->new_values]);

        $this->delete(route('admin.categories.destroy', $category))->assertSessionHasNoErrors();
        $deleted = AuditLog::query()->where('event', AuditEvent::CategoryDeleted)->sole();
        $this->assertModelMissing($category);
        $this->assertSame(['category', $category->id, 'Panjabi & Kurta'], [$deleted->subject_type, $deleted->subject_id, $deleted->subject_label]);
        $this->assertSame('Panjabi & Kurta', $deleted->old_values['name']);
        $this->assertNull($deleted->new_values);

        $this->assertSame(4, AuditLog::query()->count());
        $this->assertStringNotContainsString('Festive wear', (string) json_encode(DB::table('audit_logs')->get()));
    }

    public function test_category_no_ops_refusals_and_invalid_input_record_nothing(): void
    {
        $category = Category::factory()->create(['name' => 'Shirts', 'slug' => 'shirts', 'sort_order' => 1, 'is_active' => true, 'description' => null]);
        Product::factory()->for($category)->create();
        $staff = User::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.categories.update', $category), ['name' => 'Shirts', 'slug' => 'shirts', 'is_active' => '1', 'sort_order' => '1']);
        $this->patch(route('admin.categories.status', $category), ['is_active' => '1']);
        $this->delete(route('admin.categories.destroy', $category));
        $this->post(route('admin.categories.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($staff)->post(route('admin.categories.store'), ['name' => 'Blocked', 'is_active' => '1'])->assertForbidden();

        $this->assertModelExists($category);
        $this->assertSame(0, AuditLog::query()->count());
    }

    // --- Brands ---------------------------------------------------------------------

    public function test_brand_create_update_status_and_delete_are_each_recorded_once(): void
    {
        $this->actingAs($this->admin)->post(route('admin.brands.store'), ['name' => 'Aarong', 'slug' => '', 'description' => '', 'is_active' => '1'])->assertSessionHasNoErrors();
        $brand = Brand::query()->sole();
        $this->assertEquals(['name' => 'Aarong', 'slug' => 'aarong', 'is_active' => true, 'description' => null], $this->sole()->new_values);

        $this->put(route('admin.brands.update', $brand), ['name' => 'Aarong', 'slug' => 'aarong', 'description' => '', 'is_active' => '1']);
        $this->assertSame(1, AuditLog::query()->count(), 'An unchanged save is not recorded.');

        $this->put(route('admin.brands.update', $brand), ['name' => 'Aarong Heritage', 'slug' => 'aarong', 'description' => 'Handloom', 'is_active' => '1']);
        $updated = AuditLog::query()->where('event', AuditEvent::BrandUpdated)->sole();
        $this->assertEquals(['name' => 'Aarong', 'description' => null], $updated->old_values);
        $this->assertEquals(['name' => 'Aarong Heritage', 'description' => AuditRedactor::CHANGED], $updated->new_values);

        $this->patch(route('admin.brands.status', $brand), ['is_active' => '0']);
        $this->assertSame([['is_active' => true], ['is_active' => false]], [AuditLog::query()->where('event', AuditEvent::BrandStatusChanged)->sole()->old_values, AuditLog::query()->where('event', AuditEvent::BrandStatusChanged)->sole()->new_values]);

        $this->delete(route('admin.brands.destroy', $brand));
        $deleted = AuditLog::query()->where('event', AuditEvent::BrandDeleted)->sole();
        $this->assertSame(['brand', $brand->id, 'Aarong Heritage'], [$deleted->subject_type, $deleted->subject_id, $deleted->subject_label]);
        $this->assertSame(4, AuditLog::query()->count());
    }

    public function test_a_brand_in_use_cannot_be_deleted_and_nothing_is_recorded(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->for($brand)->create();

        $this->actingAs($this->admin)->delete(route('admin.brands.destroy', $brand));
        $this->actingAs(User::factory()->manager()->create())->delete(route('admin.brands.destroy', $brand))->assertForbidden();

        $this->assertModelExists($brand);
        $this->assertSame(0, AuditLog::query()->count());
    }

    // --- Products -------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cotton Panjabi', 'sku' => 'PNJ-001', 'category_id' => $categoryId, 'brand_id' => null,
            'short_description' => 'Soft cotton.', 'description' => 'Hand-stitched collar.', 'purchase_price' => '900.00',
            'selling_price' => '1500.00', 'discount_price' => '', 'manage_stock' => '1', 'opening_stock' => '20',
            'low_stock_threshold' => '5', 'status' => 'active', 'is_featured' => '0', 'weight' => '0.400',
            'length' => '', 'width' => '', 'height' => '', 'meta_title' => '', 'meta_description' => '',
        ], $overrides);
    }

    public function test_product_create_records_opening_stock_in_the_same_single_entry(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->productPayload($category->id))->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $log = $this->sole();
        $this->assertSame([AuditEvent::ProductCreated, 'product', $product->id, 'PNJ-001'], [$log->event, $log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame(['opening_stock' => 20], $log->context);
        $this->assertSame([20, 'in_stock', '900.00', '1500.00', 'active'], [$log->new_values['stock_quantity'], $log->new_values['stock_status'], $log->new_values['purchase_price'], $log->new_values['selling_price'], $log->new_values['status']]);
        $this->assertSame([AuditRedactor::CHANGED, AuditRedactor::CHANGED], [$log->new_values['description'], $log->new_values['short_description']]);
        $this->assertSame(1, $product->stockMovements()->count(), 'Opening stock is still in the stock ledger.');
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::StockAdjusted)->count());
    }

    public function test_product_update_records_only_changed_fields_and_unchanged_saves_record_nothing(): void
    {
        $category = Category::factory()->create();
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->productPayload($category->id));
        $product = Product::query()->sole();

        $this->put(route('admin.products.update', $product), $this->productPayload($category->id, ['slug' => $product->slug, 'opening_stock' => null]));
        $this->assertSame(1, AuditLog::query()->count(), 'An unchanged save is not recorded.');

        $this->put(route('admin.products.update', $product), $this->productPayload($category->id, ['slug' => $product->slug, 'opening_stock' => null, 'selling_price' => '1450.00', 'purchase_price' => '950']))
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('event', AuditEvent::ProductUpdated)->sole();
        $this->assertEquals(['purchase_price' => '900.00', 'selling_price' => '1500.00'], $log->old_values);
        $this->assertEquals(['purchase_price' => '950.00', 'selling_price' => '1450.00'], $log->new_values);
        $this->assertSame('1450.00', $product->refresh()->selling_price);
    }

    public function test_product_status_availability_and_delete_are_recorded_once_each(): void
    {
        $product = Product::factory()->create(['sku' => 'WAL-9', 'status' => 'active', 'manage_stock' => false, 'stock_status' => StockStatus::InStock]);
        $this->actingAs($this->admin);

        $this->patch(route('admin.products.status', $product), ['status' => 'archived']);
        $this->patch(route('admin.products.status', $product), ['status' => 'archived']);
        $this->patch(route('admin.products.stock.availability', $product), ['stock_status' => 'out_of_stock']);
        $this->patch(route('admin.products.stock.availability', $product), ['stock_status' => 'out_of_stock']);
        $this->delete(route('admin.products.destroy', $product))->assertSessionHasNoErrors();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([AuditEvent::ProductStatusChanged, AuditEvent::ProductAvailabilityChanged, AuditEvent::ProductDeleted], $logs->pluck('event')->all());
        $this->assertSame([['status' => 'active'], ['status' => 'archived']], [$logs[0]->old_values, $logs[0]->new_values]);
        $this->assertSame([['stock_status' => 'in_stock'], ['stock_status' => 'out_of_stock']], [$logs[1]->old_values, $logs[1]->new_values]);
        $this->assertSame(['product', $product->id, 'WAL-9', ['images_deleted' => 0]], [$logs[2]->subject_type, $logs[2]->subject_id, $logs[2]->subject_label, $logs[2]->context]);
        $this->assertSame('archived', $logs[2]->old_values['status']);
        $this->assertModelMissing($product);
    }

    public function test_refused_product_changes_record_nothing(): void
    {
        $category = Category::factory()->create();
        $ordered = Product::factory()->create();
        OrderItem::factory()->for($ordered)->create();
        $tracked = Product::factory()->withStock(3)->create();

        $this->actingAs($this->admin)->delete(route('admin.products.destroy', $ordered));
        $this->patch(route('admin.products.stock.availability', $tracked), ['stock_status' => 'out_of_stock']);
        $this->post(route('admin.products.store'), $this->productPayload($category->id, ['selling_price' => '']))->assertSessionHasErrors('selling_price');
        $this->actingAs(User::factory()->create())->patch(route('admin.products.status', $tracked), ['status' => 'archived'])->assertForbidden();

        $this->assertModelExists($ordered);
        $this->assertSame(0, AuditLog::query()->count());
    }

    // --- Fail-closed ----------------------------------------------------------------

    /**
     * @return array<string, array{Closure(User): mixed}>
     */
    public static function catalogWrites(): array
    {
        $category = fn (string $name) => Category::query()->where('name', $name)->firstOrFail();
        $brand = fn (string $name) => Brand::query()->where('name', $name)->firstOrFail();
        $product = fn (string $name) => Product::query()->where('name', $name)->firstOrFail();

        return [
            'category create' => [fn (User $actor) => app(CategoryService::class)->create(['name' => 'New', 'is_active' => true], $actor)],
            'category update' => [fn (User $actor) => app(CategoryService::class)->update($category('Original'), ['name' => 'Renamed'], $actor)],
            'category status' => [fn (User $actor) => app(CategoryService::class)->setActive($category('Original'), false, $actor)],
            'category delete' => [fn (User $actor) => app(CategoryService::class)->delete($category('Spare'), $actor)],
            'brand create' => [fn (User $actor) => app(BrandService::class)->create(['name' => 'New brand'], $actor)],
            'brand update' => [fn (User $actor) => app(BrandService::class)->update($brand('Original'), ['name' => 'Renamed'], $actor)],
            'brand status' => [fn (User $actor) => app(BrandService::class)->setActive($brand('Original'), false, $actor)],
            'brand delete' => [fn (User $actor) => app(BrandService::class)->delete($brand('Spare'), $actor)],
            'product create' => [fn (User $actor) => app(ProductService::class)->create(['name' => 'New', 'sku' => 'NEW-1', 'category_id' => $category('Original')->id, 'selling_price' => '10.00', 'opening_stock' => 4], $actor)],
            'product update' => [fn (User $actor) => app(ProductService::class)->update($product('Original'), ['name' => 'Renamed', 'selling_price' => '1.00'], $actor)],
            'product status' => [fn (User $actor) => app(ProductService::class)->setStatus($product('Original'), ProductStatus::Archived, $actor)],
            'product delete' => [fn (User $actor) => app(ProductService::class)->delete($product('Spare'), $actor)],
            'product availability' => [fn (User $actor) => app(StockService::class)->setManualStatus($product('Spare'), StockStatus::OutOfStock, $actor)],
        ];
    }

    #[DataProvider('catalogWrites')]
    public function test_a_failed_audit_write_rolls_the_catalog_change_back(Closure $write): void
    {
        $original = Category::factory()->create(['name' => 'Original']);
        Category::factory()->create(['name' => 'Spare']);
        Brand::factory()->create(['name' => 'Original']);
        Brand::factory()->create(['name' => 'Spare']);
        Product::factory()->for($original)->withStock(5)->create(['name' => 'Original', 'selling_price' => '99.00']);
        Product::factory()->for($original)->create(['name' => 'Spare', 'manage_stock' => false, 'stock_status' => StockStatus::InStock]);

        $tables = ['categories', 'brands', 'products', 'stock_movements'];
        $before = array_map(fn (string $table) => DB::table($table)->orderBy('id')->get(), $tables);

        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable'));
        $this->instance(AuditLogger::class, $logger);

        try {
            $write($this->admin);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertEquals($before, array_map(fn (string $table) => DB::table($table)->orderBy('id')->get(), $tables), 'Every catalog change is rolled back.');
        $this->assertSame(0, AuditLog::query()->count());
    }
}
