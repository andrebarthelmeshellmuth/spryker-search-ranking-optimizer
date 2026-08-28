<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console;

use FilesystemIterator;
use Generated\Shared\Transfer\PermissionCollectionTransfer;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingAutoTuneMetricConfigQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingEvaluationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingOptimizerRunQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingQueryRatingQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingSaturationPointCalibrationSearchTermQuery;
use Orm\Zed\SearchRankingOptimizer\Persistence\SpySearchRankingWeightCheckpointQuery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
 * it cannot confirm the Yves-side route-provider/Twig-plugin registration (step 3c) or that the widget
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
    public const COMMAND_DESCRIPTION = 'Diagnoses a search-ranking-optimizer installation: core namespace, sibling console command registration, the SRP-rating permission plugin (Zed and Client), Yves glossary key, Zed translations, the 8 Propel tables this package ships, and whether the auto-tune notification ACL role is staffed.';

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
     * This package's root, relative to this console's directory.
     *
     * @var string
     */
    protected const PACKAGE_ROOT_RELATIVE_PATH = '/../../../../../..';

    /**
     * The locale whose catalog defines the expected key set; the others are kept at parity with it.
     *
     * @var string
     */
    protected const TRANSLATION_REFERENCE_LOCALE = 'en_US';

    /**
     * @var string
     */
    protected const PATTERN_TWIG_TRANS = '/(?<![\\w\\\\])([\'"])((?:\\\\.|(?!\\1).)*)\\1\\s*\\|\\s*trans/';

    /**
     * @var string
     */
    protected const PATTERN_PHP_TRANS = '/->(?:trans|translate)\\(\\s*([\'"])((?:\\\\.|(?!\\1).)*)\\1/';

    /**
     * This package ships its OWN Glue API Platform resource (`search-relevance-judgments`), so there is
     * no project-level provider override to check for — the only thing that can silently be missing is
     * `vendor/bin/glue api:generate` never having been run since this schema was added.
     *
     * @var string
     */
    protected const GLUE_API_RESOURCE_CLASS_NAME = 'Generated\\Api\\Storefront\\SearchRelevanceJudgmentsStorefrontResource';

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
        $this->checkAutoTuneNotificationRoleStaffed($output);
        $this->checkNavigationRegistered($output);
        $this->checkBackOfficeAccess($output);
        $this->checkZedTranslationCatalogComplete($output);
        $this->checkGlueApiWiring($output);

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
        $output->writeln('  - the Yves route-provider and Twig plugins are registered (step 3c)');
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
     * The auto-tune summary email resolves its recipients from an ACL role
     * ({@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME}),
     * which no package can create for a project — it is set up by hand in the Zed ACL Gui, exactly like the
     * cron registrations above. Every way it can end up resolving to nobody is silent: `AutoTuneRunner`
     * sends to zero recipients and the run still reports success. The only surface is the auto-tune
     * console's own "Notified 0 admin(s) by email." line, which nobody reads — that job runs under cron.
     *
     * A WARNING, never a failure, and only raised when some metric actually has "notify by email" enabled:
     * the role is genuinely optional for a shop that never turned notifications on, and failing there would
     * cry wolf on the majority of installs. Everything it needs comes from one facade call, which resolves
     * recipients through the same
     * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\AutoTune\AutoTuneNotificationRecipientResolverInterface::resolve()}
     * the real send path uses — a check that re-traversed the ACL tables itself could pass while the
     * actual email still reached nobody.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkAutoTuneNotificationRoleStaffed(OutputInterface $output): void
    {
        $diagnosisTransfer = $this->getFacade()->getAutoTuneNotificationDiagnosis();
        $roleName = $diagnosisTransfer->getRoleNameOrFail();

        if (!$diagnosisTransfer->getIsNotifyEnabledAnywhere()) {
            $output->writeln(sprintf(
                '<info>✓</info> no metric has auto-tune email notification enabled, so the "%s" ACL role is not needed yet',
                $roleName,
            ));

            return;
        }

        $recipientEmails = $diagnosisTransfer->getRecipientEmails();

        if (count($recipientEmails) > 0) {
            $output->writeln(sprintf(
                '<info>✓</info> the auto-tune summary email resolves to %d recipient(s) via the "%s" ACL role',
                count($recipientEmails),
                $roleName,
            ));

            return;
        }

        if (!$diagnosisTransfer->getDoesRoleExist()) {
            $this->warnings[] = sprintf(
                'At least one metric has auto-tune email notification enabled, but the ACL role "%s" does not exist — the summary email will be sent to nobody, and the auto-tune run will still report success. Create the role in the Zed ACL Gui (Maintenance > Users & Rights > Roles), assign it to a group, and put the admins who should be notified in that group.',
                $roleName,
            );

            return;
        }

        $this->warnings[] = sprintf(
            'At least one metric has auto-tune email notification enabled and the ACL role "%s" exists, but nobody holds it — either no ACL group has been assigned the role, or the groups that have it contain no users. The summary email will be sent to nobody, and the auto-tune run will still report success. Fix it in the Zed ACL Gui (Maintenance > Users & Rights).',
            $roleName,
        );
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
     * Zed access is deny-by-default outside a matching ACL rule, and this package ships no ACL fixture data
     * — so who can reach its pages is entirely up to the adopter. Two very different installations land
     * here:
     *
     * A default Spryker install needs nothing done: `root_role` carries a total wildcard and every
     * installer user sits in `root_group`, so the pages work the moment the package is installed. An
     * installation running real restricted back-office roles is the opposite — those roles reach nothing
     * here until somebody adds a rule, and the failure is quiet, because
     * {@see \Spryker\Zed\Acl\Communication\Plugin\Navigation\AclNavigationItemFilterPlugin} filters the
     * entry out of the sidebar rather than 403ing. To that user the feature is simply absent, which looks
     * identical to the package never having been installed.
     *
     * A WARNING at most, and worded as something to confirm rather than fix: keeping these pages to
     * root-style admins is a perfectly ordinary choice, and this command cannot know which roles an adopter
     * MEANT to grant. It only reports the one state worth a second look — restricted roles exist, and not
     * one of them has a rule for this package's modules.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkBackOfficeAccess(OutputInterface $output): void
    {
        $moduleNames = $this->readOwnNavigationModuleNames();

        if ($moduleNames === []) {
            $this->warnings[] = 'Could not read this package\'s own navigation.xml, so back-office access could not be checked. Confirm by hand that the Zed roles which should see the Search Ranking Optimizer pages can actually reach them.';

            return;
        }

        $diagnosisTransfer = $this->getFactory()->createBackOfficeAccessAnalyzer()->analyze($moduleNames);
        $restrictedRoleCount = $diagnosisTransfer->getRestrictedRoleCountOrFail();

        if ($restrictedRoleCount === 0) {
            $output->writeln(sprintf(
                '<info>✓</info> all %d back-office role(s) have unrestricted access, so this package\'s Zed pages need no ACL rule',
                $diagnosisTransfer->getUnrestrictedRoleCountOrFail(),
            ));

            return;
        }

        $restrictedRoleWithAccessCount = $diagnosisTransfer->getRestrictedRoleWithAccessCountOrFail();

        if ($restrictedRoleWithAccessCount > 0) {
            $output->writeln(sprintf(
                '<info>✓</info> %d of %d restricted back-office role(s) have an ACL rule for %s',
                $restrictedRoleWithAccessCount,
                $restrictedRoleCount,
                implode('/', $moduleNames),
            ));

            return;
        }

        $this->warnings[] = sprintf(
            'This project has %d restricted back-office role(s) and none of them has an ACL rule for %s, so only unrestricted (root-style) admins can reach this package\'s Zed pages — for everybody else the sidebar entry is filtered out entirely, which looks the same as the package not being installed. If that is intended, nothing to do. If a restricted role should see Search Ranking Optimizer, add a rule for it in the Zed ACL Gui (Maintenance > Users & Rights > Roles).',
            $restrictedRoleCount,
            implode('/', $moduleNames),
        );
    }

    /**
     * Read from this package's OWN navigation.xml rather than hardcoded, same as the page-key check
     * alongside it, so a module added by a later version cannot silently fall out of this check.
     *
     * @return array<string>
     */
    protected function readOwnNavigationModuleNames(): array
    {
        $ownNavigationXml = $this->loadXml(__DIR__ . static::OWN_NAVIGATION_XML_RELATIVE_PATH);

        if ($ownNavigationXml === null) {
            return [];
        }

        $moduleNames = [];

        foreach ($ownNavigationXml->xpath('//bundle') ?: [] as $bundleElement) {
            $moduleNames[(string)$bundleElement] = true;
        }

        return array_keys($moduleNames);
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

    /**
     * The Zed catalog and the strings the GUI actually renders drift apart silently, in both directions,
     * because the keys ARE the English text: a key missing from the catalog still renders correct English
     * and only shows up as untranslated in a non-English Zed. Nothing else notices, which is how this
     * package's own catalog fell behind its GUI once already.
     *
     * Scans this package's own Zed sources for `|trans` keys and asserts each one is in the shipped
     * catalog. Deliberately one-directional: a key that looks unused to this scan may still be reached
     * through addSuccessMessage(), a widget_title, a table header or a form label, all of which are
     * translated at render time, so an unused-looking entry is never reported as a problem.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkZedTranslationCatalogComplete(OutputInterface $output): void
    {
        $usedKeys = $this->collectUsedZedTranslationKeys();
        $catalogKeys = $this->readZedTranslationCatalogKeys(static::TRANSLATION_REFERENCE_LOCALE);

        if ($usedKeys === [] || $catalogKeys === null) {
            $this->warnings[] = 'Could not compare this package\'s Zed translation catalog against the strings its GUI uses (sources or catalog unreadable). Nothing to act on unless you are working on the package itself.';

            return;
        }

        $missingKeys = array_values(array_diff($usedKeys, $catalogKeys));

        if ($missingKeys === []) {
            $output->writeln(sprintf('<info>✓</info> all %d Zed GUI strings are present in the translation catalog', count($usedKeys)));

            return;
        }

        $this->failures[] = sprintf(
            '%d Zed GUI string(s) are missing from data/translation/Zed/%s.csv and will render untranslated in any non-English Zed: "%s". This is a defect in the package itself, not in your project setup.',
            count($missingKeys),
            static::TRANSLATION_REFERENCE_LOCALE,
            implode('", "', array_slice($missingKeys, 0, 8)) . (count($missingKeys) > 8 ? '", ...' : ''),
        );
    }

    /**
     * @return array<string>
     */
    protected function collectUsedZedTranslationKeys(): array
    {
        $zedSourcePath = __DIR__ . static::PACKAGE_ROOT_RELATIVE_PATH . '/src/SprykerCommunity/Zed';

        if (!is_dir($zedSourcePath)) {
            return [];
        }

        $keys = [];
        $directoryIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($zedSourcePath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($directoryIterator as $fileInfo) {
            if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), ['twig', 'php'], true)) {
                continue;
            }

            $keys = array_merge($keys, $this->extractTranslationKeys((string)file_get_contents($fileInfo->getPathname())));
        }

        return array_values(array_unique($keys));
    }

    /**
     * Skips anything interpolated (`~`, `{{ }}`) — those are built at runtime and cannot be matched
     * against a static catalog.
     *
     * @param string $source
     *
     * @return array<string>
     */
    protected function extractTranslationKeys(string $source): array
    {
        $keys = [];

        foreach ([static::PATTERN_TWIG_TRANS, static::PATTERN_PHP_TRANS] as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = str_replace(['\\\'', '\\"'], ['\'', '"'], $match[2]);

                if (str_contains($key, '{') || str_contains($key, '~') || str_starts_with($key, '/')) {
                    continue;
                }

                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param string $locale
     *
     * @return array<string>|null
     */
    protected function readZedTranslationCatalogKeys(string $locale): ?array
    {
        $catalogPath = sprintf('%s%s/data/translation/Zed/%s.csv', __DIR__, static::PACKAGE_ROOT_RELATIVE_PATH, $locale);

        if (!is_readable($catalogPath)) {
            return null;
        }

        $handle = fopen($catalogPath, 'r');

        if ($handle === false) {
            return null;
        }

        $keys = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (!isset($row[0]) || trim((string)$row[0]) === '') {
                continue;
            }

            $keys[] = (string)$row[0];
        }

        fclose($handle);

        return $keys;
    }

    /**
     * `spryker/api-platform` is a hard composer dependency here (this package ships its own
     * `search-relevance-judgments` resource, so there is nothing to conditionally skip), but the
     * generated `Generated\Api\Storefront\SearchRelevanceJudgmentsStorefrontResource` class only exists
     * once `vendor/bin/glue api:generate storefront` has actually been run against this package's
     * schema — a project that installs/updates this package but never (re-)runs that command gets no
     * error at all, just a `search-relevance-judgments` resource missing from the OpenAPI docs and a
     * 404 on `POST /search-relevance-judgments`. WARNING, not a failure: a project that does not run a
     * Glue Storefront application at all is a legitimate, common configuration, not a broken install.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkGlueApiWiring(OutputInterface $output): void
    {
        $resourceClassName = $this->getGlueApiResourceClassName();

        if (class_exists($resourceClassName)) {
            $output->writeln(sprintf('<info>✓</info> Glue API resource %s is generated', $resourceClassName));

            return;
        }

        $this->warnings[] = sprintf(
            'Glue API resource %s does not exist yet: run `vendor/bin/glue api:generate storefront` (see README, "Glue REST API"). POST /search-relevance-judgments will 404 until then. Skip this if your project does not run a Glue Storefront application.',
            $resourceClassName,
        );
    }

    /**
     * Isolated as its own method so a test can override it to point at a fixture class name instead of
     * this host shop's real generated Glue resource.
     */
    protected function getGlueApiResourceClassName(): string
    {
        return static::GLUE_API_RESOURCE_CLASS_NAME;
    }
}
