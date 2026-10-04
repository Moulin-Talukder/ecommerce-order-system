<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against RefreshDatabase wiping a non-test database (e.g. when the
     * configuration is cached with development values). Runs before any
     * testing traits are set up.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $database = $config->get('database.connections.'.$config->get('database.default').'.database');

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("Refusing to run tests against non-test database [{$database}]. Run `php artisan config:clear`.");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
