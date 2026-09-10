<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: gives a merchant an identity to print on a receipt and a place to
 * hold its own contact/tax details. `name` (existing) stays the display
 * name; everything here is optional profile detail a merchant fills in
 * after signup, never required at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();

            // COLUMN ONLY — deliberately NOT wired into MerchantDay this
            // phase. MerchantDay::timezone() still resolves solely from
            // config('merchant.day_timezone'); reading this column there
            // would let some endpoints (already migrated) split day
            // boundaries from others (not yet) depending on rollout order,
            // which is exactly the UTC-vs-local class of bug P6 fixed. A
            // dedicated later phase migrates MerchantDay to read this
            // column (backfilled from config first) in one atomic change,
            // not a gradual one. See README § Merchant profile.
            $table->string('timezone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'address_line1',
                'address_line2',
                'city',
                'postal_code',
                'phone',
                'contact_email',
                'tax_identifier',
                'receipt_header',
                'receipt_footer',
                'timezone',
            ]);
        });
    }
};
