<?php

declare(strict_types=1);

namespace Happenv\Ltree\Contracts;

use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Model;

/**
 * Describes the subset of the `HasLtree` trait's public surface that
 * `LtreeObserver` and `PerformsLtreeOperations` depend on.
 *
 * PHPStan cannot intersect a class type with a trait — a trait is not a
 * valid `instanceof` target — so `Model&HasLtree` is an unresolvable type.
 * This interface exists purely so the observer (and the operations trait,
 * when narrowing a foreign `Model $param` rather than `$this`) can narrow
 * `Model $model` to `Model&Ltreeable` for static analysis. It is never
 * `implements`-ed by consuming models: `HasLtree` remains a zero-config,
 * interface-free trait.
 *
 * @internal
 */
interface Ltreeable
{
    public function getLtreeParent(): ?Model;

    public function getLtreeParentColumn(): ?string;

    public function getLtreePathColumn(): string;

    public function getLtreeLabel(): string;

    public function getLtreeLabelDependsOnKey(): bool;

    public function path(): ?LtreePath;

    public function setLtreeParent(?Model $parent): static;

    public function rebuildPaths(): int;
}
