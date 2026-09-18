<?php

namespace App\Domains\Company\Http\Controllers;

use App\Domains\Company\Actions\ImportEmployeesAction;
use App\Domains\Company\Http\Requests\IndexEmployeesRequest;
use App\Domains\Company\Models\Employee;
use App\Domains\Company\Support\EmployeeFilters;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /company/employees/export: the roster as CSV, streamed. Takes the
 * same query string as the list (IndexEmployeesRequest, EmployeeFilters),
 * so "export what I'm looking at" holds by construction; per_page is
 * simply irrelevant here.
 *
 * The first columns are ImportEmployeesAction::COLUMNS in order, so an
 * exported file re-imports unchanged. `status` and `separated_at` follow
 * for the reader's benefit; the importer ignores them by design.
 */
class EmployeeExportController extends Controller
{
    private const EXTRA_COLUMNS = ['status', 'separated_at'];

    public function __invoke(IndexEmployeesRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->validated();
        $filename = 'employees-'.now(config('company.day_timezone'))->toDateString().'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // A UTF-8 byte-order mark, so Excel reads accented names
            // correctly. The importer strips it on the way back in.
            fwrite($out, "\xEF\xBB\xBF");

            $this->put($out, [...ImportEmployeesAction::COLUMNS, ...self::EXTRA_COLUMNS]);

            // lazy() pages through the ordered query and eager-loads each
            // page's departments: constant memory, no N+1.
            foreach (EmployeeFilters::query($filters)->lazy(500) as $employee) {
                $this->put($out, [
                    $employee->employee_no,
                    $employee->first_name,
                    $employee->middle_name,
                    $employee->last_name,
                    $employee->suffix,
                    $employee->email,
                    $employee->mobile,
                    $employee->department?->name,
                    $employee->job_title,
                    $employee->employment_type->value,
                    $employee->hired_at?->toDateString(),
                    $employee->birthdate?->toDateString(),
                    $employee->status->value,
                    $employee->separated_at?->toDateString(),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  resource  $out
     * @param  array<int, string|null>  $cells
     */
    private function put($out, array $cells): void
    {
        // Escape set explicitly (to none), for the same reason the importer does.
        fputcsv($out, array_map($this->guard(...), $cells), ',', '"', '');
    }

    /**
     * Spreadsheet-formula guard: a cell that starts with = or @ (or a
     * control character) would be EXECUTED by Excel when the file is
     * opened. Such a value is user-typed text here, so it is prefixed
     * with an apostrophe, which spreadsheets read as "this is text" and
     * the importer strips again. A leading + or - is left alone on
     * purpose: that is what an E.164 mobile number looks like.
     */
    private function guard(?string $cell): string
    {
        if ($cell === null || $cell === '') {
            return '';
        }

        return in_array($cell[0], ['=', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }
}
