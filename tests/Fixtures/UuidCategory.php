<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests\Fixtures;

use Happenv\Ltree\Concerns\HasLtree;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UuidCategory extends Model
{
    use HasLtree;
    use HasUuids;

    protected $table = 'uuid_categories';

    protected $guarded = [];

    public $timestamps = false;

    // No getLtreeLabel() override needed: the trait default normalizes the
    // primary key (UUID hyphens -> underscores) automatically.
}
