<?php

namespace App\Domains\Allowance\Enums;

/**
 * The append-only allowance ledger's entry types. Credits and debits are
 * represented by the signed amount, while the type explains why it moved.
 */
enum LedgerEntryType: string
{
    case Grant = 'grant';
    case Consumption = 'consumption';
    case Reversal = 'reversal';
    case Expiry = 'expiry';
    case Adjustment = 'adjustment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
