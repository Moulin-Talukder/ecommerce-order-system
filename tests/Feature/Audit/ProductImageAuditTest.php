<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProductImageAuditTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->product = Product::factory()->create();
        $this->manager = User::factory()->manager()->create();
    }

    private function upload(int $count): TestResponse
    {
        $files = array_map(fn (int $i) => UploadedFile::fake()->image("photo-{$i}.jpg", 800, 800), range(1, $count));

        return $this->actingAs($this->manager)->post(route('admin.products.images.store', $this->product), ['images' => $files]);
    }

    private function rawAuditRows(): string
    {
        return (string) json_encode(DB::table('audit_logs')->get());
    }

    public function test_each_uploaded_image_is_recorded_without_its_file_location(): void
    {
        $this->upload(2)->assertSessionHasNoErrors();

        $images = $this->product->images()->get();
        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([AuditEvent::ProductImageAdded, AuditEvent::ProductImageAdded], $logs->pluck('event')->all());
        $this->assertSame($images->pluck('id')->all(), $logs->pluck('subject_id')->all());
        $this->assertSame(['product_image', 'Image #'.$images[0]->id, $this->manager->id], [$logs[0]->subject_type, $logs[0]->subject_label, $logs[0]->actor_id]);
        $this->assertEquals(['product_id' => $this->product->id, 'alt_text' => null, 'sort_order' => 0], $logs[0]->new_values);
        $this->assertEquals(['product_id' => $this->product->id, 'alt_text' => null, 'sort_order' => 1], $logs[1]->new_values);

        $raw = $this->rawAuditRows();
        foreach ($images as $image) {
            $this->assertStringNotContainsString($image->path, $raw);
        }
        $this->assertStringNotContainsString('photo-1', $raw);
        $this->assertStringNotContainsString('"disk"', $raw);
    }

    public function test_alt_text_changes_are_recorded_and_unchanged_saves_are_not(): void
    {
        $image = ProductImage::factory()->for($this->product)->create(['alt_text' => null]);
        $route = route('admin.products.images.update', [$this->product, $image]);

        $this->actingAs($this->manager)->patch($route, ['alt_text' => 'Front view'])->assertSessionHasNoErrors();
        $this->patch($route, ['alt_text' => 'Front view']);

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::ProductImageUpdated, $log->event);
        $this->assertSame([['alt_text' => null], ['alt_text' => 'Front view']], [$log->old_values, $log->new_values]);
    }

    public function test_reordering_is_recorded_only_when_the_position_changes(): void
    {
        $first = ProductImage::factory()->for($this->product)->create(['sort_order' => 0]);
        $second = ProductImage::factory()->for($this->product)->create(['sort_order' => 1]);
        $this->actingAs($this->manager);

        $this->patch(route('admin.products.images.move', [$this->product, $first, 'up']));
        $this->patch(route('admin.products.images.move', [$this->product, $second, 'first']));

        $log = AuditLog::query()->sole();
        $this->assertSame([AuditEvent::ProductImageReordered, $second->id], [$log->event, $log->subject_id]);
        $this->assertSame([['sort_order' => 1], ['sort_order' => 0]], [$log->old_values, $log->new_values]);
        $this->assertEquals(['product_id' => $this->product->id, 'direction' => 'first'], $log->context);
        $this->assertSame([$second->id, $first->id], $this->product->images()->pluck('id')->all());
    }

    public function test_deleting_an_image_keeps_its_identity_in_the_audit_record(): void
    {
        $this->upload(1);
        $image = $this->product->images()->sole();

        $this->delete(route('admin.products.images.destroy', [$this->product, $image]))->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('event', AuditEvent::ProductImageDeleted)->sole();
        $this->assertModelMissing($image);
        Storage::disk('public')->assertMissing($image->path);
        $this->assertSame(['product_image', $image->id, 'Image #'.$image->id], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertEquals(['product_id' => $this->product->id, 'alt_text' => null, 'sort_order' => 0], $log->old_values);
        $this->assertStringNotContainsString($image->path, $this->rawAuditRows());
    }

    public function test_invalid_or_unauthorised_image_changes_record_nothing(): void
    {
        $image = ProductImage::factory()->for($this->product)->create();

        $this->actingAs($this->manager)->post(route('admin.products.images.store', $this->product), ['images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors();
        $this->actingAs(User::factory()->create())->delete(route('admin.products.images.destroy', [$this->product, $image]))->assertForbidden();

        $this->assertModelExists($image);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_failed_audit_write_rolls_the_upload_back_and_removes_stored_files(): void
    {
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable'));
        $this->instance(AuditLogger::class, $logger);
        $this->withoutExceptionHandling();

        try {
            $this->upload(2);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertSame(0, $this->product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, AuditLog::query()->count());
    }
}
