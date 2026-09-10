<?php

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Orders\Support\MerchantDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /admin/audit-logs filters: actor, action, subject_type/subject_id,
 * and an inclusive from/to date range. Both dates are OPTIONAL and
 * unbounded when omitted — unlike ReportDateRangeRequest, an admin
 * browsing the audit trail has no reason to default to "just today", and
 * there's no expensive aggregate here to cap the way report queries are
 * (a plain indexed paginated read, not a whole-table sum).
 *
 * Date handling mirrors ReportDateRangeRequest's forQuery() conversion
 * exactly (see MerchantDay's docblock for why a merchant-local boundary
 * must be converted to UTC before it touches a `timestamp` column) —
 * reused here rather than reinvented, just without the range cap that's
 * specific to reporting's aggregate cost.
 */
class IndexAuditLogsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actor_user_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:255'],
            'subject_type' => ['sometimes', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The half-open UTC instant range to filter created_at by, or null
     * when neither `from` nor `to` was given — no range filter at all.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function queryRange(): ?array
    {
        $from = $this->validated('from');
        $to = $this->validated('to');

        if ($from === null && $to === null) {
            return null;
        }

        $fromDate = $from !== null ? MerchantDay::startOf($from) : MerchantDay::startOfToday();
        $toDate = $to !== null ? MerchantDay::startOf($to) : MerchantDay::startOfToday();

        return [
            MerchantDay::forQuery($fromDate),
            MerchantDay::forQuery($toDate->addDay()),
        ];
    }
}
