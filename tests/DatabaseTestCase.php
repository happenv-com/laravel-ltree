<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class DatabaseTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('LTREE_DB_HOST', '127.0.0.1'),
            'port' => env('LTREE_DB_PORT', '55432'),
            'database' => env('LTREE_DB_DATABASE', 'ltree_test'),
            'username' => env('LTREE_DB_USERNAME', 'postgres'),
            'password' => env('LTREE_DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
        Schema::dropAllTables();
    }
}
