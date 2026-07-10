<?php

declare(strict_types=1);

use Happenv\Ltree\Tests\DatabaseTestCase;
use Happenv\Ltree\Tests\Fixtures\Category;
use Happenv\Ltree\Tests\Fixtures\UuidCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function () {
    Schema::create('categories', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
        $table->ltree('path')->nullable();
    });
    Schema::create('uuid_categories', function ($table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('parent_id')->nullable();
        $table->ltree('path'); // NOT NULL — uuid label is known during creating
    });
});

it('auto-sets path from an auto-increment key via deferral', function () {
    $root = Category::create([]);
    $child = Category::create(['parent_id' => $root->id]);
    $grand = Category::create(['parent_id' => $child->id]);

    expect($root->path()->toString())->toBe((string) $root->id)
        ->and($child->path()->toString())->toBe($root->id.'.'.$child->id)
        ->and($grand->path()->toString())->toBe($root->id.'.'.$child->id.'.'.$grand->id);

    // persisted, not just in memory
    expect(Category::find($grand->id)->path()->toString())->toBe($root->id.'.'.$child->id.'.'.$grand->id);
});

it('auto-sets path during creating for a pre-known (uuid) label', function () {
    $root = UuidCategory::create([]);
    $child = UuidCategory::create(['parent_id' => $root->id]);

    $rootLabel = $root->getLtreeLabel();
    $childLabel = $child->getLtreeLabel();

    expect($root->path()->toString())->toBe($rootLabel)
        ->and($child->path()->toString())->toBe($rootLabel.'.'.$childLabel);
});

it('does not auto-set path when auto_update_path is disabled', function () {
    config()->set('ltree.auto_update_path', false);
    $c = Category::create(['path' => 'explicit']);
    expect($c->path()->toString())->toBe('explicit');
});

it('does not compute a path during creating() when disabled, even for a pre-known (uuid) label', function () {
    // UuidCategory's key is assigned before LtreeObserver::creating() runs (see
    // "auto-sets path during creating" above), so — unlike the autoincrement
    // Category above — it never hits the auto-increment deferral's own early
    // return. This isolates creating()'s `! $this->enabled()` guard: only that
    // check keeps the explicit path from being overwritten by a computed one.
    config()->set('ltree.auto_update_path', false);
    $c = UuidCategory::create(['path' => 'explicit']);
    expect($c->path()->toString())->toBe('explicit');
});

it('does not compute a path in created() when disabled and the key was deferred', function () {
    // Category's autoincrement key is null during creating(), so — regardless
    // of auto_update_path — creating() always defers to created() without
    // setting a path (see labelIsKey deferral). This isolates created()'s own
    // `! $this->enabled()` guard: only that check keeps it from computing and
    // persisting a path once the key becomes available.
    config()->set('ltree.auto_update_path', false);
    $c = Category::create([]);

    expect($c->path())->toBeNull()
        ->and(Category::find($c->id)->getAttribute('path'))->toBeNull();
});

it('defaults auto_update_path to enabled when the config key is entirely absent', function () {
    // config('ltree.auto_update_path', true)'s own default argument only
    // matters when the key is truly absent — mergeConfigFrom() always merges
    // the package default in, so the key is never actually absent in normal
    // operation. Remove it directly to reach that argument.
    config(['ltree' => Arr::except(config('ltree'), ['auto_update_path'])]);
    expect(array_key_exists('auto_update_path', config('ltree')))->toBeFalse();

    $c = Category::create([]);
    expect($c->path())->not->toBeNull();
});

it('coerces a non-boolean auto_update_path config value to boolean', function () {
    // enabled()'s (bool) cast matters at the *type* level, not just at the
    // truthiness level: this file declares strict_types=1, and enabled() is
    // typed to return bool, so without the cast, returning a non-bool config
    // value (e.g. an int) would throw a TypeError instead of coercing.
    config()->set('ltree.auto_update_path', 1);
    $c = Category::create([]);
    expect($c->path())->not->toBeNull();
});

it('syncs the parent column from a transient setLtreeParent() parent when the column is unset', function () {
    $root = Category::create([]);

    $child = new Category;
    $child->setLtreeParent($root)->save();

    expect($child->parent_id)->toBe($root->id)
        ->and($child->path()->toString())->toBe($root->id.'.'.$child->id);
});
