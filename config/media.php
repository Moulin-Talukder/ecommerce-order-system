<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploaded images
    |--------------------------------------------------------------------------
    |
    | Limits for every image uploaded through App\Services\Media\ImageUploader
    | (product images now; category images and brand logos later).
    |
    | max_kb must not exceed PHP's upload_max_filesize, and
    | max_kb x max_files_per_request must fit within post_max_size
    | (currently 2M / 8M in the CLI php.ini used by `php artisan serve`).
    |
    */

    'images' => [
        'disk' => env('MEDIA_DISK', 'public'),
        'types' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_kb' => (int) env('MEDIA_IMAGE_MAX_KB', 2048),
        'min_dimension' => 100,
        'max_dimension' => 5000,
        // Larger images are scaled down so the longest edge is this many pixels.
        'resize_to' => 2000,
        'jpeg_quality' => 85,
        'webp_quality' => 85,
    ],

    'products' => [
        'max_images' => 10,
        'max_files_per_request' => 4,
    ],

];
