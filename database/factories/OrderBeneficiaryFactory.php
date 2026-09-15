<?php

namespace Database\Factories;

use App\Domains\Orders\Enums\BeneficiaryType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderBeneficiary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Statutory-discount beneficiaries (P10).
 *
 * No CreatesAcrossTenants here — order_beneficiaries has no merchant_id,
 * so there is no global scope to work around. Tenancy is inherited
 * through the order, exactly as for OrderItemFactory.
 *
 * The money columns default to 0 rather than to random amounts: a
 * beneficiary's discount is a DERIVED total that must agree with the
 * lines pointing at it, and a factory inventing one would produce a
 * fixture no checkout could ever create. Tests that need populated
 * figures either go through the real checkout endpoint or set them
 * explicitly alongside matching lines.
 *
 * @extends Factory<OrderBeneficiary>
 */
class OrderBeneficiaryFactory extends Factory
{
    /**
     * @var class-string<OrderBeneficiary>
     */
    protected $model = OrderBeneficiary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => BeneficiaryType::Senior,
            'name' => fake()->name(),

            // Shaped like a real senior-citizen/PWD ID rather than a bare
            // number, so fixtures read like the thing they stand for.
            'id_number' => fake()->numerify('SC-####-####'),

            'discount_cents' => 0,
            'vat_exempt_sales_cents' => 0,
        ];
    }

    public function senior(): static
    {
        return $this->state(fn () => ['type' => BeneficiaryType::Senior]);
    }

    public function pwd(): static
    {
        return $this->state(fn () => [
            'type' => BeneficiaryType::Pwd,
            'id_number' => fake()->numerify('PWD-####-####'),
        ]);
    }
}
