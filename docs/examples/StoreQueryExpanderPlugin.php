<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 *
 * COPY-PASTE TEMPLATE — not autoloaded by this package. Copy this file into your own project at:
 * src/Pyz/Client/SearchElasticsearch/Plugin/QueryExpander/StoreQueryExpanderPlugin.php
 * then register it in place of the core plugin everywhere your own CatalogDependencyProvider (and any
 * other search-domain DependencyProvider) currently does `new StoreQueryExpanderPlugin()`.
 * See this package's README, "Calling Client\Catalog from Zed or console", for the full story.
 */

declare(strict_types = 1);

namespace Pyz\Client\SearchElasticsearch\Plugin\QueryExpander;

use Spryker\Client\SearchElasticsearch\Plugin\QueryExpander\StoreQueryExpanderPlugin as SprykerStoreQueryExpanderPlugin;
use Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface;
use Spryker\Client\SearchExtension\Dependency\Plugin\SearchContextAwareQueryInterface;

/**
 * Project override of the core plugin.
 *
 * The core `StoreQueryExpanderPlugin::getStoreName()` unconditionally calls `Client\Store::getCurrentStore()`,
 * which needs a live HTTP/customer session. That's fine for real storefront/Glue traffic (a session always
 * exists there) but crashes the instant `Client\Catalog::catalogSearch()`/`Client\Search::search()` is called
 * from Zed or a console command, where no session exists. This override reads an explicit store name from
 * `$requestParameters` first — the channel Spryker's own `QueryExpanderPluginInterface::expandQuery()` already
 * defines for exactly this kind of override — falling back to the core behavior when the key is absent, so
 * normal storefront traffic (which never passes it) is byte-identical to core.
 *
 * It also stamps the resolved store name onto the query's own `SearchContextTransfer`, not just the Elastica
 * filter clause. This is the part that actually matters for the Zed/console crash: `Client\Catalog::catalogSearch()`
 * runs query expanders (this class, via `Client\Search::expandQuery()`) BEFORE `Client\Search::search()`
 * resolves the Elasticsearch index name via `IndexNameResolver`. That resolver falls back to the exact same
 * `Client\Store::getCurrentStore()` call whenever the query's search context has no store name set — which is
 * always true for the core query plugins, since none of them ever call `setStoreName()` on their own context.
 * Fixing only the Elastica filter (without this stamp) leaves that second call site crashing on its own.
 */
class StoreQueryExpanderPlugin extends SprykerStoreQueryExpanderPlugin
{
    /**
     * @var string
     */
    public const PARAMETER_STORE_NAME = 'store';

    /**
     * @var string|null
     */
    protected ?string $requestedStoreName = null;

    /**
     * @param \Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface $searchQuery
     * @param array<string, mixed> $requestParameters
     *
     * @return \Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface
     */
    public function expandQuery(QueryInterface $searchQuery, array $requestParameters = []): QueryInterface
    {
        $this->requestedStoreName = isset($requestParameters[static::PARAMETER_STORE_NAME])
            ? (string)$requestParameters[static::PARAMETER_STORE_NAME]
            : null;

        $searchQuery = parent::expandQuery($searchQuery, $requestParameters);

        if ($searchQuery instanceof SearchContextAwareQueryInterface) {
            $searchQuery->setSearchContext(
                $searchQuery->getSearchContext()->setStoreName($this->getStoreName()),
            );
        }

        return $searchQuery;
    }

    /**
     * @return string
     */
    protected function getStoreName(): string
    {
        return $this->requestedStoreName ?? parent::getStoreName();
    }
}
