<?php

namespace App\Services\Media;

use App\Exceptions\Media\InvalidImageException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use RuntimeException;

/**
 * Secure, reusable image storage (product images now; category images and
 * brand logos later).
 *
 * Defence in depth on top of request validation:
 *  - the real type is read from the file's bytes, never the client MIME/name;
 *  - dimensions are checked before decoding (decompression-bomb guard);
 *  - every image is decoded and re-encoded with GD, so the stored file is a
 *    freshly generated image: appended payloads (e.g. PHP in a JPEG polyglot)
 *    and EXIF metadata (GPS location, etc.) are discarded;
 *  - files get random UUID names with an extension derived from the detected
 *    type, inside a caller-chosen directory on a Laravel filesystem disk.
 */
class ImageUploader
{
    /** @var array<int, string> IMAGETYPE_* => stored extension */
    private const EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Validation rules for a single uploaded image.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        $config = config('media.images');

        return [
            'required',
            File::image()
                ->types($config['types'])
                ->max($config['max_kb'])
                ->dimensions(
                    Rule::dimensions()
                        ->minWidth($config['min_dimension'])->minHeight($config['min_dimension'])
                        ->maxWidth($config['max_dimension'])->maxHeight($config['max_dimension'])
                ),
        ];
    }

    /**
     * Sanitize and store an uploaded image.
     *
     * @return string The stored path, relative to the disk root.
     *
     * @throws InvalidImageException
     */
    public function store(UploadedFile $file, string $directory, ?string $disk = null): string
    {
        $config = config('media.images');
        $disk ??= $config['disk'];
        $contents = (string) file_get_contents($file->getRealPath());

        $info = @getimagesizefromstring($contents);
        $extension = self::EXTENSIONS[$info[2] ?? null] ?? null;

        if ($info === false || $extension === null) {
            throw new InvalidImageException('The file is not a JPEG, PNG or WebP image.');
        }

        if ($info[0] > $config['max_dimension'] || $info[1] > $config['max_dimension']) {
            throw new InvalidImageException("Images may be at most {$config['max_dimension']}px on each side.");
        }

        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new InvalidImageException('The image could not be read.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $file->getRealPath());
        }

        $image = $this->constrainSize($image, $config['resize_to']);
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $this->encode($image, $info[2], $config), 'public')) {
            throw new RuntimeException("Could not write image to disk [{$disk}].");
        }

        return $path;
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    private function constrainSize(GdImage $image, int $maxEdge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $maxEdge) {
            return $image;
        }

        $scale = $maxEdge / max($width, $height);
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);

        return $resized instanceof GdImage ? $resized : $image;
    }

    /**
     * Phone cameras store rotation in EXIF; re-encoding drops EXIF, so rotate the pixels instead.
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $rotation = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function encode(GdImage $image, int $type, array $config): string
    {
        if ($type !== IMAGETYPE_JPEG) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();

        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, $config['jpeg_quality']),
            IMAGETYPE_PNG => imagepng($image, null, 6),
            IMAGETYPE_WEBP => imagewebp($image, null, $config['webp_quality']),
        };

        return (string) ob_get_clean();
    }
}
