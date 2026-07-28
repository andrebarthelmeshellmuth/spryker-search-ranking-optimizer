<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

/**
 * Verifies that this package's declared composer floors are REAL rather than guessed.
 *
 * Run via `composer check-floors`, which first resolves every declared constraint down to its oldest
 * allowed version (`--prefer-lowest --prefer-stable --no-dev`) and then executes this script against
 * that tree. Any symbol `src/` references but which does not exist at those versions means a declared
 * floor is too low — the package would fatal on a shop that legitimately installed the versions we claim
 * to support. Mirrors spryker-community/search-ranking's own floor-check (which itself mirrors
 * spryker-community/search-debug's, the first one written, which found 6 undeclared dependencies the very
 * first time it was run — the classic community-package bug: works in the monorepo it was developed in,
 * breaks on a real `composer require` in a leaner shop).
 *
 * Production dependencies only (`--no-dev`): dev tooling is irrelevant to what an adopter installs.
 */

$packageDir = dirname(__DIR__);

if (!is_file($packageDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "No vendor/autoload.php — run `composer check-floors` rather than this script directly.\n");

    exit(1);
}

require $packageDir . '/vendor/autoload.php';

/**
 * This package's OWN module prefixes — deliberately NOT the whole shared `SprykerCommunity\` root.
 * `spryker-community/search-ranking` is a real `require` and lives under that identical namespace root
 * (`SprykerCommunity\Client\SearchRanking\`, `SprykerCommunity\Zed\SearchRanking\`, ...), so a broad
 * `SprykerCommunity\` "own code, skip" prefix would silently skip verifying search-ranking's own symbols
 * too — exactly the undeclared-dependency bug this script exists to catch, just hidden by an overly
 * generous prefix. List this package's own module names explicitly so anything under
 * `SprykerCommunity\*\SearchRanking\` (a different module) falls through to the normal class_exists()
 * check below instead of being waved through.
 *
 * @var array<string>
 */
const OWN_MODULE_PREFIXES = [
    'SprykerCommunity\\Client\\SearchRankingOptimizer\\',
    'SprykerCommunity\\Zed\\SearchRankingOptimizer\\',
    'SprykerCommunity\\Shared\\SearchRankingOptimizer\\',
];

/**
 * Generated at build time by the host project — `Generated\` by `transfer:generate`, `Orm\` by
 * `propel:install`/`propel:model:build` from this package's OWN shipped schema.xml (merged with the
 * host's other schemas) — never shipped in any vendor tree, so their absence here is expected and says
 * nothing about dependency floors.
 *
 * @var array<string>
 */
const HOST_GENERATED_PREFIXES = [
    'Generated\\',
    'Orm\\',
];

$usedSymbols = [];
$sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir . '/src'));

foreach ($sourceFiles as $sourceFile) {
    if (!$sourceFile->isFile() || $sourceFile->getExtension() !== 'php') {
        continue;
    }

    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', (string)file_get_contents($sourceFile->getPathname()), $matches);

    foreach ($matches[1] as $symbol) {
        $usedSymbols[$symbol] = true;
    }
}

ksort($usedSymbols);

$missing = [];
$hostGenerated = 0;
$ownCode = 0;
$resolved = 0;

foreach (array_keys($usedSymbols) as $symbol) {
    $isOwnCode = false;

    foreach (OWN_MODULE_PREFIXES as $ownPrefix) {
        if (str_starts_with($symbol, $ownPrefix)) {
            $isOwnCode = true;

            break;
        }
    }

    if ($isOwnCode) {
        $ownCode++;

        continue;
    }

    $isHostGenerated = false;

    foreach (HOST_GENERATED_PREFIXES as $hostGeneratedPrefix) {
        if (str_starts_with($symbol, $hostGeneratedPrefix)) {
            $isHostGenerated = true;

            break;
        }
    }

    if ($isHostGenerated) {
        $hostGenerated++;

        continue;
    }

    if (class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol)) {
        $resolved++;

        continue;
    }

    $missing[] = $symbol;
}

printf(
    "floor-check: %d resolved | %d host-generated (skipped) | %d own-module (skipped) | %d MISSING\n",
    $resolved,
    $hostGenerated,
    $ownCode,
    count($missing),
);

foreach ($missing as $symbol) {
    echo "  MISSING: $symbol\n";
}

if ($missing !== []) {
    fwrite(STDERR, "\nA declared floor is too low, or a dependency is undeclared entirely.\n");

    exit(1);
}

echo "All required symbols exist at the lowest allowed dependency versions.\n";

exit(0);
