<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchRankingOptimizer\Business\Evaluation;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchRankingOptimizer\Business\Evaluation\QueryBucketClassifier;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchRankingOptimizer
 * @group Business
 * @group Evaluation
 * @group QueryBucketClassifierTest
 * Add your own group annotations below this line
 * @group Portable
 */
class QueryBucketClassifierTest extends Unit
{
    /**
     * Every one of the 60 real search terms from the judging worksheet — including the 3 bucket-6
     * misspellings ("shreder", "flipchat", "lokker") that returned zero real search results and were
     * never rated (they simply never appear in a real evaluation request, but this classifier itself has
     * no reason to know or care about that — it must still classify them correctly).
     *
     * @return array<array-key, array<int, string|int>>
     */
    public static function realSearchTermProvider(): array
    {
        return [
            // Bucket 1 — exact SKU/model number lookups.
            'M23484' => ['M23484', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            '1190151' => ['1190151', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            'EL-FC22' => ['EL-FC22', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            '112412' => ['112412', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            'CHP910' => ['CHP910', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            '44084' => ['44084', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            'FA 450.2' => ['FA 450.2', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            'Classic 90.2' => ['Classic 90.2', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            'XTRA M 0.5' => ['XTRA M 0.5', QueryBucketClassifier::BUCKET_SKU_LOOKUP],
            'M58313' => ['M58313', QueryBucketClassifier::BUCKET_SKU_LOOKUP],

            // Bucket 2 — brand + product-type lookups.
            'Topstar swivel chair' => ['Topstar swivel chair', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'edding permanent marker' => ['edding permanent marker', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'HSM document shredder' => ['HSM document shredder', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Wolf steel locker' => ['Wolf steel locker', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Brennenstuhl cable box' => ['Brennenstuhl cable box', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Post-it adhesive strips' => ['Post-it adhesive strips', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Clairefontaine notepad' => ['Clairefontaine notepad', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Verbatim memory card' => ['Verbatim memory card', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Hammerbacher desk' => ['Hammerbacher desk', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],
            'Mauser steel cabinet' => ['Mauser steel cabinet', QueryBucketClassifier::BUCKET_BRAND_PRODUCT_TYPE],

            // Bucket 3 — generic product-type terms.
            'office chair' => ['office chair', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'swivel chair' => ['swivel chair', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'document shredder' => ['document shredder', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'copy paper' => ['copy paper', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'flipchart' => ['flipchart', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'locker' => ['locker', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'sticky notes' => ['sticky notes', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'desk' => ['desk', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'usb stick' => ['usb stick', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],
            'hand truck' => ['hand truck', QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE],

            // Bucket 4 — natural-language / descriptive queries.
            'something to destroy confidential documents' => ['something to destroy confidential documents', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'chair for sitting comfortably all day' => ['chair for sitting comfortably all day', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'paper for the office printer' => ['paper for the office printer', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'board for presentations in meetings' => ['board for presentations in meetings', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'storage for employee belongings' => ['storage for employee belongings', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'pen that does not smudge' => ['pen that does not smudge', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'cart for moving heavy boxes' => ['cart for moving heavy boxes', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'device for checking banknotes' => ['device for checking banknotes', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'pad for taking notes in meetings' => ['pad for taking notes in meetings', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],
            'table for working while standing' => ['table for working while standing', QueryBucketClassifier::BUCKET_NATURAL_LANGUAGE],

            // Bucket 5 — attribute-qualified specific queries.
            'black swivel chair with armrests' => ['black swivel chair with armrests', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'A4 copy paper 80 g' => ['A4 copy paper 80 g', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'locker with 4 compartments' => ['locker with 4 compartments', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'marker chisel tip refillable' => ['marker chisel tip refillable', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'desk 160 cm wide' => ['desk 160 cm wide', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'microSD card 64GB class 10' => ['microSD card 64GB class 10', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'shredder stripe cut 3.9 mm' => ['shredder stripe cut 3.9 mm', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'swivel chair without armrests' => ['swivel chair without armrests', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'flipchart 105x68 cm' => ['flipchart 105x68 cm', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],
            'hand truck 500 kg load capacity' => ['hand truck 500 kg load capacity', QueryBucketClassifier::BUCKET_ATTRIBUTE_QUALIFIED],

            // Bucket 6 — misspelled variants.
            'offce chair' => ['offce chair', QueryBucketClassifier::BUCKET_MISSPELLED],
            'swivle chair' => ['swivle chair', QueryBucketClassifier::BUCKET_MISSPELLED],
            'shreder' => ['shreder', QueryBucketClassifier::BUCKET_MISSPELLED],
            'eddig marker' => ['eddig marker', QueryBucketClassifier::BUCKET_MISSPELLED],
            'copie paper' => ['copie paper', QueryBucketClassifier::BUCKET_MISSPELLED],
            'flipchat' => ['flipchat', QueryBucketClassifier::BUCKET_MISSPELLED],
            'lokker' => ['lokker', QueryBucketClassifier::BUCKET_MISSPELLED],
            'stiky notes' => ['stiky notes', QueryBucketClassifier::BUCKET_MISSPELLED],
            'Topstat chair' => ['Topstat chair', QueryBucketClassifier::BUCKET_MISSPELLED],
            'documnet shredder' => ['documnet shredder', QueryBucketClassifier::BUCKET_MISSPELLED],
        ];
    }

    /**
     * @dataProvider realSearchTermProvider
     *
     * @param string $searchTerm
     * @param int $expectedBucket
     */
    public function testClassifyMapsEveryRealJudgingWorksheetTermToItsExpectedBucket(string $searchTerm, int $expectedBucket): void
    {
        // Act
        $bucket = (new QueryBucketClassifier())->classify($searchTerm);

        // Assert
        $this->assertSame($expectedBucket, $bucket);
    }

    /**
     * The real judgment set stores `search_term` lowercase (confirmed live DB data) — this must still
     * classify correctly no matter the case the caller happens to pass in.
     */
    public function testClassifyIsCaseInsensitive(): void
    {
        // Act
        $lowercaseBucket = (new QueryBucketClassifier())->classify('office chair');
        $uppercaseBucket = (new QueryBucketClassifier())->classify('OFFICE CHAIR');
        $mixedCaseBucket = (new QueryBucketClassifier())->classify('Office Chair');

        // Assert
        $this->assertSame(QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE, $lowercaseBucket);
        $this->assertSame(QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE, $uppercaseBucket);
        $this->assertSame(QueryBucketClassifier::BUCKET_GENERIC_PRODUCT_TYPE, $mixedCaseBucket);
    }

    public function testClassifyReturnsUnknownForATermWithNoBucketMapping(): void
    {
        // Act
        $bucket = (new QueryBucketClassifier())->classify('definitely not a real judging worksheet term');

        // Assert
        $this->assertSame(QueryBucketClassifier::BUCKET_UNKNOWN, $bucket);
        $this->assertSame(0, $bucket);
    }
}
