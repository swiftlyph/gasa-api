<?php

namespace App\Domains\Allowance\Http\Resources;

use App\Domains\Allowance\Models\AllowanceAccount;
use App\Domains\Allowance\Models\AllowanceLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin AllowanceAccount */
class AllowanceAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'purse' => $this->purse,
            'balance_cents' => $this->balance_cents ?? 0,
            'transactions' => $this->when(
                $this->resource->relationLoaded('recentEntries'),
                function (): array {
                    /** @var Collection<int, AllowanceLedgerEntry> $entries */
                    $entries = $this->resource->getRelation('recentEntries');

                    return AllowanceLedgerEntryResource::collection($entries)->resolve();
                },
                [],
            ),
        ];
    }
}
