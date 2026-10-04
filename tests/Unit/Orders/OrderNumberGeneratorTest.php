<?php

namespace Tests\Unit\Orders;

use App\Services\Orders\OrderNumberGenerator;
use Tests\TestCase;

class OrderNumberGeneratorTest extends TestCase
{
    public function test_format_uses_prefix_date_and_unambiguous_characters(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4));

        $number = (new OrderNumberGenerator)->generate();

        $this->assertMatchesRegularExpression('/^ORD-261004-[A-HJKMNP-Z2-9]{6}$/', $number);
        $this->assertDoesNotMatchRegularExpression('/[01ILO]/', substr($number, -6));
    }

    public function test_numbers_are_random_not_sequential(): void
    {
        $generator = new OrderNumberGenerator;
        $numbers = array_map(fn () => $generator->generate(), range(1, 500));

        $this->assertCount(500, array_unique($numbers));
    }

    public function test_the_prefix_is_configurable(): void
    {
        config(['orders.number_prefix' => 'SHOP']);

        $this->assertStringStartsWith('SHOP-', (new OrderNumberGenerator)->generate());
    }
}
