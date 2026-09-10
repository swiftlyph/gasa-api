<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A reporting date range wider than ReportDateRangeRequest::MAX_RANGE_DAYS.
 *
 * Its own code rather than the generic `validation_failed`: this is not a
 * malformed request (both dates are perfectly valid `Y-m-d` values), it is
 * a request for more aggregation than this phase is willing to run without
 * caching — a distinct failure a client needs to handle differently (retry
 * with a narrower range) from "you sent something wrong."
 */
class ReportRangeTooLarge extends ApiException
{
    public function __construct(
        public readonly int $requestedDays,
        public readonly int $maxDays,
    ) {
        parent::__construct(
            "The report range spans {$requestedDays} days; the maximum is {$maxDays}.",
            'range_too_large',
            422,
        );
    }
}
