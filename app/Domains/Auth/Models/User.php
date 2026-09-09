<?php

namespace App\Domains\Auth\Models;

use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Laravel guesses the factory class from the model's namespace tail
     * (App\Domains\Auth\Models\User → Database\Factories\Domains\Auth\
     * Models\UserFactory), which doesn't exist under our Domains layout.
     * Point it at the real one explicitly.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * Tenant identity always derives from the authenticated user, never
     * from request input — company_id is set by domain code in the
     * tenancy phase, never mass-assigned from a request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Merchant membership is a pivot, never a column on users — a user may
     * later belong to several merchants as a team member.
     *
     * @return BelongsToMany<Merchant, $this>
     */
    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class)
            ->withPivot('role_in_merchant')
            ->withTimestamps();
    }

    /**
     * The user's single ACTIVE merchant, or null.
     *
     * One merchant per user for now; the pivot already supports more, so
     * this deliberately returns the first active one rather than assuming
     * exactly one exists. Pending and suspended merchants resolve to null,
     * which is what makes BelongsToMerchant match nothing and
     * EnsureMerchantActive return 403 for those accounts.
     *
     * Memoized: BelongsToMerchant calls this on every scoped query, so an
     * unmemoized version would issue a DB round-trip per query. Null is
     * cached too (via the separate flag) so the no-merchant case doesn't
     * re-query either.
     */
    public function merchant(): ?Merchant
    {
        if (! $this->merchantResolved) {
            $this->resolvedMerchant = $this->merchants()
                ->where('merchants.status', MerchantStatus::Active->value)
                ->first();

            $this->merchantResolved = true;
        }

        return $this->resolvedMerchant;
    }

    private ?Merchant $resolvedMerchant = null;

    private bool $merchantResolved = false;
}
