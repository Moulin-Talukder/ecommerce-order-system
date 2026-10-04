<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * The uploaded file is not a safe, decodable image of an allowed type.
 */
class InvalidImageException extends RuntimeException {}
