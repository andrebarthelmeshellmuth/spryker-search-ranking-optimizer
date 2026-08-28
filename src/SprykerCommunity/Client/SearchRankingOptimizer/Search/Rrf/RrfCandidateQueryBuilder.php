<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search\Rrf;

use Elastica\Query\AbstractQuery;
use Elastica\Query\FunctionScore;
use Elastica\Query\Ids;
use Elastica\Query\MatchNone;
use Elastica\Query\Term;

/**
 * See {@see RrfCandidateQueryBuilderInterface} for the full contract/rationale.
 */
class RrfCandidateQueryBuilder implements RrfCandidateQueryBuilderInterface
{
    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $fusedRankedDocIds
     */
    public function build(array $fusedRankedDocIds): AbstractQuery
    {
        if ($fusedRankedDocIds === []) {
            return new MatchNone();
        }

        $poolSize = count($fusedRankedDocIds);

        $functionScore = new FunctionScore();
        $functionScore->setQuery(new Ids(array_values($fusedRankedDocIds)));
        $functionScore->setBoostMode(FunctionScore::BOOST_MODE_REPLACE);
        $functionScore->setScoreMode(FunctionScore::SCORE_MODE_MAX);

        foreach (array_values($fusedRankedDocIds) as $rankIndex => $docId) {
            $weight = (float)($poolSize - $rankIndex);

            $filter = new Term();
            $filter->setTerm('_id', $docId);

            $functionScore->addWeightFunction($weight, $filter);
        }

        return $functionScore;
    }
}
