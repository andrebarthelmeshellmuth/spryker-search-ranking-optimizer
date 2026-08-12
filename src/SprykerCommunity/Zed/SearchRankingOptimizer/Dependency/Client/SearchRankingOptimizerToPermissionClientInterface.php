<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Dependency\Client;

use Generated\Shared\Transfer\PermissionCollectionTransfer;

interface SearchRankingOptimizerToPermissionClientInterface
{
    /**
     * Deliberately `getRegisteredPermissions()`, NOT `findMergedRegisteredNonInfrastructuralPermissions()`
     * — the latter is a real Zed-Gateway HTTP call under the hood ({@see \Spryker\Client\Permission\Zed\PermissionStub})
     * requiring a live Store/HTTP context this bare CLI process doesn't have, and crashes with a
     * `StoreFactory::getStoreService(): Return value must be of type string, null returned` TypeError.
     * `getRegisteredPermissions()` stays entirely local (`PermissionFinder::getRegisteredPermissionCollection()`,
     * no network call), which is exactly the "is this plugin registered on the Client side" question this
     * bridge exists to answer.
     */
    public function getRegisteredPermissions(): PermissionCollectionTransfer;
}
