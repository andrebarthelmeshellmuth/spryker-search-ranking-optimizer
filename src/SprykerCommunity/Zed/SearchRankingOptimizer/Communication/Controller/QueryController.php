<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Controller;

use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form\QueryImportanceWeightForm;
use SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Table\QueryTable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Communication\SearchRankingOptimizerCommunicationFactory getFactory()
 * @method \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SearchRankingOptimizerFacadeInterface getFacade()
 */
class QueryController extends AbstractController
{
    /**
     * @var string
     */
    protected const URL_QUERY_LIST = '/search-ranking-optimizer/query';

    /**
     * @return array<string, mixed>
     */
    public function indexAction(): array
    {
        return $this->viewResponse([
            'queryTable' => $this->getFactory()->createQueryTable()->render(),
        ]);
    }

    /**
     * AJAX data source the rendered table's own JS polls against (DataTables-style server-side
     * processing) — same convention `spryker-community/search-ranking`'s own MetricTable/IndexController
     * pairing uses.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function tableAction(): JsonResponse
    {
        return $this->jsonResponse(
            $this->getFactory()->createQueryTable()->fetchData(),
        );
    }

    /**
     * Gated by standard Zed ACL only (like every other controller in this package) — the "Query Curator"
     * role is an ACL group grant, not a separate Permission-system check. A `SetSearchQueryImportancePermissionPlugin`
     * used to exist alongside this action but was never actually checked anywhere; removed rather than
     * left as dead code implying protection that wasn't there.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array<string, mixed>
     */
    public function editAction(Request $request)
    {
        $idSearchRankingQuery = $this->castId(
            $request->query->get(QueryTable::URL_PARAM_ID_SEARCH_RANKING_QUERY),
        );

        $queryTransfer = $this->getFacade()->findQueryById($idSearchRankingQuery);

        if ($queryTransfer === null) {
            $this->addErrorMessage(sprintf('Query with id %d does not exist.', $idSearchRankingQuery));

            return $this->redirectResponse(static::URL_QUERY_LIST);
        }

        $importanceWeightForm = $this->getFactory()
            ->createQueryImportanceWeightForm($queryTransfer->getImportanceWeightOrFail())
            ->handleRequest($request);

        if ($importanceWeightForm->isSubmitted() && $importanceWeightForm->isValid()) {
            $importanceWeight = (float)$importanceWeightForm->getData()[QueryImportanceWeightForm::FIELD_IMPORTANCE_WEIGHT];
            $this->getFacade()->updateQueryImportanceWeight($idSearchRankingQuery, $importanceWeight);
            $this->addSuccessMessage(sprintf('Importance weight for "%s" was updated.', $queryTransfer->getSearchTermOrFail()));

            return $this->redirectResponse(static::URL_QUERY_LIST);
        }

        return $this->viewResponse([
            'importanceWeightForm' => $importanceWeightForm->createView(),
            'query' => $queryTransfer,
        ]);
    }
}
