<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Http\Resources\AuditLogResource;
use App\Domains\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/merchants/{merchant} and the 201 response of
 * POST /admin/merchants. Full profile + owner + team + registers +
 * status history (drawn from audit_logs, see AdminMerchantController).
 *
 * $statusHistory is passed in rather than loaded here for the same
 * reason TeamMemberResource takes $merchant as a constructor arg: a
 * JsonResource has no natural place to run an extra query itself without
 * the controller losing visibility into (and control over) how many
 * queries the endpoint runs.
 *
 * @mixin Merchant
 */
class AdminMerchantDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, AuditLog>  $statusHistory
     */
    public function __construct(Merchant $merchant, private readonly Collection $statusHistory)
    {
        parent::__construct($merchant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,

            'legal_name' => $this->legal_name,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'contact_email' => $this->contact_email,
            'tax_identifier' => $this->tax_identifier,
            'receipt_header' => $this->receipt_header,
            'receipt_footer' => $this->receipt_footer,
            'timezone' => $this->timezone,

            'owner' => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => (string) $this->owner->email,
            ],

            'team' => $this->users->map(function ($member) {
                /** @var Pivot $pivot */
                $pivot = $member->getAttribute('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => (string) $member->email,
                    'role_in_merchant' => RoleInMerchant::from($pivot->getAttribute('role_in_merchant'))->value,
                    'is_owner' => $member->id === $this->owner_user_id,
                ];
            })->values(),

            'registers' => $this->registers->map(fn ($register) => [
                'id' => $register->id,
                'name' => $register->name,
                'is_active' => $register->is_active,
            ])->values(),

            'status_history' => AuditLogResource::collection($this->statusHistory),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
