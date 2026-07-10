<?php

declare(strict_types=1);

namespace Happenv\Ltree\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Typed factories for raw ltree SQL function expressions.
 *
 * Every argument is a column name; it is emitted as a double-quoted SQL
 * identifier (mirroring `LtreeBuilder::ltreePathColumn()`), never as a bound
 * literal — these expressions are meant to be dropped into `select()`,
 * `whereRaw()`, `orderByRaw()`, etc. alongside other query columns.
 */
final class Ltree
{
    public static function nlevel(string $column): Expression
    {
        return DB::raw('nlevel('.self::quote($column).')');
    }

    public static function subpath(string $column, int $offset, ?int $length = null): Expression
    {
        $sql = 'subpath('.self::quote($column).', '.$offset;

        if ($length !== null) {
            $sql .= ', '.$length;
        }

        return DB::raw($sql.')');
    }

    public static function subltree(string $column, int $start, int $end): Expression
    {
        return DB::raw('subltree('.self::quote($column).', '.$start.', '.$end.')');
    }

    public static function index(string $a, string $b): Expression
    {
        return DB::raw('index('.self::quote($a).', '.self::quote($b).')');
    }

    public static function lca(string ...$columns): Expression
    {
        return DB::raw('lca('.self::quoteAll($columns).')');
    }

    public static function text2ltree(string $column): Expression
    {
        return DB::raw('text2ltree('.self::quote($column).')');
    }

    public static function ltree2text(string $column): Expression
    {
        return DB::raw('ltree2text('.self::quote($column).')');
    }

    public static function concat(string ...$parts): Expression
    {
        return DB::raw('('.self::quoteAll($parts, ' || ').')');
    }

    /**
     * @param  array<string>  $columns
     */
    private static function quoteAll(array $columns, string $glue = ', '): string
    {
        return implode($glue, array_map(self::quote(...), $columns));
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
