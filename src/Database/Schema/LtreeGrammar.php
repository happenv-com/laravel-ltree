<?php

declare(strict_types=1);

namespace Happenv\Ltree\Database\Schema;

use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

final class LtreeGrammar
{
    /**
     * Register the ltree column-type macros on the Postgres schema grammar.
     *
     * Laravel keeps one macro store on the shared `Illuminate\Database\Grammar`
     * base, so these become visible process-wide — harmless, since they are only
     * ever dispatched via getType() for a column whose type is literally 'ltree'
     * (produced solely by the Postgres-only Blueprint macros). The hasMacro()
     * guard is correct precisely because that store is shared.
     */
    public static function register(): void
    {
        self::macro('typeLtree', 'ltree');
        self::macro('typeLquery', 'lquery');
        self::macro('typeLtxtquery', 'ltxtquery');
    }

    private static function macro(string $name, string $sqlType): void
    {
        if (PostgresGrammar::hasMacro($name)) {
            return;
        }

        PostgresGrammar::macro($name, fn (Fluent $column): string => $sqlType);
    }
}
