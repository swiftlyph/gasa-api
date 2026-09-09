<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\CashMovementType;
use App\Domains\CashSessions\Models\CashMovement;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<CashMovement>
     */
    protected $model = CashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'cash_session_id' => CashSession::factory(),
            'type' => CashMovementType::CashIn,
            'amount_cents' => fake()->numberBetween(50, 500) * 100,
            'reason' => 'Change fund top-up',
            'created_by_user_id' => User::factory(),
        ];
    }

    /**
     * Pins the movement to an existing session, and stamps merchant_id to
     * match it — a movement whose tenant disagreed with its own session
     * would be a fixture production could never create.
     */
    public function forSession(CashSession $cashSession, ?User $recordedBy = null): static
    {
        return $this->state(fn () => [
            'merchant_id' => $cashSession->merchant_id,
            'cash_session_id' => $cashSession->getKey(),
            'created_by_user_id' => $recordedBy?->getKey() ?? $cashSession->opened_by_user_id,
        ]);
    }

    public function cashIn(int $amountCents = 50000, string $reason = 'Change fund top-up'): static
    {
        return $this->state(fn () => [
            'type' => CashMovementType::CashIn,
            'amount_cents' => $amountCents,
            'reason' => $reason,
        ]);
    }

    public function cashOut(int $amountCents = 20000, string $reason = 'Petty cash for supplies'): static
    {
        return $this->state(fn () => [
            'type' => CashMovementType::CashOut,
            'amount_cents' => $amountCents,
            'reason' => $reason,
        ]);
    }
}
