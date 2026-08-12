<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchRankingOptimizerWidget\Controller;

use FilesystemIterator;
use Generated\Shared\Transfer\SearchRankingProductRelevanceJudgmentBatchRequestTransfer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spryker\Yves\Kernel\Controller\AbstractController;
use Spryker\Yves\Kernel\PermissionAwareTrait;
use SprykerCommunity\Shared\SearchRankingOptimizer\Plugin\RateSearchRelevancePermissionPlugin;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Router\SearchRankingOptimizerWidgetRouteProviderPlugin;
use SprykerCommunity\Yves\SearchRankingOptimizerWidget\Plugin\Twig\SearchRankingOptimizerWidgetTwigPlugin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;
use Twig\Error\SyntaxError;

/**
 * Diagnoses the Yves-side half of a search-ranking-optimizer installation — the half
 * {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Console\SearchRankingOptimizerCheckInstallationConsole}
 * cannot reach, because Zed never bootstraps the Yves DI container. Complementary to that console
 * command, not a replacement: this page does not re-check the schema, the cron jobs or the Zed
 * navigation — run the console command for those.
 *
 * Deliberately covers the failure modes that produce a widget which *looks* installed:
 * `getSearchRelevanceRatings()` registered but never called by the SRP template (stored judgments simply
 * never reappear after a reload), a glossary that was never imported (buttons carry raw translation keys
 * as their tooltips), and a frontend bundle that was never rebuilt (unstyled buttons). None of those
 * raise an error anywhere.
 *
 * Reachable only when BOTH gates pass: the route only exists when
 * {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConstants::IS_CHECK_INSTALLATION_PAGE_ENABLED}
 * allows it (defaults to `false`), AND the visiting customer holds {@see RateSearchRelevancePermissionPlugin}.
 * Missing the permission where the route does exist renders an explanation with the exact remedy rather
 * than a bare 403 — that is almost always someone mid-setup, not an intrusion.
 *
 * @method \SprykerCommunity\Yves\SearchRankingOptimizerWidget\SearchRankingOptimizerWidgetFactory getFactory()
 */
class CheckInstallationController extends AbstractController
{
    use PermissionAwareTrait;

    /**
     * Any one of the widget's own glossary keys is enough to prove the package's glossary data was
     * imported — they ship in, and are imported from, a single CSV.
     *
     * @var string
     */
    protected const GLOSSARY_KEY_PROBE = 'search_ranking_optimizer.rate.heart';

    /**
     * The widget's outer custom element name, which is also its BEM block — present in the built CSS
     * bundle if and only if the frontend build picked this package's components up.
     *
     * @var string
     */
    protected const FRONTEND_ASSET_PROBE = 'search-ranking-optimizer-product-rating';

    /**
     * @return \Spryker\Yves\Kernel\View\View|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        if (!$this->can(RateSearchRelevancePermissionPlugin::KEY)) {
            return $this->renderView(
                '@SearchRankingOptimizerWidget/views/check-installation/permission-denied.twig',
                [],
                new Response('', Response::HTTP_FORBIDDEN),
            );
        }

        return $this->view(
            [
                'checks' => $this->runChecks(),
            ],
            [],
            '@SearchRankingOptimizerWidget/views/check-installation/check-installation.twig',
        );
    }

    /**
     * @return array<int, array{label: string, passed: bool, remedy: string|null}>
     */
    protected function runChecks(): array
    {
        return [
            $this->checkTwigFunctions(),
            $this->checkRoutes(),
            $this->checkJudgmentLookup(),
            $this->checkGlossary(),
            $this->checkFrontendAssets(),
        ];
    }

    /**
     * @return array{label: string, passed: bool, remedy: string|null}
     */
    protected function checkTwigFunctions(): array
    {
        $missingFunctionNames = [];

        foreach ($this->getExpectedTwigFunctionNames() as $functionName => $argumentList) {
            if ($this->isTwigFunctionCallable($functionName, $argumentList)) {
                continue;
            }

            $missingFunctionNames[] = $functionName;
        }

        return [
            'label' => 'Twig helper functions (canRateSearchRelevance, searchRankingOptimizerRatingCsrfToken, getSearchRelevanceRatings) are registered',
            'passed' => $missingFunctionNames === [],
            'remedy' => $missingFunctionNames === []
                ? null
                : sprintf(
                    'Register SearchRankingOptimizerWidgetTwigPlugin in src/Pyz/Yves/Twig/TwigDependencyProvider.php (see README step 3b). Missing: %s.',
                    implode(', ', $missingFunctionNames),
                ),
        ];
    }

