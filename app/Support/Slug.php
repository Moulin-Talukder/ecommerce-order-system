<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generates URL slugs that are unique within a model's table.
 */
final class Slug
{
    /**
     * Build a unique slug from the given text, appending -2, -3, ... on collision.
     *
     * Falls back to a random slug when the text has no ASCII-transliterable
     * characters (e.g. a name written only in Bangla).
     *
     * @param  class-string<Model>  $model
     */
    public static function unique(string $model, string $text, ?int $ignoreId = null, string $column = 'slug', int $maxLength = 160): string
    {
        $base = Str::limit(Str::slug($text), $maxLength, '');
        $base = trim($base, '-') ?: Str::lower(Str::random(8));

        $slug = $base;
        $suffix = 2;

        while (self::exists($model, $column, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function exists(string $model, string $column, string $slug, ?int $ignoreId): bool
    {
        return $model::query()
            ->where($column, $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
