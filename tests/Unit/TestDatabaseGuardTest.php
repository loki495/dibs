<?php

declare(strict_types=1);

use Tests\TestCase;

it('accepts in-memory sqlite', function (): void {
    TestCase::assertIsolatedDatabase('sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null]]);
})->throwsNoExceptions();

it('refuses a database that is not in-memory sqlite', function (string $default, array $connections): void {
    TestCase::assertIsolatedDatabase($default, $connections);
})->throws(RuntimeException::class, 'Refusing to run tests')->with([
    'sqlite file' => ['sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => '/var/www/html/database/database.sqlite']]],
    'sqlite with a url' => ['sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///var/www/html/database/database.sqlite']]],
    'mysql' => ['mysql', ['mysql' => ['driver' => 'mysql', 'database' => ':memory:']]],
    'missing connection' => ['sqlite', []],
]);
