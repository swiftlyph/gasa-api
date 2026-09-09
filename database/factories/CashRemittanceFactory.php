<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\RemittanceStatus;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRemittance>
 */
class CashRemittanceFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<CashRemittance>
     */
    protected $model = CashRemittance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'cash_session_id' => CashSession::factory(),
            'amount_cents' => fake()->numberBetween(500, 2000) * 100,
            'note' => 'Bank drop',
            'attachment_path' => null,
            'created_by_user_id' => User::factory(),
            'confirmed_by_user_id' => null,
            'confirmed_at' => null,
            'status' => RemittanceStatus::Pending,
        ];
    }

    /**
     * Pins the remittance to an existing session, and stamps merchant_id
     * to match it.
     */
    public function forSession(CashSession $cashSession, ?User $createdBy = null): static
    {
        return $this->state(fn () => [
            'merchant_id' => $cashSession->merchant_id,
            'cash_session_id' => $cashSession->getKey(),
            'created_by_user_id' => $createdBy?->getKey() ?? $cashSession->opened_by_user_id,
        ]);
    }

    /**
     * A confirmed remittance needs a SECOND user by construction — the
     * whole point of the feature — so this state requires one explicitly
     * rather than defaulting to the creator, which would fabricate a
     * fixture the real ConfirmRemittanceAction refuses to produce.
     */
    public function confirmed(User $confirmedBy): static
    {
        return $this->state(fn () => [
            'status' => RemittanceStatus::Confirmed,
            'confirmed_by_user_id' => $confirmedBy->getKey(),
            'confirmed_at' => now(),
        ]);
    }
}
