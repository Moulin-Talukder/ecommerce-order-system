<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function equivalentFormats(): array
    {
        return [
            'canonical' => ['01712345678'],
            'with +880' => ['+8801712345678'],
            'with 880' => ['8801712345678'],
            'with 00880' => ['008801712345678'],
            'without leading zero' => ['1712345678'],
            'with spaces and dashes' => ['017-1234 5678'],
            'with surrounding whitespace' => ['  01712345678 '],
            'formatted international' => ['+880 1712-345678'],
        ];
    }

    #[DataProvider('equivalentFormats')]
    public function test_equivalent_formats_normalize_to_the_same_number(string $input): void
    {
        $this->assertSame('01712345678', PhoneNumber::normalize($input));
        $this->assertTrue(PhoneNumber::isValid($input));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too short' => ['0171234567'],
            'too long' => ['017123456789'],
            'invalid operator prefix 012' => ['01212345678'],
            'landline' => ['029876543'],
            'letters' => ['phone'],
            'foreign number' => ['+14155552671'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_return_null(?string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }
}
