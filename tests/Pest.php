<?php

declare(strict_types=1);
use Happenv\Ltree\Tests\TestCase;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Support\Container;

// Feature tests get the Testbench-backed base case; Unit tests stay pure-PHP.
// Scoped to direct children (non-recursive glob) so subdirectories can declare
// their own base case per-file (e.g. tests/Feature/Schema uses DatabaseTestCase)
// without Pest's "test case already in use" conflict — a directory-level
// `->in()` match and a file-level `uses()` match can't both apply to one file.
uses(TestCase::class)->in('Feature/*.php');

// Pest-native mutation testing (`composer mutate` -> `pest --mutate`): mutate
// the whole `src/` value layer, only mutate lines that are actually covered
// by a test, and require a covered-mutation score of 100 (proof every
// reachable branch is asserted on, not just executed).
//
// `pest-plugin-mutate` has no public global-config helper: the top-level
// `mutates()` function (vendor/pestphp/pest/src/Functions.php) is a
// per-test-file scoping call — it returns void, so it can't be chained with
// ->path()/->coveredOnly()/->min(). The fluent Configuration contract
// (vendor/pestphp/pest-plugin-mutate/src/Contracts/Configuration.php) is
// reached globally through the plugin's own ConfigurationRepository
// singleton — the same object `mutates()` mutates internally
// (`$configurationRepository->globalConfiguration('default')->class(...)`,
// see Repositories/ConfigurationRepository.php@globalConfiguration()).
//
// ->everything() is required: Mutate::addOutput() refuses to run (exit 1,
// "Mutation testing requires the usage of the `covers()` function or
// `mutates()` function") unless CLI --path/--class was passed, per-test
// covers()/mutates() populated $configuration->classes, or ->everything()
// is set — see the plugin's own suggested command in that error message:
// `pest --mutate --parallel --everything --covered-only`.
Container::getInstance()->get(ConfigurationRepository::class)
    ->globalConfiguration()
    ->everything()
    ->path('src')
    ->coveredOnly()
    ->min(100);
