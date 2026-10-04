<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->product = Product::factory()->create(['name' => 'Leather Wallet']);
        $this->manager = User::factory()->manager()->create();
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function upload(array $files, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)
            ->from(route('admin.products.show', $this->product))
            ->post(route('admin.products.images.store', $this->product), ['images' => $files]);
    }

    /**
     * @return list<int>
     */
    private function orderedIds(): array
    {
        return $this->product->images()->pluck('id')->all();
    }

    // --- Uploading ---------------------------------------------------------------

    public function test_a_valid_image_is_uploaded_to_the_public_disk(): void
    {
        $this->upload([UploadedFile::fake()->image('wallet.jpg', 800, 800)])
            ->assertRedirect(route('admin.products.show', $this->product))
            ->assertSessionHas('success', '1 image uploaded.');

        $image = $this->product->images()->sole();
        $this->assertSame('public', $image->disk);
        $this->assertSame(0, $image->sort_order);
        $this->assertMatchesRegularExpression("#^products/{$this->product->id}/[0-9a-f-]{36}\.jpg$#", $image->path);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_multiple_images_are_stored_in_upload_order_and_the_first_is_primary(): void
    {
        $this->upload([
            UploadedFile::fake()->image('front.jpg', 400, 400),
            UploadedFile::fake()->image('back.png', 400, 400),
            UploadedFile::fake()->image('side.jpg', 400, 400),
        ])->assertSessionHas('success', '3 images uploaded.');

        $images = $this->product->images;
        $this->assertSame([0, 1, 2], $images->pluck('sort_order')->all());
        $this->assertStringEndsWith('.png', $images[1]->path);
        $this->assertTrue($this->product->primaryImage->is($images[0]));
        $this->assertCount(3, Storage::disk('public')->allFiles("products/{$this->product->id}"));
    }

    public function test_later_uploads_are_appended_after_existing_images(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200)]);
        $this->upload([UploadedFile::fake()->image('b.jpg', 200, 200), UploadedFile::fake()->image('c.jpg', 200, 200)]);

        $this->assertSame([0, 1, 2], $this->product->images()->pluck('sort_order')->all());
    }

    public function test_the_per_product_image_limit_is_enforced(): void
    {
        config(['media.products.max_images' => 3]);
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200), UploadedFile::fake()->image('b.jpg', 200, 200)]);

        $this->upload([UploadedFile::fake()->image('c.jpg', 200, 200), UploadedFile::fake()->image('d.jpg', 200, 200)])
            ->assertSessionHasErrors(['images' => 'A product can have at most 3 images (2 already uploaded).']);

        $this->assertSame(2, $this->product->images()->count());
        $this->assertCount(2, Storage::disk('public')->allFiles("products/{$this->product->id}"), 'Rejected uploads leave no files behind.');
    }

    public function test_the_per_request_file_limit_is_enforced(): void
    {
        $files = array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg", 200, 200), range(1, 5));

        $this->upload($files)->assertSessionHasErrors('images');

        $this->assertSame(0, $this->product->images()->count());
    }

    public function test_at_least_one_file_is_required(): void
    {
        $this->upload([])->assertSessionHasErrors('images');
    }

    /**
     * @return array<string, array{\Closure(): UploadedFile}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'php script' => [fn () => UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>')],
            'php disguised as jpg' => [fn () => UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["c"]); ?>')],
            'php with spoofed image mime' => [fn () => UploadedFile::fake()->create('photo.png', 1, 'image/png')],
            'html file' => [fn () => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')],
            'svg with script' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'pdf' => [fn () => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
            'gif' => [fn () => UploadedFile::fake()->image('anim.gif', 200, 200)],
            'too small' => [fn () => UploadedFile::fake()->image('tiny.jpg', 50, 50)],
            'too large in pixels' => [fn () => UploadedFile::fake()->image('huge.jpg', 5001, 100)],
            'too large in bytes' => [fn () => UploadedFile::fake()->image('heavy.jpg', 200, 200)->size(3000)],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_invalid_files_are_rejected(\Closure $makeFile): void
    {
        $this->upload([$makeFile()])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_one_bad_file_rejects_the_whole_batch(): void
    {
        $this->upload([
            UploadedFile::fake()->image('good.jpg', 200, 200),
            UploadedFile::fake()->createWithContent('bad.jpg', 'not an image'),
        ])->assertSessionHasErrors('images.1');

        $this->assertSame(0, $this->product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_polyglot_image_is_accepted_but_stored_without_its_payload(): void
    {
        $file = UploadedFile::fake()->image('polyglot.jpg', 300, 300);
        file_put_contents($file->getRealPath(), '<?php echo "pwned"; ?>', FILE_APPEND);

        $this->upload([$file])->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->get($this->product->images()->sole()->path);
        $this->assertStringNotContainsString('<?php', $stored);
    }

    public function test_the_details_page_shows_images_with_the_primary_marked(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200), UploadedFile::fake()->image('b.jpg', 200, 200)]);
        $primary = $this->product->images()->first();

        $this->actingAs($this->manager)
            ->get(route('admin.products.show', $this->product))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($primary->path))
            ->assertSee('Primary')
            ->assertSee('2 / 10');
    }

    // --- Alt text, ordering, deletion -------------------------------------------------

    public function test_alt_text_can_be_set_and_cleared(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200)]);
        $image = $this->product->images()->sole();

        $this->actingAs($this->manager)
            ->patch(route('admin.products.images.update', [$this->product, $image]), ['alt_text' => 'Brown leather wallet, front'])
            ->assertSessionHas('success');
        $this->assertSame('Brown leather wallet, front', $image->refresh()->alt_text);

        $this->actingAs($this->manager)->patch(route('admin.products.images.update', [$this->product, $image]), ['alt_text' => '']);
        $this->assertNull($image->refresh()->alt_text);
    }

    public function test_alt_text_is_limited_and_escaped(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200)]);
        $image = $this->product->images()->sole();

        $this->actingAs($this->manager)
            ->patch(route('admin.products.images.update', [$this->product, $image]), ['alt_text' => str_repeat('x', 256)])
            ->assertSessionHasErrors('alt_text');

        $image->update(['alt_text' => '"><script>alert(1)</script>']);
        $this->actingAs($this->manager)
            ->get(route('admin.products.show', $this->product))
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_images_can_be_reordered_and_made_primary(): void
    {
        $this->upload([
            UploadedFile::fake()->image('a.jpg', 200, 200),
            UploadedFile::fake()->image('b.jpg', 200, 200),
            UploadedFile::fake()->image('c.jpg', 200, 200),
        ]);
        [$a, $b, $c] = $this->orderedIds();

        $this->actingAs($this->manager)->patch(route('admin.products.images.move', [$this->product, $c, 'up']));
        $this->assertSame([$a, $c, $b], $this->orderedIds());

        $this->actingAs($this->manager)->patch(route('admin.products.images.move', [$this->product, $a, 'down']));
        $this->assertSame([$c, $a, $b], $this->orderedIds());

        $this->actingAs($this->manager)
            ->patch(route('admin.products.images.move', [$this->product, $b, 'first']))
            ->assertSessionHas('success', 'Primary image updated.');
        $this->assertSame([$b, $c, $a], $this->orderedIds());
        $this->assertSame($b, $this->product->primaryImage()->first()->id);
        $this->assertSame([0, 1, 2], $this->product->images()->pluck('sort_order')->all());
    }

    public function test_moving_beyond_the_ends_is_a_no_op(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200), UploadedFile::fake()->image('b.jpg', 200, 200)]);
        [$a, $b] = $this->orderedIds();

        $this->actingAs($this->manager)->patch(route('admin.products.images.move', [$this->product, $a, 'up']));
        $this->actingAs($this->manager)->patch(route('admin.products.images.move', [$this->product, $b, 'down']));

        $this->assertSame([$a, $b], $this->orderedIds());
    }

    public function test_unknown_move_directions_are_not_routable(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200)]);

        $this->actingAs($this->manager)
            ->patch('/admin/products/'.$this->product->id.'/images/'.$this->orderedIds()[0].'/move/sideways')
            ->assertNotFound();
    }

    public function test_deleting_an_image_removes_the_file_and_renumbers_the_rest(): void
    {
        $this->upload([
            UploadedFile::fake()->image('a.jpg', 200, 200),
            UploadedFile::fake()->image('b.jpg', 200, 200),
            UploadedFile::fake()->image('c.jpg', 200, 200),
        ]);
        $primary = $this->product->images()->first();

        $this->actingAs($this->manager)
            ->delete(route('admin.products.images.destroy', [$this->product, $primary]))
            ->assertSessionHas('success', 'Image deleted.');

        $this->assertModelMissing($primary);
        Storage::disk('public')->assertMissing($primary->path);
        $this->assertSame([0, 1], $this->product->images()->pluck('sort_order')->all());
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_images_of_another_product_cannot_be_targeted(): void
    {
        $other = Product::factory()->create();
        $foreign = ProductImage::factory()->for($other)->create();

        $this->actingAs($this->manager)->patch(route('admin.products.images.update', [$this->product, $foreign]), ['alt_text' => 'x'])->assertNotFound();
        $this->actingAs($this->manager)->patch(route('admin.products.images.move', [$this->product, $foreign, 'first']))->assertNotFound();
        $this->actingAs($this->manager)->delete(route('admin.products.images.destroy', [$this->product, $foreign]))->assertNotFound();

        $this->assertModelExists($foreign);
    }

    // --- Authorization -------------------------------------------------------------

    public function test_staff_cannot_manage_images(): void
    {
        $this->upload([UploadedFile::fake()->image('a.jpg', 200, 200)]);
        $image = $this->product->images()->sole();
        $staff = User::factory()->create();

        $this->upload([UploadedFile::fake()->image('b.jpg', 200, 200)], $staff)->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.products.images.update', [$this->product, $image]), ['alt_text' => 'x'])->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.products.images.move', [$this->product, $image, 'first']))->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.products.images.destroy', [$this->product, $image]))->assertForbidden();

        $this->assertSame(1, $this->product->images()->count());
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_guests_cannot_upload_images(): void
    {
        $this->post(route('admin.products.images.store', $this->product), ['images' => [UploadedFile::fake()->image('a.jpg', 200, 200)]])
            ->assertRedirect(route('login'));

        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
