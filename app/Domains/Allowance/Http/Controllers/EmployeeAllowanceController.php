<?php

namespace App\Domains\Allowance\Http\Controllers;

use App\Domains\Allowance\Actions\GrantAllowanceAction;
use App\Domains\Allowance\Http\Requests\GrantAllowanceRequest;
use App\Domains\Allowance\Http\Resources\AllowanceAccountResource;
use App\Domains\Allowance\Models\AllowanceAccount;
use App\Domains\Allowance\Models\AllowanceLedgerEntry;
use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/** Company-admin allowance read and grant endpoints for one employee. */
class EmployeeAllowanceController extends Controller
{
    public function show(Employee $employee): AllowanceAccountResource
    {
        $this->authorize('view', $employee);

        return AllowanceAccountResource::make($this->loadAccount($employee));
    }

    public function grant(
        GrantAllowanceRequest $request,
        Employee $employee,
        GrantAllowanceAction $action,
    ): JsonResponse {
        $this->authorize('manageAllowance', $employee);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->payload();
        $account = $action->execute(
            $employee,
            $actor,
            $payload['amount_cents'],
            $payload['reason'],
            $payload['idempotency_key'],
        );

        return AllowanceAccountResource::make($this->loadAccount($employee, $account))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    private function loadAccount(Employee $employee, ?AllowanceAccount $account = null): AllowanceAccount
    {
        $account ??= AllowanceAccount::query()
            ->where('employee_id', $employee->getKey())
            ->where('purse', 'allowance')
            ->first();

        if ($account === null) {
            $account = new AllowanceAccount([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->getKey(),
                'purse' => 'allowance',
            ]);
            $entries = collect();
            $balance = 0;
        } else {
            $entries = AllowanceLedgerEntry::query()
                ->where('allowance_account_id', $account->getKey())
                ->latest('id')
                ->limit(20)
                ->get();
            $balance = (int) AllowanceLedgerEntry::query()
                ->where('allowance_account_id', $account->getKey())
                ->sum('amount_cents');
        }

        $account->setAttribute('balance_cents', $balance);
        /** @var Collection<int, AllowanceLedgerEntry> $entries */
        $account->setRelation('recentEntries', $entries);

        return $account;
    }
}
