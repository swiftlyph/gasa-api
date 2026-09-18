<?php

namespace App\Domains\Company\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Actions\ImportEmployeesAction;
use App\Domains\Company\Http\Requests\ImportEmployeesRequest;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * POST /company/employees/import. Always 200 with a per-row report, in
 * both modes: a file with bad rows is a normal outcome the caller reads
 * row by row, not an error. Only a file that can't be read at all is a
 * 422 (invalid_import_file), and only a malformed upload a
 * validation_failed.
 *
 * Authorized as `create`: importing is adding (and editing) employees in
 * bulk, nothing the caller couldn't do one at a time.
 */
class EmployeeImportController extends Controller
{
    public function __invoke(ImportEmployeesRequest $request, ImportEmployeesAction $action): JsonResponse
    {
        $this->authorize('create', Employee::class);

        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->activeCompany();

        $report = $action->execute($company, $request->csv()->getRealPath(), $request->shouldCommit());

        return response()->json($report);
    }
}
