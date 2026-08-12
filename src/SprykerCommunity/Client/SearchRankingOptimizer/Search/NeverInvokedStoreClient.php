<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchRankingOptimizer\Search;

use Generated\Shared\Transfer\StoreTransfer;
use LogicException;
use Spryker\Client\SearchElasticsearch\Dependency\Client\SearchElasticsearchToStoreClientInterface;

/**
 * `IndexNameResolver` requires a store client dependency, but its `getCurrentStore()` fallback is only
 * ever invoked when `resolve()` is called WITHOUT an explicit store name — {@see SaturationPointCalibrationSearcher}
 * always passes one explicitly (the admin-picked store, since Zed has no "current store" of its own), so
 * this dependency is structurally required but never actually exercised. A real `Client\Store` bridge
 * would be misleading here — it would imply calibration depends on `Client\Store::getCurrentStore()`,
 * the exact HTTP-request-scoped call that breaks from Zed in the first place.
 */
class NeverInvokedStoreClient implements SearchElasticsearchToStoreClientInterface
{
    /**
     * @throws \LogicException
     */
    public function getCurrentStore(): StoreTransfer
    {
        throw new LogicException(
            'NeverInvokedStoreClient::getCurrentStore() was called — SaturationPointCalibrationSearcher must always ' .
            'pass an explicit store name to IndexNameResolver::resolve() instead of relying on this fallback.',
        );
    }
}
