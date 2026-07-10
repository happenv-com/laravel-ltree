<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests\Fixtures;

use Happenv\Ltree\Testing\HasLtreeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FactoryCategory>
 */
class FactoryCategoryFactory extends Factory
{
    use HasLtreeFactory;

    /** @var class-string<FactoryCategory> */
    protected $model = FactoryCategory::class;

    /**
     * `path`/`parent_id` are entirely managed by HasLtree/LtreeObserver
     * (parent_id via setLtreeParent(), path computed on save) — `name` is
     * the only attribute left for the factory to fake.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'default-category',
        ];
    }
}
