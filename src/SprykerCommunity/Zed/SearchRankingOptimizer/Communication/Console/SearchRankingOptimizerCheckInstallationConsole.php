<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console;

use Generated\Shared\Transfer\PermissionCollectionTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfigQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRunQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRatingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationSearchTermQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpointQuery;
use SimpleXMLElement;
use Spryker\Shared\Config\Config;
use Spryker\Shared\Kernel\KernelConstants;
use Spryker\Zed\Kernel\ClassResolver\Config\BundleConfigResolver;
use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Diagnoses a search-ranking-optimizer installation.
 *
 * Mirrors spryker-community/search-ranking's own `search-ranking:check-installation` — this package's
 * README installation section has 7 numbered steps (plus 3a/3b sub-steps), and almost every one of them
 * fails SILENTLY when missed: a forgotten DependencyProvider wire-up produces no error, just a feature
 * that quietly never does anything (a permission nobody can ever be granted, a widget button with no
 * translated label). This checks every prerequisite reachable from the CLI and names the exact remedy for
 * whatever is wrong.
 *
 * Deliberately honest about its own limits, same posture as
 * {@see \SprykerCommunity\Zed\SearchRanking\Communication\Console\SearchRankingCheckInstallationConsole}:
 * it cannot confirm the Yves-side route-provider/Twig-plugin registration (step 3b) or that the widget
 * actually renders and submits correctly on a live storefront page — those need a real browser request to
 * verify, not a CLI probe.
 *
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 */
class SearchRankingOptimizerCheckInstallationConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-ranking-optimizer:check-installation';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Diagnoses a search-ranking-optimizer installation: core namespace, sibling console command registration, the SRP-rating permission plugin (Zed and Client), Yves glossary key, Zed translations, and the 8 Propel tables this package ships.';

    /**
     * @var string
     */
    protected const CORE_NAMESPACE = 'SprykerCommunity';

    /**
     * The other console commands this package registers — step 3 of the README's installation section.
     *
     * @var array<string>
     */
    protected const SIBLING_COMMANDS = [
        'search-ranking-optimizer:calibrate',
        'search-ranking-optimizer:auto-tune',
        'search-ranking-optimizer:optimize',
    ];

    /**
     * A stable key from this package's own `data/glossary.csv` (step 5, Yves half).
     *
     * @var string
     */
    protected const KNOWN_GLOSSARY_KEY = 'search_ranking_optimizer.rate.heart';

    /**
     * A stable, page-heading-level string from this package's own `data/translation/Zed/en_US.csv`
     * (step 5, Zed half) — unlikely to be casually reworded, unlike a button label.
     *
     * @var string
     */
    protected const KNOWN_ZED_TRANSLATION_KEY = 'Search Ranking Saturation Point Calibration';

    /**
     * @var string
     */
    protected const KNOWN_ZED_TRANSLATION_LOCALE = 'en_US';

    /**
     * The 8 Propel tables this package's own schema ships (step 6), label => Query class. Queried
     * directly via their generated Query classes (this package's own public API, not a `Pyz\*` reference)
     * rather than through any business-layer facade — existence/reachability is all that matters here.
     *
     * @var array<string, class-string<\Propel\Runtime\ActiveQuery\ModelCriteria>>
     */
    protected const PROPEL_TABLES = [
        'spy_search_ranking_saturation_point_calibration' => SpySearchRankingSaturationPointCalibrationQuery::class,
        'spy_search_ranking_saturation_point_calibration_search_term' => SpySearchRankingSaturationPointCalibrationSearchTermQuery::class,
        'spy_search_ranking_query' => SpySearchRankingQueryQuery::class,
        'spy_search_ranking_query_rating' => SpySearchRankingQueryRatingQuery::class,
        'spy_search_ranking_evaluation' => SpySearchRankingEvaluationQuery::class,
        'spy_search_ranking_weight_checkpoint' => SpySearchRankingWeightCheckpointQuery::class,
        'spy_search_ranking_auto_tune_metric_config' => SpySearchRankingAutoTuneMetricConfigQuery::class,
        'spy_search_ranking_optimizer_run' => SpySearchRankingOptimizerRunQuery::class,
    ];

    /**
     * The commands this package expects a project to have put on a cron schedule.
     *
     * @var array<string>
     */
    protected const CRON_COMMANDS = [
        'search-ranking-optimizer:calibrate',
        'search-ranking-optimizer:auto-tune',
        'search-ranking-optimizer:optimize',
    ];

    /**
     * Referenced as a string, never imported: spryker/symfony-scheduler is a `suggest`, not a
     * requirement, so this package must stay loadable without it.
     *
     * @var string
     */
    protected const SCHEDULER_CONFIG_CLASS = 'Spryker\\Zed\\SymfonyScheduler\\SymfonySchedulerConfig';

    /**
     * This package's own navigation.xml, relative to this console's directory — the source of truth for
     * which page keys a project is expected to have copied.
     *
     * @var string
     */
    protected const OWN_NAVIGATION_XML_RELATIVE_PATH = '/../navigation.xml';

    /**
     * @var array<string>
     */
    protected array $failures = [];

    /**
     * @var array<string>
     */
    protected array $warnings = [];

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);

        parent::configure();
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $input is mandated by the Console base class.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkCoreNamespace($output);
        $this->checkSiblingCommandsRegistered($output);
        $this->checkPermissionPluginRegistered($output);
        $this->checkGlossaryKeyRegistered($output);
        $this->checkZedTranslationRegistered($output);
        $this->checkPropelTablesExist($output);
        $this->checkCronJobsRegistered($output);
        $this->checkNavigationRegistered($output);

        $output->writeln('');

        foreach ($this->warnings as $warning) {
            $output->writeln(sprintf('<comment>! %s</comment>', $warning));
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $output->writeln(sprintf('<error>✗ %s</error>', $failure));
            }

            return static::CODE_ERROR;
        }

        $output->writeln('<info>Everything checkable from the CLI is in place.</info>');
        $output->writeln('Not verifiable from here — these need a real browser request, not a CLI probe:');
        $output->writeln('  - the Yves route-provider and Twig plugins are registered (step 3b)');
        $output->writeln('  - the rating widget actually renders below product tiles and submits successfully');

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkCoreNamespace(OutputInterface $output): void
    {
        $coreNamespaces = Config::get(KernelConstants::CORE_NAMESPACES, []);

        if (in_array(static::CORE_NAMESPACE, $coreNamespaces, true)) {
            $output->writeln(sprintf('<info>✓</info> core namespace "%s" is registered', static::CORE_NAMESPACE));

            return;
        }

        $this->failures[] = sprintf(
            'Core namespace "%s" is NOT registered. Add it to KernelConstants::CORE_NAMESPACES in config/Shared/config_default.php — without it Spryker cannot resolve any of this package\'s classes.',
            static::CORE_NAMESPACE,
        );
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkSiblingCommandsRegistered(OutputInterface $output): void
    {
        /** @var \Symfony\Component\Console\Application|null $application */
        $application = $this->getApplication();

        if ($application === null) {
            $this->warnings[] = 'Could not access the console Application instance — skipping sibling command checks.';

            return;
        }

        $missingCommands = [];

        foreach (static::SIBLING_COMMANDS as $commandName) {
            if ($application->has($commandName)) {
                continue;
            }

            $missingCommands[] = $commandName;
        }

        if ($missingCommands === []) {
            $output->writeln(sprintf('<info>✓</info> all %d sibling console commands are registered', count(static::SIBLING_COMMANDS)));

            return;
        }

        $this->failures[] = sprintf(
            'The following console commands are NOT registered: %s. Add them in ConsoleDependencyProvider::getConsoleCommands() (README step 3).',
            implode(', ', $missingCommands),
        );
    }

    /**
     * Verifies RateSearchRelevancePermissionPlugin is registered on BOTH sides (README step 3a). The
     * Client half is checked directly via {@see \Spryker\Client\Permission\PermissionClientInterface::getRegisteredPermissions()}
     * (purely local — see the Client bridge's docblock for why the tempting "merged" method isn't used).
     * The Zed half is checked via {@see \Spryker\Zed\Permission\Business\PermissionFacadeInterface::findMergedRegisteredNonInfrastructuralPermissions()}
     * — also safe/local (Zed's own merge internally calls the Client's local method too, never a network
     * one) — which returns the UNION of both sides. Combining the two: if the union is missing the key,
     * NEITHER side has it; if the union has it but the Client-only check doesn't, Zed has it and Client
     * doesn't. Either gap independently makes the rating widget silently ungrantable/invisible.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkPermissionPluginRegistered(OutputInterface $output): void
    {
        $clientPermissionKeys = $this->extractPermissionKeys(
            $this->getFactory()->getPermissionClient()->getRegisteredPermissions(),
        );
        $clientHasPlugin = in_array(RateSearchRelevancePermissionPlugin::KEY, $clientPermissionKeys, true);

        $unionPermissionKeys = $this->extractPermissionKeys(
            $this->getFactory()->getPermissionFacade()->findMergedRegisteredNonInfrastructuralPermissions(),
        );
        $eitherSideHasPlugin = in_array(RateSearchRelevancePermissionPlugin::KEY, $unionPermissionKeys, true);

        if ($clientHasPlugin && $eitherSideHasPlugin) {
            $output->writeln('<info>✓</info> the SRP-rating permission plugin is registered on both Zed and Client');

            return;
        }

        if (!$eitherSideHasPlugin) {
            $this->failures[] = 'RateSearchRelevancePermissionPlugin is NOT registered on Zed OR Client (README step 3a). Without both sides, the permission can never be granted to any company role, so the rating widget stays invisible for every customer.';

            return;
        }

        $this->failures[] = 'RateSearchRelevancePermissionPlugin is registered on Zed but NOT on Client (README step 3a) — add it to Pyz\Client\Permission\PermissionDependencyProvider::getPermissionPlugins() too. Without the Client half, canRateSearchRelevance() can never resolve true, so the rating widget stays invisible for every customer even with the permission granted.';
    }

    /**
     * @param \Generated\Shared\Transfer\PermissionCollectionTransfer $permissionCollectionTransfer
     *
     * @return array<string>
     */
    protected function extractPermissionKeys(PermissionCollectionTransfer $permissionCollectionTransfer): array
    {
        $keys = [];

        foreach ($permissionCollectionTransfer->getPermissions() as $permissionTransfer) {
            $key = $permissionTransfer->getKey();

            if ($key === null) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Confirms the project imported this package's Yves glossary key (README step 5, Yves half) via
     * {@see \Spryker\Zed\Glossary\Business\GlossaryFacadeInterface::hasKey()}.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkGlossaryKeyRegistered(OutputInterface $output): void
    {
        if ($this->getFactory()->getGlossaryFacade()->hasKey(static::KNOWN_GLOSSARY_KEY)) {
            $output->writeln('<info>✓</info> the Yves glossary key is imported');

            return;
        }

        $this->failures[] = sprintf(
            'Glossary key "%s" does not exist. Run `vendor/bin/console data:import glossary` (README step 5) after installing this package.',
            static::KNOWN_GLOSSARY_KEY,
        );
    }

    /**
     * Confirms the project loaded this package's own Zed translation catalog (README step 5, Zed half)
     * via {@see \Spryker\Zed\Translator\Business\TranslatorFacadeInterface::has()}.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkZedTranslationRegistered(OutputInterface $output): void
    {
        if ($this->getFactory()->getTranslatorFacade()->has(static::KNOWN_ZED_TRANSLATION_KEY, static::KNOWN_ZED_TRANSLATION_LOCALE)) {
            $output->writeln('<info>✓</info> the Zed GUI translation catalog is loaded');

            return;
        }

        $this->failures[] = sprintf(
            'The Zed translation catalog does not resolve "%s". Add the spryker-community/* glob to Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns() (README step 5), then run translator:clean-cache and translator:generate-cache.',
            static::KNOWN_ZED_TRANSLATION_KEY,
        );
    }

    /**
     * Confirms all 8 of this package's own Propel tables (README step 6) actually exist and are
     * queryable — a project that never ran `propel:install`/`propel:migrate` after requiring this
     * package gets a hard PHP fatal the first time any page touches one of these tables, rather than a
     * graceful error; this surfaces that up front with a clear remedy.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkPropelTablesExist(OutputInterface $output): void
    {
        $missingTables = [];

        foreach (static::PROPEL_TABLES as $tableName => $queryClass) {
            try {
                $query = new $queryClass();
                $query->count();
            } catch (Throwable) {
                $missingTables[] = $tableName;
            }
        }

        if ($missingTables === []) {
            $output->writeln(sprintf('<info>✓</info> all %d Propel tables exist and are queryable', count(static::PROPEL_TABLES)));

            return;
        }

        $this->failures[] = sprintf(
            'The following tables are missing or unreachable: %s. Run `vendor/bin/console propel:install` (or propel:migrate) after installing this package (README step 6).',
            implode(', ', $missingTables),
        );
    }

    /**
     * Cron jobs are the one integration step no package can perform for a project and nothing else
     * verifies: `SymfonySchedulerConfig::getCronJobs()` returns `[]` in Spryker core and has no plugin
     * stack at all, so a vendor package cannot contribute an entry — it is project config, copied by hand
     * from the README. Skipping it produces no error either, just a queued calibration or optimization run that sits in "queued" forever.
     *
     * Resolved through {@see BundleConfigResolver} rather than by naming `Pyz\Zed\...` directly: the
     * resolver is what picks a project's own override over core's empty default, and hardcoding the
     * project namespace would break the moment a project uses a different one.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkCronJobsRegistered(OutputInterface $output): void
    {
        $cronJobs = $this->findRegisteredCronJobs();

        if ($cronJobs === null) {
            $this->warnings[] = sprintf(
                'Could not read this project\'s cron registrations (spryker/symfony-scheduler is optional and may not be installed, or this project schedules jobs another way). Confirm by hand that these run periodically: %s.',
                implode(', ', static::CRON_COMMANDS),
            );

            return;
        }

        $registeredCommands = implode(' ', array_column($cronJobs, 'command'));
        $missingCommands = [];

        foreach (static::CRON_COMMANDS as $commandName) {
            if (str_contains($registeredCommands, $commandName)) {
                continue;
            }

            $missingCommands[] = $commandName;
        }

        if ($missingCommands === []) {
            $output->writeln('<info>✓</info> every cron job this package needs is registered');

            return;
        }

        $this->failures[] = sprintf(
            'These commands are NOT scheduled: %s. Add them to Pyz\Zed\SymfonyScheduler\SymfonySchedulerConfig::getCronJobs() (README step 7) — nothing registers them automatically, and leaving them unscheduled fails silently.',
            implode(', ', $missingCommands),
        );
    }

    /**
     * Null means "cannot tell" (module absent, or the resolved config does not expose cron jobs), which
     * is deliberately different from an empty array — the former is a warning, the latter a real failure.
     *
     * @return array<string, array<string, string>>|null
     */
    protected function findRegisteredCronJobs(): ?array
    {
        if (!class_exists(static::SCHEDULER_CONFIG_CLASS)) {
            return null;
        }

        try {
            $schedulerConfig = (new BundleConfigResolver())->resolve(static::SCHEDULER_CONFIG_CLASS);
        } catch (Throwable) {
            return null;
        }

        if (!method_exists($schedulerConfig, 'getCronJobs')) {
            return null;
        }

        return $schedulerConfig->getCronJobs();
    }

    /**
     * Zed navigation has no glob auto-discovery for `vendor/spryker-community/*`, so a project copies this
     * package's own `<search-ranking-optimizer-gui>` block into `config/Zed/navigation.xml` by hand — and a page added by a
     * later version of this package is easy to miss on upgrade. Neither omission errors: the entry is
     * simply absent from the sidebar, and a stale navigation cache hides a correct copy just as
     * completely as never copying it at all.
     *
     * The expected page keys are read from this package's OWN navigation.xml rather than hardcoded here,
     * so this check cannot drift from what the package actually ships.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkNavigationRegistered(OutputInterface $output): void
    {
        $expectedPageKeys = $this->readOwnNavigationPageKeys();
        $effectiveNavigation = $this->readEffectiveNavigation();

        if ($expectedPageKeys === [] || $effectiveNavigation === null) {
            $this->warnings[] = 'Could not compare this package\'s navigation entries against the project\'s own (neither the built navigation cache nor config/Zed/navigation.xml was readable). Confirm by hand that this package\'s pages appear in the Zed sidebar.';

            return;
        }

        [$sourceLabel, $registeredPageKeys] = $effectiveNavigation;
        $missingPageKeys = array_values(array_diff($expectedPageKeys, $registeredPageKeys));

        if ($missingPageKeys === []) {
            $output->writeln(sprintf('<info>✓</info> all %d navigation entries are registered (checked against %s)', count($expectedPageKeys), $sourceLabel));

            return;
        }

        $this->failures[] = sprintf(
            'These navigation entries are missing from %s: %s. First run "vendor/bin/console navigation:cache:remove && vendor/bin/console navigation:build-cache" — a stale cache hides a correct configuration just as completely, and is the cheaper cause to rule out. If they are still missing after that, copy the <search-ranking-optimizer-gui> block from this package\'s own src/SprykerCommunity/Zed/SearchRankingOptimizer/Communication/navigation.xml into config/Zed/navigation.xml (README step 4). A missing entry never errors — the page simply cannot be reached from the sidebar.',
            $sourceLabel,
            implode(', ', $missingPageKeys),
        );
    }

    /**
     * Every page key this package's own navigation.xml declares — the root entry plus each `<pages>`
     * child, including the ones marked `<visible>0</visible>` (invisible still means routable, and a
     * project that skipped them gets a dead link from the visible pages that point at them).
     *
     * @return array<string>
     */
    protected function readOwnNavigationPageKeys(): array
    {
        $ownNavigationXml = $this->loadXml(__DIR__ . static::OWN_NAVIGATION_XML_RELATIVE_PATH);

        if ($ownNavigationXml === null) {
            return [];
        }

        $pageKeys = [];

        foreach ($ownNavigationXml->children() as $rootEntry) {
            $pageKeys[] = $rootEntry->getName();

            foreach ($rootEntry->pages->children() as $page) {
                $pageKeys[] = $page->getName();
            }
        }

        return $pageKeys;
    }

    /**
     * Prefers the BUILT navigation cache over the project's raw XML, because the cache is what Zed
     * actually renders from — a correct copy that was never followed by a cache rebuild is a real, and
     * easy to miss, failure mode. Falls back to the raw XML when no cache has been built.
     *
     * @return array{0: string, 1: array<string>}|null
     */
    protected function readEffectiveNavigation(): ?array
    {
        $cacheFilePath = APPLICATION_ROOT_DIR . '/src/Generated/Zed/Navigation/codeBucket/navigation.cache';

        if (is_readable($cacheFilePath)) {
            $cachedNavigation = json_decode((string)file_get_contents($cacheFilePath), true);

            if (is_array($cachedNavigation)) {
                return ['the built navigation cache', $this->collectCachedPageKeys($cachedNavigation)];
            }
        }

        $projectPageKeys = $this->readProjectNavigationPageKeys();

        return $projectPageKeys === null ? null : ['config/Zed/navigation.xml', $projectPageKeys];
    }

    /**
     * @return array<string>|null
     */
    protected function readProjectNavigationPageKeys(): ?array
    {
        $projectNavigationXml = $this->loadXml(APPLICATION_ROOT_DIR . '/config/Zed/navigation.xml');

        if ($projectNavigationXml === null) {
            return null;
        }

        $pageKeys = [];

        foreach ($projectNavigationXml->xpath('//*') ?: [] as $element) {
            $pageKeys[] = $element->getName();
        }

        return $pageKeys;
    }

    /**
     * @param array<string, mixed> $cachedNavigation
     *
     * @return array<string>
     */
    protected function collectCachedPageKeys(array $cachedNavigation): array
    {
        $pageKeys = [];

        foreach ($cachedNavigation as $pageKey => $page) {
            $pageKeys[] = (string)$pageKey;

            if (!is_array($page) || !is_array($page['pages'] ?? null)) {
                continue;
            }

            $pageKeys = array_merge($pageKeys, $this->collectCachedPageKeys($page['pages']));
        }

        return $pageKeys;
    }

    /**
     * @param string $filePath
     */
    protected function loadXml(string $filePath): ?SimpleXMLElement
    {
        if (!is_readable($filePath)) {
            return null;
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string)file_get_contents($filePath));
        libxml_use_internal_errors($previousUseInternalErrors);

        return $xml === false ? null : $xml;
    }
}
