<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\CashSessionStatus;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<CashSession>
     */
    protected $model = CashSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'register_id' => Register::factory(),
            'opened_by_user_id' => User::factory(),
            'closed_by_user_id' => null,

            'status' => CashSessionStatus::Open,

            'opening_float_cents' => fake()->numberBetween(2, 20) * 100 * 100,
            'counted_cash_cents' => null,
            'expected_cash_cents' => null,
            'variance_cents' => null,

            'opened_at' => now(),
            'closed_at' => null,
            'notes' => null,
        ];
    }

    /**
     * Pins the session to an existing merchant/register, and to one of
     * that merchant's own users as opener — mirroring
     * OrderFactory::forMerchant()'s reasoning: an opener from a different
     * merchant would be a nonsensical fixture.
     */
    public function forMerchant(Merchant $merchant, ?Register $register = null, ?User $opener = null): static
    {
        return $this->state(fn () => [
            'merchant_id' => $merchant->getKey(),
            'register_id' => $register?->getKey() ?? Register::factory()->forMerchant($merchant),
            'opened_by_user_id' => $opener?->getKey()
                ?? $merchant->users()->value('users.id')
                ?? User::factory(),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status' => CashSessionStatus::Open,
            'closed_by_user_id' => null,
            'counted_cash_cents' => null,
            'expected_cash_cents' => null,
            'variance_cents' => null,
            'closed_at' => null,
        ]);
    }

    /**
     * A closed session with a chosen variance: 0 for an exact count,
     * positive for an over, negative for a short. expected_cash_cents is
     * set independently of any real orders/movements — this factory
     * fabricates the SNAPSHOT a real close would have computed, rather
     * than recomputing it from fixture data, since tests that need the
     * live formula exercised use ReconcileCashSessionAction directly
     * against real orders/movements instead.
     */
    public function closed(int $expectedCashCents = 100000, int $varianceCents = 0, ?User $closedBy = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CashSessionStatus::Closed,
            'closed_by_user_id' => $closedBy?->getKey() ?? $attributes['opened_by_user_id'],
            'expected_cash_cents' => $expectedCashCents,
            'counted_cash_cents' => $expectedCashCents + $varianceCents,
            'variance_cents' => $varianceCents,
            'closed_at' => now(),
        ]);
    }
}
