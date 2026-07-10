<?php

declare(strict_types=1);

namespace Happenv\Ltree\Database\Schema;

use Illuminate\Support\Facades\DB;

final class LtreeExtension
{
    public static function create(?string $connection = null): void
    {
        DB::connection($connection)->statement('CREATE EXTENSION IF NOT EXISTS ltree');
    }

    public static function drop(?string $connection = null): void
    {
        DB::connection($connection)->statement('DROP EXTENSION IF EXISTS ltree');
    }

    public static function exists(?string $connection = null): bool
    {
        return DB::connection($connection)->selectOne(
            "SELECT 1 AS present FROM pg_extension WHERE extname = 'ltree'",
        ) !== null;
    }
}
