<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveMixedDocblockOverruledByNativeTypeRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
        __DIR__ . '/fixtures',
    ])
    ->withSkip([
        // The bare directory pattern alone doesn't reliably skip the FILES inside it -- fnmatch() needs
        // an exact string match, and a per-file path has a filename trailing the directory this pattern
        // matches. Confirmed empirically on the sibling search-ranking package: this let
        // RemoveUselessReturnTagRector reach into regenerated *TesterActions.php files there. Both forms
        // kept since which one actually matches depends on how the caller passes the path.
        __DIR__ . '/tests/*/_support/_generated',
        __DIR__ . '/tests/*/_support/_generated/*',
        __DIR__ . '/tests/*/_output',
        __DIR__ . '/tests/*/_data',
        // Freshly generated each CI run for the standalone portable-tests job, gitignored, never
        // committed — same reason src/Generated/ is never touched in a real Spryker project either.
        __DIR__ . '/src/Generated',
        __DIR__ . '/src/Generated/*',
        // Checked-in, verbatim Spryker CORE generated fixture (see its own docblock) — must stay
        // byte-identical to core's own generator output, never rector'd.
        __DIR__ . '/tests/_ci-standalone/Generated',
        __DIR__ . '/tests/_ci-standalone/Generated/*',
        // Spryker.Commenting.DocBlockVar (active in this project's phpcs.xml) requires a @var doc block
        // on every property. Promoting a property into the constructor signature deletes its standalone
        // declaration -- and the @var block that sat above it -- with nowhere left to reattach one,
        // producing "Doc Block annotation @var for property missing" across 14 files when tried. Same
        // systemic contradiction search-debug hit with RemoveUselessParamTagRector, different sniff.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // Spryker.Commenting.DocBlockParam (active in this project's phpcs.xml) requires exactly one
        // @param tag per method parameter, typed or not. This rule strips @param tags for natively
        // typed params, which produced 215 "Doc Block params do not match method signature" errors when
        // tried -- same systemic contradiction search-debug hit with this exact rule.
        RemoveUselessParamTagRector::class,
        // Same DocBlockParam contradiction as above, narrower trigger: strips a single @param mixed
        // when the parameter is natively typed mixed, still breaking the required 1:1 count. Same rule,
        // same reasoning, already skipped in the sibling search-debug package.
        RemoveMixedDocblockOverruledByNativeTypeRector::class,
        // Rewrites plain === null / !== null checks on a nullable single-class type into
        // instanceof \Fully\Qualified\ClassName -- strictly more verbose for a simple null check, breaks
        // this codebase's consistent === null idiom used everywhere else, and writes an inline FQCN
        // instead of a use import, tripping Spryker.Namespaces.UseStatement. Identical finding to
        // search-debug's.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ])
    // Picks up the PHP floor (>=8.3) from composer.json.
    ->withPhpSets()
    ->withDeadCodeLevel(69)
    ->withCodeQualityLevel(75)
    ->withoutParallel();
