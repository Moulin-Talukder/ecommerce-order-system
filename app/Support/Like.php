<?php

namespace App\Support;

/**
 * Builds SQL LIKE patterns from user input, treating % and _ literally.
 */
final class Like
{
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
