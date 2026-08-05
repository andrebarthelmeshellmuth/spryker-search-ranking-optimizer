<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
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
        // Rewrites plain === null / !== null checks on a nullable single-class type into
        // instanceof \Fully\Qualified\ClassName -- strictly more verbose for a simple null check, breaks
        // this codebase's consistent === null idiom used everywhere else, and writes an inline FQCN
        // instead of a use import, tripping Spryker.Namespaces.UseStatement. Identical finding to
        // search-debug's.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ])
    // Picks up the PHP floor (>=8.3) from composer.json.
    ->withPhpSets()
    // Both at the real ceiling for the installed rector/rector version (2.0.0, older than the sibling
    // packages' 2.5.8 — smaller rule sets): DeadCodeLevel::RULES has 46 entries (max index 45) and
    // CodeQualityLevel::RULES has 75 (max index 74) — level numbers above either ceiling are silently
    // clamped to it by Rector's own LevelRulesResolver, so writing a higher number here would just be
    // inaccurate, not more aggressive.
    ->withDeadCodeLevel(45)
    ->withCodeQualityLevel(74)
    ->withoutParallel();
