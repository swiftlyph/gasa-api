<?php

namespace App\Domains\CashSessions\Http\Requests;

use App\Domains\Orders\Reports\TopItemsReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /merchant/cash-sessions/{cashSession}/z-report — no date range (the
 * session itself IS the boundary, see ZReportReport's docblock), just an
 * optional `?limit=` on the top-items block. Reuses TopItemsReport's
 * DEFAULT_LIMIT/MAX_LIMIT rather than declaring its own, so the two
 * "best sellers" endpoints share one cap to tune.
 */
class ZReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.TopItemsReport::MAX_LIMIT],
        ];
    }
}
