<?php

namespace App\Domains\Allowance\Models;

use App\Domains\Allowance\Enums\LedgerEntryType;
use App\Domains\Auth\Models\User;
use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable movement in an allowance account. Positive amounts grant or
 * restore entitlement; negative amounts consume, expire or adjust it.
 *
 * @property LedgerEntryType $type
 * @property int $amount_cents
 * @property int $balance_after_cents
 */
class AllowanceLedgerEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'allowance_ledger_entries';

    protected $fillable = [
        'company_id',
        'allowance_account_id',
        'type',
        'amount_cents',
        'balance_after_cents',
        'reason',
        'idempotency_key',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AllowanceAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AllowanceAccount::class, 'allowance_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
