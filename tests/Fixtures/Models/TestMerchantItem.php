<?php

namespace Tests\Fixtures\Models;

use App\Domains\Shared\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;

/**
 * TEST FIXTURE — never shipped, never referenced by application code.
 *
 * BelongsToMerchant needs a merchant-owned table to be tested against, but
 * no real merchant-owned domain tables exist yet (they arrive next phase).
 * Testing the trait through a premature domain model would couple the
 * tenancy tests to a schema still being designed; this stand-in stays
 * deliberately trivial so the tests only ever assert trait behavior.
 *
 * Its table is created by tests/Fixtures/create_test_merchant_items_table.php,
 * loaded only by the test suite.
 */
class TestMerchantItem extends Model
{
    use BelongsToMerchant;

    protected $table = 'test_merchant_items';

    /**
     * `merchant_id` is intentionally fillable so the tests can attempt to
     * mass-assign a spoofed tenant id and prove the trait overwrites it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'merchant_id',
    ];
}
