<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before RefreshDatabase, which would otherwise wipe whatever database the
     * config points at (a cached config or an exported DB_DATABASE beats phpunit.xml).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        self::assertIsolatedDatabase($app['config']->get('database.default'), $app['config']->get('database.connections'));

        return $app;
    }

    /**
     * @param  array<string, array<string, mixed>>  $connections
     */
    public static function assertIsolatedDatabase(mixed $default, array $connections): void
    {
        $connection = is_string($default) ? ($connections[$default] ?? []) : [];

        if (($connection['driver'] ?? null) !== 'sqlite' || ($connection['database'] ?? null) !== ':memory:'
            || ! empty($connection['url'])) {
            throw new RuntimeException('Refusing to run tests: the default database connection is not in-memory SQLite.');
        }
    }
}
