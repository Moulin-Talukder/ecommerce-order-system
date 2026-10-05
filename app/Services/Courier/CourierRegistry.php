<?php

namespace App\Services\Courier;

use App\Enums\CourierProvider;
use App\Services\Courier\Steadfast\SteadfastGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the API gateway for a courier provider. Adding a courier means
 * adding a CourierProvider case and a gateway class here.
 */
class CourierRegistry
{
    public function __construct(private Container $container) {}

    public function gateway(CourierProvider $provider): CourierGateway
    {
        return $this->container->make(match ($provider) {
            CourierProvider::Steadfast => SteadfastGateway::class,
        });
    }
}
