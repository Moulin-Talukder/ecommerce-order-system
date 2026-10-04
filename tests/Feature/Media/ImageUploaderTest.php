<?php

namespace Tests\Feature\Media;

use App\Exceptions\Media\InvalidImageException;
use App\Services\Media\ImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ImageUploaderTest extends TestCase
{
    private ImageUploader $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->uploader = app(ImageUploader::class);
    }

    public function test_it_stores_a_reencoded_image_under_a_random_name(): void
    {
        $path = $this->uploader->store(UploadedFile::fake()->image('../../evil name.php.jpg', 640, 480), 'products/7');

        $this->assertMatchesRegularExpression('#^products/7/[0-9a-f-]{36}\.jpg$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame([640, 480, IMAGETYPE_JPEG], array_slice(getimagesizefromstring(Storage::disk('public')->get($path)), 0, 3));
    }

    public function test_png_and_webp_keep_their_format(): void
    {
        $png = $this->uploader->store(UploadedFile::fake()->image('a.png', 300, 300), 'test');
        $webp = $this->uploader->store($this->webpUpload(), 'test');

        $this->assertStringEndsWith('.png', $png);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(Storage::disk('public')->get($png))[2]);
        $this->assertStringEndsWith('.webp', $webp);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('public')->get($webp))[2]);
    }

    public function test_the_extension_comes_from_the_content_not_the_client_filename(): void
    {
        $png = UploadedFile::fake()->image('real.png', 200, 200);
        $disguised = new UploadedFile($png->getRealPath(), 'photo.jpg', 'image/jpeg', null, true);

        $this->assertStringEndsWith('.png', $this->uploader->store($disguised, 'test'));
    }

    public function test_script_payloads_appended_to_an_image_are_removed(): void
    {
        $file = UploadedFile::fake()->image('polyglot.jpg', 300, 300);
        file_put_contents($file->getRealPath(), '<?php system($_GET["c"]); ?>', FILE_APPEND);
        $this->assertStringContainsString('<?php', file_get_contents($file->getRealPath()));

        $path = $this->uploader->store($file, 'test');

        $stored = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('system(', $stored);
        $this->assertStringEndsWith('.jpg', $path);
    }

    public function test_embedded_metadata_is_stripped(): void
    {
        $file = UploadedFile::fake()->image('meta.jpg', 300, 300);
        $this->insertJpegSegment($file, "\xFF\xFE", 'SECRET-GPS-LOCATION-23.8103,90.4125');

        $stored = Storage::disk('public')->get($this->uploader->store($file, 'test'));

        $this->assertStringNotContainsString('SECRET-GPS-LOCATION', $stored);
    }

    public function test_exif_orientation_is_applied_to_the_pixels(): void
    {
        $file = UploadedFile::fake()->image('phone.jpg', 400, 200);
        $this->insertJpegSegment($file, "\xFF\xE1", $this->exifWithOrientation(6));

        $stored = Storage::disk('public')->get($this->uploader->store($file, 'test'));

        $this->assertSame([200, 400], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_large_images_are_scaled_down(): void
    {
        config(['media.images.resize_to' => 300]);

        $path = $this->uploader->store(UploadedFile::fake()->image('big.jpg', 1200, 600), 'test');

        $this->assertSame([300, 150], array_slice(getimagesizefromstring(Storage::disk('public')->get($path)), 0, 2));
    }

    public function test_images_beyond_the_dimension_limit_are_rejected_before_decoding(): void
    {
        config(['media.images.max_dimension' => 500]);

        $this->expectException(InvalidImageException::class);

        $this->uploader->store(UploadedFile::fake()->image('huge.jpg', 600, 100), 'test');
    }

    public function test_non_images_are_rejected(): void
    {
        $this->expectException(InvalidImageException::class);

        $this->uploader->store(UploadedFile::fake()->createWithContent('shell.jpg', '<?php phpinfo(); ?>'), 'test');
    }

    public function test_disallowed_image_types_are_rejected(): void
    {
        $this->expectException(InvalidImageException::class);

        $this->uploader->store(UploadedFile::fake()->image('anim.gif', 200, 200), 'test');
    }

    public function test_nothing_is_written_when_an_image_is_rejected(): void
    {
        try {
            $this->uploader->store(UploadedFile::fake()->createWithContent('x.png', 'not an image'), 'test');
        } catch (InvalidImageException) {
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_failed_disk_write_raises_an_error(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->expectException(RuntimeException::class);

        $this->uploader->store(UploadedFile::fake()->image('a.jpg', 200, 200), 'test');
    }

    public function test_files_can_be_deleted(): void
    {
        $path = $this->uploader->store(UploadedFile::fake()->image('a.jpg', 200, 200), 'test');

        $this->uploader->delete('public', $path);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_shared_validation_rules_reject_svg(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');

        $validator = validator(['file' => $svg], ['file' => ImageUploader::rules()]);

        $this->assertTrue($validator->fails());
    }

    private function webpUpload(): UploadedFile
    {
        $image = imagecreatetruecolor(200, 200);
        $path = tempnam(sys_get_temp_dir(), 'webp');
        imagewebp($image, $path);

        return new UploadedFile($path, 'a.webp', 'image/webp', null, true);
    }

    /**
     * Insert a JPEG marker segment right after the SOI marker.
     */
    private function insertJpegSegment(UploadedFile $file, string $marker, string $payload): void
    {
        $bytes = file_get_contents($file->getRealPath());
        $segment = $marker.pack('n', strlen($payload) + 2).$payload;
        file_put_contents($file->getRealPath(), substr($bytes, 0, 2).$segment.substr($bytes, 2));
    }

    /**
     * Minimal little-endian EXIF block containing only the Orientation tag.
     */
    private function exifWithOrientation(int $orientation): string
    {
        return "Exif\0\0"
            ."II*\0".pack('V', 8)          // TIFF header, IFD0 at offset 8
            .pack('v', 1)                  // one IFD entry
            .pack('vvV', 0x0112, 3, 1)     // Orientation, SHORT, count 1
            .pack('vv', $orientation, 0)   // value (padded to 4 bytes)
            .pack('V', 0);                 // no next IFD
    }
}
