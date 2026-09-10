<?php

namespace App\Domains\Merchant\Enums;

use App\Domains\Merchant\Exceptions\InvalidMerchantTransition;

/**
 * Mirrors the CHECK constraint on merchants.status. Adding a case here
 * REQUIRES a migration widening that constraint — the database is the
 * real enforcement, this enum is the typed view of it.
 *
 * Only `Active` merchants may use the merchant portal; `Pending` (not yet
 * approved) and `Suspended` both get a 403 `merchant_inactive` from
 * EnsureMerchantActive.
 *
 * allowedTransitions() is THE single authority on which status changes a
 * platform admin may make — mirrors App\Domains\Orders\Enums\OrderStatus's
 * shape exactly. Nothing else decides this: App\Domains\Merchant\Actions\
 * ChangeMerchantStatusAction asks this enum rather than branching itself,
 * so the 422 `invalid_transition` response is produced in exactly one
 * place.
 */
enum MerchantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * pending -> active (approve) or suspended (reject/block before ever
     * going live); active -> suspended; suspended -> active (reinstate).
     * There is deliberately no suspended -> pending or active -> pending:
     * once approved, a merchant never goes back to awaiting approval.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Suspended],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Guard form of canTransitionTo(). Actions call this rather than
     * branching themselves.
     *
     * @throws InvalidMerchantTransition
     */
    public function assertCanTransitionTo(self $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw new InvalidMerchantTransition($this, $target);
        }
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
