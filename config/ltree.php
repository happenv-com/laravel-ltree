<?php

declare(strict_types=1);

use Happenv\Ltree\Normalization\DefaultNormalizer;

return [
    'path_column' => 'path',
    'parent_column' => 'parent_id',

    // Ordering is opt-in: set this to a real integer column you add to your
    // table (e.g. 'sort_order') to enable moveBefore()/moveAfter()/
    // moveFirst()/moveLast(). Left null, appendChild()/prependChild() simply
    // attach without touching any sort column, and the ordered-move methods
    // throw MissingSortColumnException.
    'order_column' => null,
    'auto_update_path' => true,
    'auto_create_extension' => false,
    'default_index' => 'gist',
    'transactional_moves' => true,
    'cascade_delete' => false,
    'fk_on_delete' => 'restrict',
    'normalizer' => [
        'class' => DefaultNormalizer::class,
        'strategy' => 'replace', // 'replace' | 'throw'
        'replacements' => ['-' => '_'],
    ],
];
