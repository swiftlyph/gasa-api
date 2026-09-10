<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Orders\Reports\TopItemsReport;

/**
 * GET /merchant/reports/top-items — the shared date range plus `?limit=`.
 */
class TopItemsReportRequest extends ReportDateRangeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.TopItemsReport::MAX_LIMIT],
        ]);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? TopItemsReport::DEFAULT_LIMIT);
    }
}
