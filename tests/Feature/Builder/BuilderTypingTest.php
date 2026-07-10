<?php

declare(strict_types=1);

use Happenv\Ltree\Builders\LtreeBuilder;
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

it('returns an LtreeBuilder from the model', function () {
    expect(Category::query())->toBeInstanceOf(LtreeBuilder::class)
        ->and((new Category)->newQuery())->toBeInstanceOf(LtreeBuilder::class);
});
