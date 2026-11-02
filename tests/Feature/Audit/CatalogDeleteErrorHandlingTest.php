<?php

namespace Tests\Feature\Audit;

use App\Exceptions\CannotDeleteException;
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
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only a genuine foreign-key refusal becomes CannotDeleteException; any
 * other database error during a delete (such as a failed audit insert)
 * reaches the caller unchanged, and the delete is rolled back.
 */
class CatalogDeleteErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each case: the record to delete, the delete call, and a listener that
     * attaches a referencing row after the service's own "in use" checks,
     * as a concurrent request would.
     *
     * @return array<string, array{Closure(): Model, Closure(Model, ?User): void, Closure(Model): void}>
     */
    public static function deletions(): array
    {
        return [
            'category' => [
                fn () => Category::factory()->create(),
                fn (Model $category, ?User $actor) => app(CategoryService::class)->delete($category, $actor),
                fn () => Category::deleting(fn (Category $category) => Product::factory()->for($category)->create()),
            ],
            'brand' => [
                fn () => Brand::factory()->create(),
                fn (Model $brand, ?User $actor) => app(BrandService::class)->delete($brand, $actor),
                fn () => Brand::deleting(fn (Brand $brand) => Product::factory()->for($brand)->create()),
            ],
            'product' => [
                fn () => Product::factory()->create(),
                fn (Model $product, ?User $actor) => app(ProductService::class)->delete($product, $actor),
                fn () => Product::deleting(fn (Product $product) => OrderItem::factory()->for($product)->create()),
            ],
        ];
    }

    #[DataProvider('deletions')]
    public function test_a_genuine_foreign_key_refusal_still_becomes_cannot_delete(Closure $make, Closure $delete, Closure $attachConcurrently): void
    {
        $record = $make();
        $attachConcurrently($record);

        try {
            $delete($record, User::factory()->admin()->create());
            $this->fail('The referenced row must not be deleted.');
        } catch (CannotDeleteException $exception) {
            $this->assertStringContainsString('is in use and cannot be deleted', $exception->getMessage());
            $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            $this->assertSame(['23000', 1451], array_slice($exception->getPrevious()->errorInfo, 0, 2));
        }

        $this->assertModelExists($record);
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[DataProvider('deletions')]
    public function test_a_failed_audit_insert_is_not_reported_as_a_deletion_refusal(Closure $make, Closure $delete): void
    {
        $record = $make();
        $ghost = User::factory()->make(['id' => 999_999]);

        try {
            $delete($record, $ghost);
            $this->fail('The audit failure must reach the caller.');
        } catch (CannotDeleteException) {
            $this->fail('An audit failure must not be reported as "in use".');
        } catch (QueryException $exception) {
            $this->assertSame(['23000', 1452], array_slice($exception->errorInfo, 0, 2), 'The original audit insert error, unchanged.');
            $this->assertStringContainsString('audit_logs', $exception->getMessage());
        }

        $this->assertModelExists($record);
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[DataProvider('deletions')]
    public function test_any_other_database_error_during_the_audit_write_propagates_unchanged(Closure $make, Closure $delete): void
    {
        $record = $make();
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andReturnUsing(fn () => DB::table('audit_logs_unavailable')->insert(['event' => 'x']));
        $this->instance(AuditLogger::class, $logger);

        try {
            $delete($record, User::factory()->admin()->create());
            $this->fail('The database error must reach the caller.');
        } catch (CannotDeleteException) {
            $this->fail('An unrelated database error must not be reported as "in use".');
        } catch (QueryException $exception) {
            $this->assertSame(['42S02', 1146], array_slice($exception->errorInfo, 0, 2));
            $this->assertStringContainsString('audit_logs_unavailable', $exception->getMessage());
        }

        $this->assertModelExists($record);
    }
}
