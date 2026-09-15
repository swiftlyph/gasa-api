<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Merchant\Models\Merchant;

/**
 * Updates the merchant profile fields (legal name, address, contact
 * details, receipt copy). $payload comes from
 * UpdateMerchantProfileRequest::profilePayload(), which validates only
 * those fields — never status, owner_user_id, id, name or timezone — so
 * $merchant->update($payload) is safe here even though all of those are
 * $fillable on Merchant: this Action never builds the write payload from
 * raw request input, only from a FormRequest's validated() output, so
 * there is nothing beyond the nine profile fields for it to write.
 */
class UpdateMerchantProfileAction
{
    /**
     * @param  array{
     *     legal_name?: string|null,
     *     address_line1?: string|null,
     *     address_line2?: string|null,
     *     city?: string|null,
     *     postal_code?: string|null,
     *     phone?: string|null,
     *     contact_email?: string|null,
     *     tax_identifier?: string|null,
     *     receipt_header?: string|null,
     *     receipt_footer?: string|null,
     *     vat_registered?: bool,
     * }  $payload
     */
    public function execute(Merchant $merchant, array $payload): Merchant
    {
        $merchant->update($payload);

        // refresh(), not fresh(): fresh() returns ?static (null if the
        // row somehow no longer exists), which would make this method's
        // non-nullable return type a lie to phpstan. refresh() re-pulls
        // the row into the same instance and always returns static.
        return $merchant->refresh();
    }
}
