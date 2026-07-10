<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests\Fixtures;

use Happenv\Ltree\Concerns\HasLtree;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Factory-enabled twin of Category, for exercising
 * `Testing\HasLtreeFactory::createTree()` — Category itself has no factory
 * since none of the other test suites need one.
 */
class FactoryCategory extends Model
{
    /** @use HasFactory<FactoryCategoryFactory> */
    use HasFactory;

    use HasLtree;

    protected $guarded = [];

    public $timestamps = false;

    protected static function newFactory(): FactoryCategoryFactory
    {
        return FactoryCategoryFactory::new();
    }
}
