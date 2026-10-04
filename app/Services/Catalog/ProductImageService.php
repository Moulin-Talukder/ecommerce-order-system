<?php

namespace App\Services\Catalog;

use App\Exceptions\Media\InvalidImageException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Manages a product's images. Display order is sort_order (0 = primary);
 * every operation re-numbers the remaining images 0..n-1.
 */
class ProductImageService
{
    public function __construct(private ImageUploader $uploader) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, ProductImage>
     */
    public function add(Product $product, array $files): Collection
    {
        $disk = config('media.images.disk');
        $max = config('media.products.max_images');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($product, $files, $disk, $max, &$storedPaths): Collection {
                // Serialize concurrent uploads for the same product so the image limit holds.
                Product::query()->lockForUpdate()->findOrFail($product->id);

                $existing = $product->images()->count();

                if ($existing + count($files) > $max) {
                    throw ValidationException::withMessages([
                        'images' => "A product can have at most {$max} images ({$existing} already uploaded).",
                    ]);
                }

                $nextPosition = $existing === 0 ? 0 : (int) $product->images()->max('sort_order') + 1;
                $images = collect();

                foreach ($files as $file) {
                    $path = $this->uploader->store($file, "products/{$product->id}", $disk);
                    $storedPaths[] = $path;

                    $images->push($product->images()->create([
                        'disk' => $disk,
                        'path' => $path,
                        'sort_order' => $nextPosition++,
                    ]));
                }

                return $images;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->uploader->delete($disk, $path);
            }

            if ($exception instanceof InvalidImageException) {
                throw ValidationException::withMessages(['images' => $exception->getMessage()]);
            }

            throw $exception;
        }
    }

    public function updateAltText(ProductImage $image, ?string $altText): ProductImage
    {
        $image->update(['alt_text' => $altText]);

        return $image;
    }

    /**
     * Move an image one place up/down, or to the front (making it primary).
     */
    public function move(ProductImage $image, string $direction): void
    {
        DB::transaction(function () use ($image, $direction): void {
            $ids = $image->product->images()->lockForUpdate()->pluck('id')->all();
            $index = array_search($image->id, $ids, true);

            $target = match ($direction) {
                'first' => 0,
                'up' => max(0, $index - 1),
                'down' => min(count($ids) - 1, $index + 1),
            };

            array_splice($ids, $index, 1);
            array_splice($ids, $target, 0, [$image->id]);

            $this->renumber($ids);
        });
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $product = $image->product;
            $image->delete();

            $this->renumber($product->images()->lockForUpdate()->pluck('id')->all());

            DB::afterCommit(fn () => $this->uploader->delete($image->disk, $image->path));
        });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private function renumber(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            ProductImage::query()->whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
        }
    }
}
