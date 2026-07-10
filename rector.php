<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\FuncCall\ThrowIfAndThrowUnlessExceptionsToUseClassStringRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests/Fixtures',
    ])
    // No argument on purpose: Rector reads the target PHP version from the
    // "php" constraint in composer.json (currently ^8.3), so the package and
    // its Rector rules always track the minimum supported PHP version — bump
    // the composer constraint and Rector follows automatically.
    ->withPhpSets()
    // Refactoring only — code style/formatting stays Pint's job.
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    ->withImportNames(importShortClasses: false)
    ->withSkip([
        // Keep explicit exception guards as `throw new X(...)` rather than the
        // throw_if()/throw_unless() helpers — the guards are mutation-tested
        // (several carry `@pest-mutate-ignore` justifications), so clarity and
        // stable coverage beat the one-liner. (The if/throw variant lives in the
        // LARAVEL_IF_HELPERS set, which is deliberately not enabled above.)
        ThrowIfAndThrowUnlessExceptionsToUseClassStringRector::class,

        // Never demote a public static method to an instance method: the static
        // support facade (Happenv\Ltree\Support\Ltree), LtreeExtension::exists()
        // and the schema macros are public API — this rule would break callers.
        LocallyCalledStaticMethodToNonStaticRector::class,

        // Keep app() — resolve() is an equivalent, less common alias; no win.
        AppToResolveRector::class,

        // Keep the explicit `=== null` guards in the tree-operations trait: these
        // methods compare two nullable models, and the `! $x instanceof Model`
        // form Rector prefers introduces a provably-equivalent InstanceOfToTrue
        // mutant that the covered-score-100 gate can neither kill nor cleanly
        // ignore (a line-level ignore would also mask a genuinely killable
        // sibling mutant). `=== null` is clearer here and stays mutation-clean.
        FlipTypeControlToUseExclusiveTypeRector::class => [
            __DIR__.'/src/Concerns/PerformsLtreeOperations.php',
        ],
    ]);
