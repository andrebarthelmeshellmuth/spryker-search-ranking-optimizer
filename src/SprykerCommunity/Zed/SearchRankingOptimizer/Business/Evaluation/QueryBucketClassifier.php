<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation;

/**
 * A fixed, hardcoded search-term -> query-type-bucket lookup for P4's hybrid-vs-lexical comparison — NOT
 * schema-backed (no category/bucket column exists on `spy_search_ranking_query`), deliberately: this is a
 * one-off spike measurement over exactly the 60-query judging worksheet the search-semantic-hybrid spike
 * plan defines, not a general-purpose query-taxonomy feature. Matching is case-insensitive against
 * `spy_search_ranking_query.search_term`, which is stored lowercase in this shop's real data — every key
 * below is therefore already lowercase, and {@see classify()} lowercases its input before lookup.
 *
 * The 6 buckets (see the spike plan / judging worksheet for the full rationale):
 * 1. Exact SKU/model number lookups.
 * 2. Brand + product-type lookups.
 * 3. Generic product-type terms.
 * 4. Natural-language / descriptive "what I want to do with it" queries — the hypothesis is these benefit
 *    most from hybrid (semantic) retrieval, since they share little lexical overlap with product titles.
 * 5. Attribute-qualified specific queries (dimensions, capacities, colors).
 * 6. Misspelled variants of bucket-3 terms — the hypothesis is these ALSO benefit from hybrid retrieval,
 *    since a typo breaks lexical matching but an embedding model may still place it close to the correct
 *    term's vector.
 *
 * 3 of bucket 6's terms ("shreder", "flipchat", "lokker") returned zero real search results during
 * judging and were therefore never rated — they simply never appear in a real evaluation request's
 * `queries[]` (built from rated data only), so no special-casing is needed for them here; they're kept in
 * this map anyway for completeness/documentation.
 */
class QueryBucketClassifier implements QueryBucketClassifierInterface
{
    /**
     * Specification:
     * - A search term with no entry here classifies as bucket 0 ("unknown") — never throws. Should not
     *   happen for the real judged set (exactly these 60 terms), but a query added to the judgment set
     *   later, or through some other path, must degrade safely rather than break the comparison report.
     *
     * @var int
     */
    public const BUCKET_UNKNOWN = 0;

    /**
     * @var int
     */
    public const BUCKET_SKU_LOOKUP = 1;

    /**
     * @var int
     */
    public const BUCKET_BRAND_PRODUCT_TYPE = 2;

    /**
     * @var int
     */
    public const BUCKET_GENERIC_PRODUCT_TYPE = 3;

    /**
     * @var int
     */
    public const BUCKET_NATURAL_LANGUAGE = 4;

    /**
     * @var int
     */
    public const BUCKET_ATTRIBUTE_QUALIFIED = 5;

    /**
     * @var int
     */
    public const BUCKET_MISSPELLED = 6;

    protected const BUCKET_BY_SEARCH_TERM = [
        // Bucket 1 — exact SKU/model number lookups.
        'm23484' => self::BUCKET_SKU_LOOKUP,
        '1190151' => self::BUCKET_SKU_LOOKUP,
        'el-fc22' => self::BUCKET_SKU_LOOKUP,
        '112412' => self::BUCKET_SKU_LOOKUP,
        'chp910' => self::BUCKET_SKU_LOOKUP,
        '44084' => self::BUCKET_SKU_LOOKUP,
        'fa 450.2' => self::BUCKET_SKU_LOOKUP,
        'classic 90.2' => self::BUCKET_SKU_LOOKUP,
        'xtra m 0.5' => self::BUCKET_SKU_LOOKUP,
        'm58313' => self::BUCKET_SKU_LOOKUP,

        // Bucket 2 — brand + product-type lookups.
        'topstar swivel chair' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'edding permanent marker' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'hsm document shredder' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'wolf steel locker' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'brennenstuhl cable box' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'post-it adhesive strips' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'clairefontaine notepad' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'verbatim memory card' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'hammerbacher desk' => self::BUCKET_BRAND_PRODUCT_TYPE,
        'mauser steel cabinet' => self::BUCKET_BRAND_PRODUCT_TYPE,

        // Bucket 3 — generic product-type terms.
        'office chair' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'swivel chair' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'document shredder' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'copy paper' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'flipchart' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'locker' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'sticky notes' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'desk' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'usb stick' => self::BUCKET_GENERIC_PRODUCT_TYPE,
        'hand truck' => self::BUCKET_GENERIC_PRODUCT_TYPE,

        // Bucket 4 — natural-language / descriptive queries.
        'something to destroy confidential documents' => self::BUCKET_NATURAL_LANGUAGE,
        'chair for sitting comfortably all day' => self::BUCKET_NATURAL_LANGUAGE,
        'paper for the office printer' => self::BUCKET_NATURAL_LANGUAGE,
        'board for presentations in meetings' => self::BUCKET_NATURAL_LANGUAGE,
        'storage for employee belongings' => self::BUCKET_NATURAL_LANGUAGE,
        'pen that does not smudge' => self::BUCKET_NATURAL_LANGUAGE,
        'cart for moving heavy boxes' => self::BUCKET_NATURAL_LANGUAGE,
        'device for checking banknotes' => self::BUCKET_NATURAL_LANGUAGE,
        'pad for taking notes in meetings' => self::BUCKET_NATURAL_LANGUAGE,
        'table for working while standing' => self::BUCKET_NATURAL_LANGUAGE,

        // Bucket 5 — attribute-qualified specific queries.
        'black swivel chair with armrests' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'a4 copy paper 80 g' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'locker with 4 compartments' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'marker chisel tip refillable' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'desk 160 cm wide' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'microsd card 64gb class 10' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'shredder stripe cut 3.9 mm' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'swivel chair without armrests' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'flipchart 105x68 cm' => self::BUCKET_ATTRIBUTE_QUALIFIED,
        'hand truck 500 kg load capacity' => self::BUCKET_ATTRIBUTE_QUALIFIED,

        // Bucket 6 — misspelled variants (3 of these — "shreder", "flipchat", "lokker" — returned zero
        // results and were never rated; see this class's own docblock).
        'offce chair' => self::BUCKET_MISSPELLED,
        'swivle chair' => self::BUCKET_MISSPELLED,
        'shreder' => self::BUCKET_MISSPELLED,
        'eddig marker' => self::BUCKET_MISSPELLED,
        'copie paper' => self::BUCKET_MISSPELLED,
        'flipchat' => self::BUCKET_MISSPELLED,
        'lokker' => self::BUCKET_MISSPELLED,
        'stiky notes' => self::BUCKET_MISSPELLED,
        'topstat chair' => self::BUCKET_MISSPELLED,
        'documnet shredder' => self::BUCKET_MISSPELLED,
    ];

    /**
     * {@inheritDoc}
     *
     * @param string $searchTerm
     */
    public function classify(string $searchTerm): int
    {
        return static::BUCKET_BY_SEARCH_TERM[strtolower($searchTerm)] ?? static::BUCKET_UNKNOWN;
    }
}