    /**
     * Keyed by function name, valued by the argument list to compile the call with — the compile-time
     * check below only rejects an UNKNOWN function, but Twig still needs an arity Twig itself accepts.
     *
     * @return array<string, string>
     */
    protected function getExpectedTwigFunctionNames(): array
    {
        return [
            SearchRankingOptimizerWidgetTwigPlugin::FUNCTION_NAME_CAN_RATE_SEARCH_RELEVANCE => '',
            SearchRankingOptimizerWidgetTwigPlugin::FUNCTION_NAME_RATING_CSRF_TOKEN => '',
            SearchRankingOptimizerWidgetTwigPlugin::FUNCTION_NAME_GET_SEARCH_RELEVANCE_RATINGS => "'', []",
        ];
    }

    /**
     * Compiles a throwaway one-line template that calls the function, rather than inspecting
     * `Twig\Environment`'s function registry directly — that registry is only reachable through
     * `getFunction()`, which Twig marks `@internal`. `createTemplate()` is Twig's own documented way to
     * ask "does this compile", and it already throws {@see SyntaxError} for an unknown function at compile
     * time, so no render is needed either.
     *
     * @param string $functionName
     * @param string $argumentList
     */
    protected function isTwigFunctionCallable(string $functionName, string $argumentList): bool
    {
        try {
            $this->getTwig()->createTemplate(sprintf('{{ %s(%s) }}', $functionName, $argumentList));

            return true;
        } catch (SyntaxError) {
            return false;
        }
    }

    /**
     * @return array{label: string, passed: bool, remedy: string|null}
     */
    protected function checkRoutes(): array
    {
        $missingRouteNames = [];

        foreach ($this->getWidgetRouteNames() as $routeName) {
            if ($this->isRouteRegistered($routeName)) {
                continue;
            }

            $missingRouteNames[] = $routeName;
        }

        return [
            'label' => 'Submit and clear judgment routes are registered',
            'passed' => $missingRouteNames === [],
            'remedy' => $missingRouteNames === []
                ? null
                : sprintf(
                    'Register SearchRankingOptimizerWidgetRouteProviderPlugin in src/Pyz/Yves/Router/RouterDependencyProvider.php (see README step 3b). Missing: %s.',
                    implode(', ', $missingRouteNames),
                ),
        ];
    }

    /**
     * The check-installation route itself is deliberately excluded: reaching this action already proves it
     * is registered, so re-checking it here would only ever report success.
     *
     * @return array<string>
     */
    protected function getWidgetRouteNames(): array
    {
        return [
            SearchRankingOptimizerWidgetRouteProviderPlugin::ROUTE_NAME_SUBMIT_RELEVANCE_JUDGMENT,
            SearchRankingOptimizerWidgetRouteProviderPlugin::ROUTE_NAME_CLEAR_RELEVANCE_JUDGMENT,
        ];
    }

    /**
     * @param string $routeName
     */
    protected function isRouteRegistered(string $routeName): bool
    {
        try {
            $this->getRouter()->generate($routeName);

            return true;
        } catch (RouteNotFoundException) {
            return false;
        }
    }

    /**
     * Exercises the batched lookup the SRP template's own `activeRatingType` depends on, end to end
     * (Yves -> Client stub -> Zed gateway), with a search term nothing can plausibly have been rated for.
     * A `[]` result is a PASS here: the point is that the round trip completes at all, not that this
     * particular term has judgments.
     *
     * This is the data path behind the single most silent failure this package has: the template not
     * feeding `activeRatingType` back in looks identical to a rating that never saved. This check proves
     * the half that IS introspectable; the template wiring itself is listed on the page as not verifiable
     * from here.
     *
     * @return array{label: string, passed: bool, remedy: string|null}
     */
    protected function checkJudgmentLookup(): array
    {
        $requestTransfer = (new SearchRankingProductRelevanceJudgmentBatchRequestTransfer())
            ->setSearchTerm('')
            ->setStoreName($this->getFactory()->getStoreClient()->getCurrentStore()->getNameOrFail())
            ->setLocaleName($this->getLocale())
            ->setIdProductAbstracts([])
            ->setCustomerReference('');

        try {
            $this->getFactory()->getSearchRankingOptimizerClient()->getProductRelevanceJudgments($requestTransfer);
            $errorMessage = null;
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }

        return [
            'label' => 'Stored-judgment lookup (getSearchRelevanceRatings -> Zed gateway) completes',
            'passed' => $errorMessage === null,
            'remedy' => $errorMessage === null
                ? null
                : sprintf(
                    'The Zed gateway behind the ratings prefill is unreachable, so already-rated products would always render unpressed. Confirm Zed is running and that this package is installed there too. Error: %s',
                    $errorMessage,
                ),
        ];
    }

