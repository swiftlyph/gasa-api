<?php

namespace App\Domains\Merchant\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantStatus;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property MerchantStatus $status
 * @property int $owner_user_id
 */
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
        'owner_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MerchantStatus::class,
        ];
    }

    /**
     * Laravel guesses the factory from the model's namespace tail, which
     * doesn't exist under our Domains layout. Point it at the real one.
     */
    protected static function newFactory(): MerchantFactory
    {
        return MerchantFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role_in_merchant')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === MerchantStatus::Active;
    }
}
