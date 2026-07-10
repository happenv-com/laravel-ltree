<?php

declare(strict_types=1);

namespace Happenv\Ltree\Query;

use Happenv\Ltree\Builders\LtreeBuilder;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent path-constraint composer handed to a `LtreeBuilder::wherePath()`
 * closure. Each verb delegates to the inner grouped `LtreeBuilder` (all
 * constraints AND-compose inside one parenthesised WHERE) and returns `$this`
 * for chaining. It adds no SQL of its own — it is pure sugar over the builder
 * predicates from phases 04/06.
 *
 * @template TModel of Model
 */
final readonly class PathConstraint
{
    /**
     * @param  LtreeBuilder<TModel>  $query
     */
    public function __construct(private LtreeBuilder $query) {}

    public function descendantOf(Model|LtreePath|string $node): static
    {
        $this->query->whereDescendantOf($node);

        return $this;
    }

    public function ancestorOf(Model|LtreePath|string $node): static
    {
        $this->query->whereAncestorOf($node);

        return $this;
    }

    public function childOf(Model|LtreePath|string $node): static
    {
        $this->query->whereChildOf($node);

        return $this;
    }

    public function parentOf(Model|LtreePath|string $node): static
    {
        $this->query->whereParentOf($node);

        return $this;
    }

    public function siblingOf(Model|LtreePath|string $node): static
    {
        $this->query->whereSiblingOf($node);

        return $this;
    }

    public function root(): static
    {
        $this->query->whereRoot();

        return $this;
    }

    public function leaf(): static
    {
        $this->query->whereLeaf();

        return $this;
    }

    public function depth(int $depth): static
    {
        $this->query->whereDepth($depth);

        return $this;
    }

    public function betweenDepth(int $min, int $max): static
    {
        $this->query->whereBetweenDepth($min, $max);

        return $this;
    }

    public function maxDepth(int $depth): static
    {
        $this->query->maxDepth($depth);

        return $this;
    }

    public function minDepth(int $depth): static
    {
        $this->query->minDepth($depth);

        return $this;
    }

    public function withinDepth(Model|LtreePath|string $node, int $levels): static
    {
        $this->query->whereWithinDepthOf($node, $levels);

        return $this;
    }

    public function startsWith(Model|LtreePath|string $prefix): static
    {
        $this->query->wherePathStartsWith($prefix);

        return $this;
    }

    public function endsWith(string $suffix): static
    {
        $this->query->wherePathEndsWith($suffix);

        return $this;
    }

    public function segment(string $label): static
    {
        $this->query->whereSegment($label);

        return $this;
    }

    public function matches(string $lquery): static
    {
        $this->query->wherePathMatches($lquery);

        return $this;
    }

    /**
     * @param  array<int, string>  $lqueries
     */
    public function matchesAny(array $lqueries): static
    {
        $this->query->wherePathMatchesAny($lqueries);

        return $this;
    }

    public function contains(string $word): static
    {
        $this->query->wherePathContains($word);

        return $this;
    }

    /**
     * @param  array<int, string>  $words
     */
    public function containsAll(array $words): static
    {
        $this->query->wherePathContainsAll($words);

        return $this;
    }

    /**
     * @param  array<int, string>  $words
     */
    public function containsAny(array $words): static
    {
        $this->query->wherePathContainsAny($words);

        return $this;
    }
}
