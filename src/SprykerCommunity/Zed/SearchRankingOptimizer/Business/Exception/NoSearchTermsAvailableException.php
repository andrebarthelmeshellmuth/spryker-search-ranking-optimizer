<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Business\Exception;

use RuntimeException;

/**
 * Thrown by {@see \SprykerCommunity\Zed\SearchRankingOptimizer\Business\SaturationPointCalibration\SaturationPointCalibrationUploadHandler::createCalibration()}
 * when the non-CSV path finds no organically rated queries for the given store/locale -- rejects the
 * upload outright rather than queuing a calibration run with zero attached search terms, which would
 * otherwise sit as status=uploaded until the next "search-ranking-optimizer:calibrate" tick and only then
 * fail with a generic "No values were found" message, hiding the real, immediately-actionable cause.
 */
class NoSearchTermsAvailableException extends RuntimeException
{
}
