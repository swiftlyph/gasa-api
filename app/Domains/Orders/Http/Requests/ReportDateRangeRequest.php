<?php

namespace App\Domains\Orders\Http\Requests;

use App\Domains\Orders\Exceptions\ReportRangeTooLarge;
use App\Domains\Orders\Support\MerchantDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared date-range handling for every GET /merchant/reports/* endpoint.
 *
 * `from` and `to` are both INCLUSIVE calendar days, merchant-local (see
 * MerchantDay) — `?from=2026-09-01&to=2026-09-03` covers three whole days.
 * Both default to today when omitted, so every report endpoint answers
 * something sensible with no query string at all rather than 422ing or
 * silently scanning the whole table.
 *
 * The range cap is enforced here, once, rather than in each report's own
 * query — a report that forgot the check would otherwise run an unbounded
 * aggregate over the entire orders table. `range_too_large` is a distinct
 * 422 from `validation_failed`: both dates are individually valid, this is
 * a request for more aggregation than this phase serves without caching
 * (see README § Reporting).
 */
class ReportDateRangeRequest extends FormRequest
{
    /**
     * Inclusive days, both ends. 366 covers a leap year's worth of daily
     * figures — generous for the "how did last year compare" question a
     * merchant actually asks, without leaving the aggregate queries below
     * unbounded.
     */
    public const MAX_RANGE_DAYS = 366;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'The from filter must be a calendar day in YYYY-MM-DD format.',
            'to.date_format' => 'The to filter must be a calendar day in YYYY-MM-DD format.',
            'to.after_or_equal' => 'The to filter must not be before the from filter.',
        ];
    }

    /**
     * The range cap is a business rule about how much a report is willing
     * to aggregate, not a shape-of-the-request problem — so it is checked
     * after ordinary field validation passes (both dates already known to
     * be well-formed and from <= to) rather than as a validation rule, and
     * raised as ReportRangeTooLarge for its own error code rather than
     * folded into `validation_failed`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                // Malformed dates or from > to already failed above;
                // don't pile a second, possibly-misleading check on top of
                // input the range can't even be computed from yet.
                return;
            }

            $days = (int) $this->fromDate()->diffInDays($this->toDate()) + 1;

            if ($days > self::MAX_RANGE_DAYS) {
                throw new ReportRangeTooLarge($days, self::MAX_RANGE_DAYS);
            }
        });
    }

    /**
     * The first day of the range, merchant-local — today when `from` is
     * omitted.
     */
    public function fromDate(): CarbonImmutable
    {
        $from = $this->validated('from');

        return $from !== null ? MerchantDay::startOf($from) : MerchantDay::startOfToday();
    }

    /**
     * The last day of the range, merchant-local and INCLUSIVE — today when
     * `to` is omitted, which also covers the common case of neither
     * parameter being sent (from == to == today).
     */
    public function toDate(): CarbonImmutable
    {
        $to = $this->validated('to');

        return $to !== null ? MerchantDay::startOf($to) : MerchantDay::startOfToday();
    }

    /**
     * The half-open UTC instant range a query should actually filter on:
     * [start of `from`, start of the day AFTER `to`) — see MerchantDay's
     * docblock for why both ends are converted with forQuery() before
     * they can touch a query binding.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function queryRange(): array
    {
        return [
            MerchantDay::forQuery($this->fromDate()),
            MerchantDay::forQuery($this->toDate()->addDay()),
        ];
    }
}
