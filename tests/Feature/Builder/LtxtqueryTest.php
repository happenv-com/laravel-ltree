<?php

declare(strict_types=1);

use Happenv\Ltree\Exceptions\LtreeException;
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

function seedTxt(): void
{
    foreach ([
        'Top.Science.Astronomy',
        'Top.Science.Physics',
        'Top.Hobbies.Amateurs',
    ] as $p) {
        Category::create([])->forceFill(['path' => $p])->save();
    }
}

it('matches whole labels via wherePathContains', function () {
    seedTxt();

    expect(Category::query()->wherePathContains('Science')->count())->toBe(2)
        ->and(Category::query()->wherePathContains('Sci')->count())->toBe(0)          // whole-label only
        ->and(Category::query()->wherePathContains('Astro*')->count())->toBe(1)       // prefix flag
        ->and(Category::query()->wherePathContains('science')->count())->toBe(0)      // case-sensitive
        ->and(Category::query()->wherePathContains('science@')->count())->toBe(2);    // @ flag
});

it('assembles AND / OR sets via wherePathContainsAll / Any', function () {
    seedTxt();

    expect(Category::query()->wherePathContainsAll(['Science', 'Astronomy'])->count())->toBe(1)
        ->and(Category::query()->wherePathContainsAll(['Science', 'Hobbies'])->count())->toBe(0)
        ->and(Category::query()->wherePathContainsAny(['Astronomy', 'Hobbies'])->count())->toBe(2)
        ->and(Category::query()->wherePathContainsAll([])->count())->toBe(0)
        ->and(Category::query()->wherePathContainsAny([])->count())->toBe(0);
});

it('passes a raw ltxtquery through search()', function () {
    seedTxt();

    expect(Category::query()->search('Science & !Physics')->count())->toBe(1)   // only Astronomy branch
        ->and(Category::query()->search('Astronomy | Hobbies')->count())->toBe(2);
});

it('rejects an ltxtquery operator smuggled into a contains word', function () {
    seedTxt();

    expect(fn () => Category::query()->wherePathContains('Science & Physics'))
        ->toThrow(LtreeException::class);
    expect(fn () => Category::query()->wherePathContainsAll(['Science', 'a | b']))
        ->toThrow(LtreeException::class);

    // an empty word is also rejected (an empty ltxtquery atom is invalid)
    expect(fn () => Category::query()->wherePathContains(''))
        ->toThrow(LtreeException::class);
});
