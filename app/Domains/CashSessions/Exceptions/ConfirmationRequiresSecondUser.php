<?php

namespace App\Domains\CashSessions\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The user confirming a remittance is the same user who created it.
 *
 * 403, not 422: the request is asking to do something this user is not
 * PERMITTED to do — confirm their own remittance — rather than something
 * malformed. Segregation of duties is the entire control a remittance
 * provides (see the cash_remittances migration); letting the creator also
 * confirm would make "confirmed" mean nothing more than "created," so this
 * is enforced here rather than left as a UI convention a client could skip.
 */
class ConfirmationRequiresSecondUser extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'A remittance must be confirmed by someone other than the person who created it.',
            'confirmation_requires_second_user',
            403,
        );
    }
}
