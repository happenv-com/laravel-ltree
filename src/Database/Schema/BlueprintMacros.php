<?php

declare(strict_types=1);

namespace Happenv\Ltree\Database\Schema;

use Happenv\Ltree\Exceptions\UnsupportedIndexException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\IndexDefinition;

final class BlueprintMacros
{
    public static function register(): void
    {
        if (! Blueprint::hasMacro('ltree')) {
            Blueprint::macro('ltree', function (string $column = 'path'): ColumnDefinition {
                /** @var Blueprint $this */
                return $this->addColumn('ltree', $column);
            });
        }

        if (! Blueprint::hasMacro('lquery')) {
            Blueprint::macro('lquery', function (string $column): ColumnDefinition {
                /** @var Blueprint $this */
                return $this->addColumn('lquery', $column);
            });
        }

        if (! Blueprint::hasMacro('ltxtquery')) {
            Blueprint::macro('ltxtquery', function (string $column): ColumnDefinition {
                /** @var Blueprint $this */
                return $this->addColumn('ltxtquery', $column);
            });
        }

        if (! Blueprint::hasMacro('ltreeDepth')) {
            Blueprint::macro('ltreeDepth', function (string $column = 'depth', string $from = 'path'): ColumnDefinition {
                /** @var Blueprint $this */
                return $this->integer($column)->storedAs('nlevel("'.$from.'")');
            });
        }

        if (! Blueprint::hasMacro('gist')) {
            /** @return IndexDefinition */
            Blueprint::macro('gist', function (string|array $columns, ?string $name = null) {
                /** @var Blueprint $this */
                return $this->index($columns, $name, 'gist');
            });
        }

        if (! Blueprint::hasMacro('gin')) {
            Blueprint::macro('gin', function (string|array $columns, ?string $name = null): never {
                throw new UnsupportedIndexException(
                    'A scalar ltree column has no GIN operator class in PostgreSQL; use $table->gist() (GiST is the ltree index). GIN applies only to ltree[] columns.',
                );
            });
        }
    }
}
