<?php

declare(strict_types=1);

namespace Happenv\Ltree\Normalization;

use Happenv\Ltree\Contracts\Normalizer;
use Happenv\Ltree\Exceptions\InvalidLabelException;

final readonly class DefaultNormalizer implements Normalizer
{
    /**
     * Conservative ltree label charset. PostgreSQL 16+ may relax this
     * (spec §12 verify item #1) — change here only, in one place.
     */
    private const string ILLEGAL = '/[^A-Za-z0-9_]/';

    /**
     * @param  'replace'|'throw'  $strategy
     * @param  array<string, string>  $replacements
     */
    public function __construct(
        private string $strategy = 'replace',
        private array $replacements = ['-' => '_'],
    ) {}

    public function normalize(int|string $value): string
    {
        $label = (string) $value;

        if ($label === '') {
            throw new InvalidLabelException('An ltree label cannot be empty.');
        }

        $label = strtr($label, $this->replacements);

        if (preg_match(self::ILLEGAL, $label) === 1) {
            if ($this->strategy === 'throw') {
                throw new InvalidLabelException(
                    sprintf('Value [%s] is not a valid ltree label.', $value),
                );
            }

            // The (string) cast satisfies PHPStan's preg_replace() stub, which
            // types the return as string|null. It is not behaviorally
            // reachable: this subject is always a `string` and the pattern is
            // a fixed, non-backtracking negated character class, so PCRE
            // cannot fail here (verified: forcing pcre.backtrack_limit=0 and
            // pcre.recursion_limit=0 against a 2M-char pathological subject
            // still returns a string, never null) — no test can force the
            // null branch this cast guards against.
            $label = (string) preg_replace(self::ILLEGAL, '_', $label); // @pest-mutate-ignore: RemoveStringCast
        }

        if ($label === '') {
            throw new InvalidLabelException(
                sprintf('Value [%s] normalized to an empty ltree label.', $value),
            );
        }

        return $label;
    }
}
