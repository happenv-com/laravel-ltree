<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests\Fixtures;

use Happenv\Ltree\Concerns\HasLtree;
use Illuminate\Database\Eloquent\Model;

class OrderedCategory extends Model
{
    use HasLtree;

    protected $table = 'ordered_categories';

    protected $guarded = [];

    public $timestamps = false;

    // Self-contained override rather than relying on the `ltree.order_column`
    // config default: this fixture's table always has a `sort_order` column,
    // so it always supports ordered moves regardless of what a given test
    // has configured that default to (see OrderedMovesTest's use of
    // `Category` + a nulled-out config to exercise the unordered path).
    public function getLtreeOrderColumn(): string
    {
        return 'sort_order';
    }
}
