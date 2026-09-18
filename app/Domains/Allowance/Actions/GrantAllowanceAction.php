<?php

namespace App\Domains\Allowance\Actions;

use App\Domains\Allowance\Enums\LedgerEntryType;
use App\Domains\Allowance\Exceptions\EmployeeNotEligible;
use App\Domains\Allowance\Exceptions\IdempotencyKeyReuse;
use App\Domains\Allowance\Models\AllowanceAccount;
use App\Domains\Allowance\Models\AllowanceLedgerEntry;
use App\Domains\Auth\Models\User;
use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Grants entitlement through a ledger entry. The account is locked while
 * calculating the next balance, and the idempotency key makes safe retries
 * return without issuing the same grant twice.
 */
class GrantAllowanceAction
{
    /**
     * @throws EmployeeNotEligible
     */
    public function execute(
        Employee $employee,
        User $actor,
        int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): AllowanceAccount {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new EmployeeNotEligible;
        }

        return DB::transaction(function () use ($employee, $actor, $amountCents, $reason, $idempotencyKey): AllowanceAccount {
            $account = AllowanceAccount::query()->firstOrCreate([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->getKey(),
                'purse' => 'allowance',
            ]);

            /** @var AllowanceAccount $account */
            $account = AllowanceAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = AllowanceLedgerEntry::query()
                ->where('allowance_account_id', $account->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->amount_cents !== $amountCents || $existing->reason !== trim($reason)) {
                    throw new IdempotencyKeyReuse;
                }
            } else {
                $balance = (int) AllowanceLedgerEntry::query()
                    ->where('allowance_account_id', $account->getKey())
                    ->sum('amount_cents');

                AllowanceLedgerEntry::create([
                    'company_id' => $employee->company_id,
                    'allowance_account_id' => $account->getKey(),
                    'type' => LedgerEntryType::Grant,
                    'amount_cents' => $amountCents,
                    'balance_after_cents' => $balance + $amountCents,
                    'reason' => trim($reason),
                    'idempotency_key' => $idempotencyKey,
                    'created_by_user_id' => $actor->getKey(),
                ]);
            }

            return $account->refresh();
        });
    }
}
