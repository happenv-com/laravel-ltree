<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
});

/**
 * Seed a small fixed-label tree by writing paths directly (labels, not ids),
 * so lquery patterns can target known labels. auto_update_path is left on but
 * we overwrite path explicitly after create to get deterministic labels.
 */
function seedLabelled(): void
{
    foreach ([
        'Top',
        'Top.Science',
        'Top.Science.Astronomy',
        'Top.Science.Physics',
        'Top.Hobbies',
        'Top.Hobbies.Amateurs_Astronomy',
    ] as $p) {
        $c = Category::create([]);
        $c->forceFill(['path' => $p])->save();
    }
}

it('filters with lquery via wherePathMatches', function () {
    seedLabelled();

    // lquery's bare `*` matches zero-or-more labels, so `*.Science.*` also
    // matches `Top.Science` itself (trailing `*` matching zero labels), not
    // just its descendants — verified against the live PG instance.
    expect(Category::query()->wherePathMatches('*.Science.*')->pluck('path')->map->__toString()->all())
        ->toEqualCanonicalizing(['Top.Science', 'Top.Science.Astronomy', 'Top.Science.Physics'])
        ->and(Category::query()->wherePathMatches('Top.*{1}.Astronomy')->count())->toBe(1)
        ->and(Category::query()->wherePathMatches('*.!Physics')->pluck('path')->map->__toString()->all())
        ->not->toContain('Top.Science.Physics');

    // case-sensitive by default; the per-label @ flag makes it insensitive
    expect(Category::query()->wherePathMatches('*.science.*')->count())->toBe(0)
        ->and(Category::query()->wherePathMatches('*.science@.*')->count())->toBe(3);
});

it('ORs lquery matches via orWherePathMatches', function () {
    seedLabelled();

    $ids = Category::query()
        ->wherePathMatches('*.Astronomy')
        ->orWherePathMatches('*.Physics')
        ->pluck('path')->map->__toString()->all();

    expect($ids)->toEqualCanonicalizing(['Top.Science.Astronomy', 'Top.Science.Physics']);
});

it('matches any of several lqueries via wherePathMatchesAny', function () {
    seedLabelled();

    expect(Category::query()->wherePathMatchesAny(['*.Physics', '*.Hobbies'])->pluck('path')->map->__toString()->all())
        ->toEqualCanonicalizing(['Top.Science.Physics', 'Top.Hobbies'])
        ->and(Category::query()->wherePathMatchesAny([])->count())->toBe(0);
});
