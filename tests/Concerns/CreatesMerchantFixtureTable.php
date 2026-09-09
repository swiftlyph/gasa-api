<?php

namespace Tests\Concerns;

use Illuminate\Database\Migrations\Migration;

/**
 * Runs the test-only merchant fixture migration.
 *
 * The fixture lives outside database/migrations so it can never reach a
 * real database, which means RefreshDatabase won't pick it up — tests that
 * need the table opt in by using this trait and calling
 * createMerchantFixtureTable() in beforeEach.
 */
trait CreatesMerchantFixtureTable
{
    protected function createMerchantFixtureTable(): void
    {
        /** @var Migration $migration */
        $migration = require __DIR__.'/../Fixtures/create_test_merchant_items_table.php';

        $migration->up();
    }
}