    /**
     * A missing glossary key is not an error anywhere — Spryker's translator returns the key itself, so
     * the widget renders with a raw `search_ranking_optimizer.rate.heart` tooltip and nothing complains.
     * Rendering through the same `trans` filter the widget's own template uses makes this a faithful
     * check rather than an approximation of one.
     *
     * @return array{label: string, passed: bool, remedy: string|null}
     */
    protected function checkGlossary(): array
    {
        $isTranslated = $this->translate(static::GLOSSARY_KEY_PROBE) !== static::GLOSSARY_KEY_PROBE;

        return [
            'label' => 'Glossary translations for the rating widget are imported',
            'passed' => $isTranslated,
            'remedy' => $isTranslated
                ? null
                : sprintf(
                    'Copy this package\'s data/glossary.csv into your project\'s glossary data and run "vendor/bin/console data:import glossary" (see README step 3b). Until then "%s" renders as its own raw key.',
                    static::GLOSSARY_KEY_PROBE,
                ),
        ];
    }

    /**
     * @param string $glossaryKey
     */
    protected function translate(string $glossaryKey): string
    {
        try {
            return $this->getTwig()->createTemplate(sprintf('{{ %s | trans }}', var_export($glossaryKey, true)))->render();
        } catch (Throwable) {
            return $glossaryKey;
        }
    }

    /**
     * The `index.ts`-shaped trap this project has hit before: a template-paired `.scss` is silently never
     * bundled unless its directory also has an entry point, and an adopter who never re-ran the frontend
     * build gets the same symptom — unstyled buttons, no error. Searching the built CSS for the
     * component's own block name is the only honest way to tell from here.
     *
     * @return array{label: string, passed: bool, remedy: string|null}
     */
    protected function checkFrontendAssets(): array
    {
        $isBundled = $this->isAssetProbePresentInBuiltCss();

        if ($isBundled === null) {
            return [
                'label' => 'Frontend build includes this package\'s components',
                'passed' => false,
                'remedy' => 'Could not locate any built Yves CSS bundle to inspect, which normally means the frontend has never been built in this environment. Run "yarn yves" (or your project\'s equivalent) and reload.',
            ];
        }

        return [
            'label' => 'Frontend build includes this package\'s components',
            'passed' => $isBundled,
            'remedy' => $isBundled
                ? null
                : sprintf(
                    'The built CSS contains no "%s" rules, so the rating buttons render unstyled. Run "yarn yves" (or your project\'s equivalent) and reload.',
                    static::FRONTEND_ASSET_PROBE,
                ),
        ];
    }

    /**
     * Null distinguishes "no bundle found at all" (nothing was ever built, or this project keeps its
     * assets somewhere non-standard) from a definite yes/no — the two deserve different remedies.
     */
    protected function isAssetProbePresentInBuiltCss(): ?bool
    {
        $cssFilePaths = $this->findBuiltCssFilePaths();

        if ($cssFilePaths === []) {
            return null;
        }

        foreach ($cssFilePaths as $cssFilePath) {
            if (str_contains((string)file_get_contents($cssFilePath), static::FRONTEND_ASSET_PROBE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walks the asset root rather than globbing a fixed depth: the built path is theme- and
     * revision-nested (`assets/<revision>/<theme>/css/`) and neither segment is fixed across projects.
     *
     * @return array<string>
     */
    protected function findBuiltCssFilePaths(): array
    {
        $assetRootPath = APPLICATION_ROOT_DIR . '/public/Yves/assets';

        if (!is_dir($assetRootPath)) {
            return [];
        }

        $cssFilePaths = [];
        $directoryIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetRootPath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($directoryIterator as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'css') {
                continue;
            }

            $cssFilePaths[] = $fileInfo->getPathname();
        }

        return $cssFilePaths;
    }
}
