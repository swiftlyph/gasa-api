<?php

namespace App\Domains\Platform\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Deactivating a user who owns a merchant.
 *
 * `merchants.owner_user_id` is RESTRICT-on-delete, and every permission
 * an owner holds is keyed off that column plus their `role_in_merchant`
 * pivot. Deactivating them would leave a live merchant whose owner
 * cannot sign in — the same split-brain state CannotDemoteOwner blocks
 * on the merchant side, reached from the admin side instead.
 *
 * The merchant must be suspended, or ownership transferred, first.
 */
class UserOwnsMerchant extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'This user owns a merchant and cannot be deactivated. Suspend the merchant first.',
            'user_owns_merchant',
            422,
        );
    }
}
