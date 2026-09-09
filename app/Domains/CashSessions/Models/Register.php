<?php

namespace App\Domains\CashSessions\Models;

use App\Domains\CashSessions\Policies\RegisterPolicy;
use App\Domains\Shared\Concerns\BelongsToMerchant;
use Database\Factories\RegisterFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A till. Tenant-owned; see the create_registers_table migration for why
 * this exists from day one rather than being added when a second till
 * eventually shows up.
 *
 * Deliberately minimal this phase: no register CRUD beyond listing (see
 * RegisterPolicy and RegisterController) — creating and retiring
 * registers is a merchant-settings concern for a later phase, not part of
 * cash-session reconciliation.
 *
 * "The default register" is resolved by
 * App\Domains\CashSessions\Support\DefaultRegister — the merchant's OLDEST
 * register (lowest id), not a stored flag. A stored `is_default` boolean
 * would need its own uniqueness rule (exactly one per merchant) and its
 * own transfer-of-default logic when that one register is retired;
 * deriving it from "the first one you got" needs neither.
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property bool $is_active
 */
#[UsePolicy(RegisterPolicy::class)]
class Register extends Model
{
    /** @use HasFactory<RegisterFactory> */
    use BelongsToMerchant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'name',
        'is_active',
    ];

    protected static function newFactory(): RegisterFactory
    {
        return RegisterFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CashSession, $this>
     */
    public function cashSessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }
}
