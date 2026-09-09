<?php

namespace App\Domains\Orders\Enums;

use App\Domains\Orders\Exceptions\InvalidOrderTransition;

/**
 * The order lifecycle, and — in allowedTransitions() — THE single
 * authority on which moves between its states are legal. Nothing else in
 * the codebase may decide whether a transition is allowed: both
 * CompleteOrderAction and VoidOrderAction ask this enum, and any future
 * transition (a kitchen "ready", a manager reopen) asks it too.
 *
 * Mirrors the CHECK constraint on orders.status. Adding a case here
 * REQUIRES a migration widening that constraint — the database is the
 * real enforcement, this enum is the typed view of it.
 *
 * Three states today, two of them terminal. The shape is deliberately
 * built for INSERTION rather than replacement: adding `Preparing` and
 * `Ready` between Pending and Completed later means adding two cases and
 * editing the match arms below, with no caller changing at all, because
 * no caller hard-codes a state pair.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Voided = 'voided';

    /**
     * The transition map. Terminal states return an empty list, which is
     * what makes completed -> voided, voided -> completed, and every
     * repeat transition illegal without any of those pairs being spelled
     * out as a special case.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Completed, self::Voided],
            self::Completed, self::Voided => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Guard form of canTransitionTo(). Actions call this rather than
     * branching themselves, so the 422 `invalid_transition` response is
     * produced in exactly one place.
     *
     * @throws InvalidOrderTransition
     */
    public function assertCanTransitionTo(self $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw new InvalidOrderTransition($this, $target);
        }
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
