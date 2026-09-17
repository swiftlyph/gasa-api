<?php

namespace App\Domains\Catalog\Enums;

/**
 * A measurement family. This is THE thing that makes unit conversion
 * safe: two units convert into one another only when they share a
 * UnitType, and there is no code path anywhere that converts across
 * families (mass <-> volume needs a density, which is per-ingredient and
 * not something this system will ever guess at — see Unit's docblock).
 */
enum UnitType: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';
}
