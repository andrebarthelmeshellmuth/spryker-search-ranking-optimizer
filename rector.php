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
        __DIR__ . '/tests/*/_support/_generated',
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
    // Gradual levels (0 = safest rules only). Raising in batches; stop at the first hit that
    // conflicts with established Spryker style rather than applying it automatically.
    ->withDeadCodeLevel(70)
    ->withCodeQualityLevel(70)
    ->withoutParallel();
