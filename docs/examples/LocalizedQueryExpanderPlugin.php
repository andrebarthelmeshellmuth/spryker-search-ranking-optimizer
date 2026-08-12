<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 *
 * COPY-PASTE TEMPLATE — not autoloaded by this package. Copy this file into your own project at:
 * src/Pyz/Client/SearchElasticsearch/Plugin/QueryExpander/LocalizedQueryExpanderPlugin.php
 * then register it in place of the core plugin everywhere your own CatalogDependencyProvider (and any
 * other search-domain DependencyProvider) currently does `new LocalizedQueryExpanderPlugin()`.
 * See this package's README, "Calling Client\Catalog from Zed or console", for the full story — and see
 * the sibling StoreQueryExpanderPlugin.php in this same directory, which this pairs with.
 */

declare(strict_types = 1);

namespace Pyz\Client\SearchElasticsearch\Plugin\QueryExpander;

use Spryker\Client\SearchElasticsearch\Plugin\QueryExpander\LocalizedQueryExpanderPlugin as SprykerLocalizedQueryExpanderPlugin;
use Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface;

/**
 * Project override of the core plugin — the locale-filter twin of StoreQueryExpanderPlugin (same
 * directory). The core `LocalizedQueryExpanderPlugin::getCurrentLocale()` unconditionally calls
 * `Client\Locale::getCurrentLocale()`, which needs a live HTTP/customer session, for the identical
 * reason StoreQueryExpanderPlugin's Client\Store call does.
 *
 * Unlike the store case, locale never factors into Elasticsearch index name resolution (only store
 * does — see `IndexNameResolver::resolve()`, which takes a store name but no locale argument at all).
 * So this override only needs to fix the query filter clause; no SearchContextTransfer stamping is
 * required here.
 */
class LocalizedQueryExpanderPlugin extends SprykerLocalizedQueryExpanderPlugin
{
    /**
     * @var string
     */
    public const PARAMETER_LOCALE_NAME = 'locale';

    /**
     * @var string|null
     */
    protected ?string $requestedLocaleName = null;

    /**
     * @param \Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface $searchQuery
     * @param array<string, mixed> $requestParameters
     *
     * @return \Spryker\Client\SearchExtension\Dependency\Plugin\QueryInterface
     */
    public function expandQuery(QueryInterface $searchQuery, array $requestParameters = []): QueryInterface
    {
        $this->requestedLocaleName = isset($requestParameters[static::PARAMETER_LOCALE_NAME])
            ? (string)$requestParameters[static::PARAMETER_LOCALE_NAME]
            : null;

        return parent::expandQuery($searchQuery, $requestParameters);
    }

    /**
     * @return string
     */
    protected function getCurrentLocale(): string
    {
        return $this->requestedLocaleName ?? parent::getCurrentLocale();
    }
}
