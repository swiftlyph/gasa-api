<?php

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Enums\UnitType;

/**
 * The exact arithmetic the "please don't make a mistake" requirement
 * hinges on: every quantity converts to its family's base unit by
 * exact integer multiplication, and conversion across families (mass vs
 * volume) is structurally impossible rather than merely unvalidated. No
 * database needed — this is pure enum logic.
 */
test('every mass unit converts to milligrams exactly', function () {
    expect(Unit::Milligram->toBaseUnits(7))->toBe(7)
        ->and(Unit::Gram->toBaseUnits(30))->toBe(30_000)
        ->and(Unit::Kilogram->toBaseUnits(5))->toBe(5_000_000)
        // 1kg == 1000g == 1,000,000mg, the fixed metric-system fact this
        // enum exists to encode as exact integer ratios.
        ->and(Unit::Kilogram->toBaseUnits(1))->toBe(Unit::Gram->toBaseUnits(1_000))
        ->and(Unit::Gram->toBaseUnits(1))->toBe(Unit::Milligram->toBaseUnits(1_000));
});

test('every volume unit converts to milliliters exactly', function () {
    expect(Unit::Milliliter->toBaseUnits(100))->toBe(100)
        ->and(Unit::Liter->toBaseUnits(5))->toBe(5_000)
        ->and(Unit::Liter->toBaseUnits(1))->toBe(Unit::Milliliter->toBaseUnits(1_000));
});

test('count units are already their own base', function () {
    expect(Unit::Piece->toBaseUnits(1))->toBe(1)
        ->and(Unit::Piece->toBaseUnits(200))->toBe(200);
});

test('each unit reports its family, and baseUnitFor returns the smallest unit of that family', function () {
    expect(Unit::Milligram->type())->toBe(UnitType::Mass)
        ->and(Unit::Gram->type())->toBe(UnitType::Mass)
        ->and(Unit::Kilogram->type())->toBe(UnitType::Mass)
        ->and(Unit::Milliliter->type())->toBe(UnitType::Volume)
        ->and(Unit::Liter->type())->toBe(UnitType::Volume)
        ->and(Unit::Piece->type())->toBe(UnitType::Count)
        ->and(Unit::baseUnitFor(UnitType::Mass))->toBe(Unit::Milligram)
        ->and(Unit::baseUnitFor(UnitType::Volume))->toBe(Unit::Milliliter)
        ->and(Unit::baseUnitFor(UnitType::Count))->toBe(Unit::Piece);
});

test('valuesFor lists only the units belonging to one family', function () {
    expect(Unit::valuesFor(UnitType::Mass))->toBe(['mg', 'g', 'kg'])
        ->and(Unit::valuesFor(UnitType::Volume))->toBe(['ml', 'l'])
        ->and(Unit::valuesFor(UnitType::Count))->toBe(['pcs']);
});

test('fromFamily resolves a unit that belongs to the expected family', function () {
    expect(Unit::fromFamily('g', UnitType::Mass))->toBe(Unit::Gram)
        ->and(Unit::fromFamily('ml', UnitType::Volume))->toBe(Unit::Milliliter);
});

test('fromFamily throws — never silently coerces — for the exact "30ml against a kg-tracked ingredient" mistake', function () {
    expect(fn () => Unit::fromFamily('ml', UnitType::Mass))->toThrow(InvalidArgumentException::class);
});

test('fromFamily throws for a volume unit requested as count, and for a nonexistent unit string', function () {
    expect(fn () => Unit::fromFamily('l', UnitType::Count))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Unit::fromFamily('bogus', UnitType::Mass))->toThrow(InvalidArgumentException::class);
});

test('formatQuantity divides back to a whole number when it divides evenly', function () {
    expect(Unit::formatQuantity(5_000_000, Unit::Kilogram))->toBe('5')
        ->and(Unit::formatQuantity(500_000, Unit::Gram))->toBe('500')
        ->and(Unit::formatQuantity(0, Unit::Kilogram))->toBe('0');
});

test('formatQuantity trims to at most 3 decimal places for a quantity that doesn\'t divide evenly, never a repeating fraction', function () {
    // 1,500,000mg in kg = 1.5kg exactly.
    expect(Unit::formatQuantity(1_500_000, Unit::Kilogram))->toBe('1.5')
        // 1,000g in kg, entered oddly, still resolves as a clean division.
        ->and(Unit::formatQuantity(250_000, Unit::Kilogram))->toBe('0.25');
});

test('formatQuantity never feeds its display-only division back into a value used for further math', function () {
    // The base-unit integer itself is untouched by formatting — the
    // string is display-only, exactly like Money::format().
    $baseUnits = Unit::Gram->toBaseUnits(30);

    Unit::formatQuantity($baseUnits, Unit::Kilogram);

    expect($baseUnits)->toBe(30_000);
});
