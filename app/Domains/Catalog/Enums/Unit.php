<?php

namespace App\Domains\Catalog\Enums;

use InvalidArgumentException;

/**
 * Every unit this system understands, and the exact integer arithmetic
 * that converts between the ones that are actually comparable.
 *
 * THE RULE THIS ENUM EXISTS TO ENFORCE: a quantity only ever converts to
 * another unit of the SAME UnitType. Grams and kilograms both measure
 * mass, so 1000g always equals 1kg — that is a fixed fact of the metric
 * system, expressible as an exact integer ratio. Milliliters and grams do
 * not share a family: how much a milliliter of something weighs depends
 * on what that something IS (its density), which varies per ingredient
 * and is not data this system collects. Converting "30ml of matcha
 * powder" into grams would require guessing a number, and a guessed
 * number in an inventory deduction is exactly the kind of mistake this
 * whole feature exists to prevent. So this enum simply refuses: toBase()
 * only accepts a Unit of the ingredient's own UnitType, everywhere it is
 * called (RecipeItem, Ingredient), and a mismatched pair is a validation
 * error long before any arithmetic runs.
 *
 * INTEGER MATH ONLY, same discipline as App\Domains\Shared\Support\Money
 * and every price column in this codebase: every quantity is stored as a
 * whole number of its family's SMALLEST unit (milligrams for mass,
 * milliliters for volume, pieces for count — see baseUnitFor()), so
 * repeated deductions across thousands of sales can never accumulate
 * floating-point drift. A user-facing quantity in a larger unit (30g,
 * 1.5L) is converted UP FRONT, by multiplication, which is always exact.
 * Only formatting a stored quantity back into a display string divides,
 * and only for that string — never for a value used in further math,
 * mirroring Money::format().
 */
enum Unit: string
{
    case Milligram = 'mg';
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Liter = 'l';
    case Piece = 'pcs';

    public function type(): UnitType
    {
        return match ($this) {
            self::Milligram, self::Gram, self::Kilogram => UnitType::Mass,
            self::Milliliter, self::Liter => UnitType::Volume,
            self::Piece => UnitType::Count,
        };
    }

    /**
     * How many of this family's BASE unit one of this unit is worth.
     * Always an exact integer — the metric system guarantees that for
     * mass and volume, and "1 piece" is trivially its own base.
     */
    public function factorToBase(): int
    {
        return match ($this) {
            self::Milligram, self::Milliliter, self::Piece => 1,
            self::Gram => 1_000,
            self::Kilogram => 1_000_000,
            self::Liter => 1_000,
        };
    }

    /** The smallest unit of a family — what every quantity is actually stored as. */
    public static function baseUnitFor(UnitType $type): self
    {
        return match ($type) {
            UnitType::Mass => self::Milligram,
            UnitType::Volume => self::Milliliter,
            UnitType::Count => self::Piece,
        };
    }

    /**
     * Converts a whole-number quantity in THIS unit to the family's base
     * unit. Exact — never a float — because factorToBase() is always an
     * integer and this is a plain multiplication.
     */
    public function toBaseUnits(int $quantity): int
    {
        return $quantity * $this->factorToBase();
    }

    /**
     * @return list<string>
     */
    public static function valuesFor(UnitType $type): array
    {
        return array_values(array_map(
            fn (self $unit) => $unit->value,
            array_filter(self::cases(), fn (self $unit) => $unit->type() === $type),
        ));
    }

    /**
     * Parses a unit string, requiring it to belong to $type. Throws
     * rather than silently returning null: every call site already knows
     * which family it expects (the ingredient it's being validated
     * against), so a mismatch here is exactly the "ml against a
     * kg-tracked ingredient" mistake this enum exists to catch.
     */
    public static function fromFamily(string $value, UnitType $type): self
    {
        $unit = self::tryFrom($value);

        if ($unit === null || $unit->type() !== $type) {
            throw new InvalidArgumentException(
                "[{$value}] is not a valid {$type->value} unit.",
            );
        }

        return $unit;
    }

    /**
     * Formats a base-unit integer quantity for display in $displayUnit —
     * e.g. (5_000_000, Kilogram) => "5". DISPLAY ONLY: the division here
     * produces a string, never a value fed back into deduction math (see
     * class docblock). Up to 3 decimal places, trimmed — kitchen-scale
     * precision, not a repeating fraction.
     */
    public static function formatQuantity(int $baseUnitQuantity, self $displayUnit): string
    {
        $factor = $displayUnit->factorToBase();

        if ($baseUnitQuantity % $factor === 0) {
            return (string) intdiv($baseUnitQuantity, $factor);
        }

        return rtrim(rtrim(number_format($baseUnitQuantity / $factor, 3, '.', ''), '0'), '.');
    }
}
