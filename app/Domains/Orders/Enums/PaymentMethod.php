<?php

namespace App\Domains\Orders\Enums;

/**
 * How an order was paid. Mirrors the CHECK constraint on
 * orders.payment_method — adding a case here REQUIRES a migration
 * widening that constraint.
 *
 * Counter-service model: payment happens at creation, so this records a
 * settled fact rather than an intention. There is no `unpaid` case and no
 * payment_status column; an order that wasn't paid for simply doesn't
 * exist.
 *
 * `Split` is the only case that uses orders.cash_cents / gcash_cents,
 * which must sum to total_cents (enforced by P2's checkout).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';
    case Split = 'split';

    public function isSplit(): bool
    {
        return $this === self::Split;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
