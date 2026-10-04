<?php

namespace App\Http\Requests\Admin;

use App\Services\Media\ImageUploader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.config('media.products.max_files_per_request')],
            'images.*' => ImageUploader::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $images = config('media.images');

        return [
            'images.max' => 'You can upload at most :max images at a time.',
            'images.*.image' => 'Each file must be a JPEG, PNG or WebP image.',
            'images.*.mimes' => 'Each file must be a JPEG, PNG or WebP image.',
            'images.*.max' => 'Each image may be at most '.($images['max_kb'] / 1024).' MB.',
            'images.*.dimensions' => "Images must be between {$images['min_dimension']}px and {$images['max_dimension']}px on each side.",
            'images.*.uploaded' => 'An image failed to upload. It may be larger than the server allows.',
        ];
    }
}
