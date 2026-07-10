<?php

declare(strict_types=1);

namespace Happenv\Ltree\ValueObjects;

use ArrayIterator;
use Countable;
use Happenv\Ltree\Exceptions\LtreeException;
use IteratorAggregate;
use JsonSerializable;
use Stringable;

/**
 * Immutable, framework-independent ltree path value object.
 *
 * Operates only on path segments. It knows nothing about Laravel, Eloquent,
 * PostgreSQL, PDO, SQL, or ltree functions. Mutating-looking methods return
 * new instances.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class LtreePath implements Countable, IteratorAggregate, JsonSerializable, Stringable
{
    /** @var list<string> */
    private array $segments;

    /**
     * @param  string|iterable<int|string, string|int>|LtreePath  $path
     */
    public function __construct(string|iterable|LtreePath $path)
    {
        $this->segments = self::normalizeInput($path);
    }

    /**
     * @param  string|iterable<int|string, string|int>|LtreePath  $path
     */
    public static function of(string|iterable|LtreePath $path): self
    {
        return new self($path);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param non-empty-string $separator */
    public static function explode(string $path, string $separator = '.'): self
    {
        return new self($path === '' ? [] : explode($separator, $path));
    }

    /** @return list<string> */
    public function segments(): array
    {
        return [...$this->segments];
    }

    /** @return int<0, max> */
    public function depth(): int
    {
        return count($this->segments);
    }

    public function parent(): ?self
    {
        if ($this->depth() <= 1) {
            return null;
        }

        return new self(array_slice($this->segments, 0, -1));
    }

    public function root(): self
    {
        if ($this->segments === []) {
            throw new LtreeException('An empty ltree path has no root.');
        }

        return new self([$this->segments[0]]);
    }

    public function first(): ?string
    {
        return $this->segments[0] ?? null;
    }

    public function last(): ?string
    {
        return $this->segments === [] ? null : $this->segments[array_key_last($this->segments)];
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->segments, $offset, $length));
    }

    public function append(string|self $suffix): self
    {
        return new self([...$this->segments, ...self::normalizeInput($suffix)]);
    }

    public function prepend(string|self $prefix): self
    {
        return new self([...self::normalizeInput($prefix), ...$this->segments]);
    }

    public function pop(): self
    {
        return new self(array_slice($this->segments, 0, -1));
    }

    public function shift(): self
    {
        return new self(array_slice($this->segments, 1));
    }

    public function implode(string $separator = '.'): string
    {
        return implode($separator, $this->segments);
    }

    public function count(): int
    {
        return $this->depth();
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    /** @return ArrayIterator<int, string> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->segments);
    }

    public function toString(): string
    {
        return implode('.', $this->segments);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function isRoot(): bool
    {
        return $this->depth() === 1;
    }

    public function contains(string $label): bool
    {
        return in_array($label, $this->segments, true);
    }

    public function startsWith(string|self $prefix): bool
    {
        $prefix = self::normalizeInput($prefix);

        return array_slice($this->segments, 0, count($prefix)) === $prefix;
    }

    public function endsWith(string|self $suffix): bool
    {
        $suffix = self::normalizeInput($suffix);

        if ($suffix === []) {
            return true;
        }

        return array_slice($this->segments, -count($suffix)) === $suffix;
    }

    public function equals(string|self $other): bool
    {
        return $this->segments === self::normalizeInput($other);
    }

    public function isAncestorOf(string|self $other): bool
    {
        $other = new self($other);

        return $other->depth() > $this->depth() && $other->startsWith($this);
    }

    public function isDescendantOf(string|self $other): bool
    {
        $other = new self($other);

        return $this->depth() > $other->depth() && $this->startsWith($other);
    }

    public function compareLexically(string|self $other): int
    {
        // `min()` bounds the loop to the shorter path so we never read past
        // either array; the depth tiebreak below decides equal-prefix cases.
        // Widening this bound (max()), the condition (<=), or the start (-1)
        // would read an out-of-bounds index — which this correct code never
        // does — so mutation testing kills those mutants via the E_WARNING
        // that the out-of-bounds read emits under failOnWarning.
        $other = self::normalizeInput($other);
        $min = min(count($this->segments), count($other));

        for ($i = 0; $i < $min; $i++) {
            $comparison = strcmp($this->segments[$i], $other[$i]) <=> 0;

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return count($this->segments) <=> count($other);
    }

    /**
     * @param  string|iterable<int|string, string|int>|LtreePath  $path
     * @return list<string>
     */
    private static function normalizeInput(string|iterable|LtreePath $path): array
    {
        // Provably equivalent mutant: this fast path is a pure optimization,
        // not a correctness branch. LtreePath implements IteratorAggregate,
        // so removing this early return (or forcing the condition false)
        // just falls through to the general `foreach` below — which iterates
        // $path's own (already-validated, non-empty, dot-free) segments and
        // rebuilds the identical array. No input can make the two paths
        // produce different output.
        if ($path instanceof self) { // @pest-mutate-ignore: InstanceOfToFalse
            return $path->segments; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (is_string($path)) {
            $path = $path === '' ? [] : explode('.', $path);
        }

        $segments = [];
        foreach ($path as $segment) {
            $segment = (string) $segment;

            if ($segment === '') {
                throw new LtreeException('An ltree path segment cannot be empty.');
            }

            if (str_contains($segment, '.')) {
                throw new LtreeException(
                    sprintf('An ltree path segment cannot contain the separator: [%s].', $segment),
                );
            }

            $segments[] = $segment;
        }

        return $segments;
    }
}
