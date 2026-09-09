<?php

namespace App\Domains\CashSessions\Http\Resources;

use App\Domains\CashSessions\Models\Register;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Register
 */
class RegisterResource extends JsonResource
{
    /**
     * $defaultRegisterId is passed in by RegisterController rather than
     * resolved per-row via DefaultRegister::for() here — that would be one
     * extra query per register in the list, when the controller already
     * knows the answer once for the whole response.
     */
    public function __construct(Register $register, private readonly ?int $defaultRegisterId = null)
    {
        parent::__construct($register);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'is_default' => $this->defaultRegisterId !== null && $this->id === $this->defaultRegisterId,
        ];
    }
}
