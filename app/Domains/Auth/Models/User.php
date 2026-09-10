<?php

namespace App\Domains\Auth\Models;

use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Support\RolePresets;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
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

    /**
     * This user's role_in_merchant on their single ACTIVE merchant, or
     * null when merchant() is null (no active merchant at all).
     *
     * Reads the pivot off the SAME row merchant() already resolved and
     * memoized, rather than issuing a second query — the pivot is always
     * loaded alongside the related model on a BelongsToMany, so no extra
     * round-trip is needed.
     */
    public function roleInMerchant(): ?RoleInMerchant
    {
        $merchant = $this->merchant();

        if ($merchant === null) {
            return null;
        }

        // Merchant declares no `pivot` property of its own — same reason
        // TeamMemberResource reads it via getAttribute() rather than
        // magic property access (see its docblock), one level up:
        // Model::getAttribute() here (not Pivot::getAttribute(), since
        // PHPStan already knows $merchant's own type and would flag
        // ->pivot the same way it flags the property) returns a plain
        // `mixed`, so this second getAttribute() call needs one too.
        /** @var Pivot $pivot */
        $pivot = $merchant->getAttribute('pivot');

        /** @var string $role */
        $role = $pivot->getAttribute('role_in_merchant');

        return RoleInMerchant::from($role);
    }

    /**
     * This user's merchant permissions, resolved from role_in_merchant
     * via RolePresets. Empty (never null) when there is no active
     * merchant — a user with no permissions and a user with no merchant
     * both mean "can do nothing here," and callers (e.g. UserResource)
     * shouldn't need to special-case which one it was.
     *
     * Memoized the same way merchant() is: a merchant-portal request may
     * check several permissions in one policy chain (tenant ownership,
     * then a specific ability), and each check would otherwise re-derive
     * the same set.
     *
     * @return array<int, string>
     */
    public function merchantPermissions(): array
    {
        if ($this->resolvedPermissions === null) {
            $role = $this->roleInMerchant();

            $this->resolvedPermissions = $role === null ? [] : RolePresets::valuesFor($role);
        }

        return $this->resolvedPermissions;
    }

    public function hasMerchantPermission(MerchantPermission $permission): bool
    {
        return in_array($permission->value, $this->merchantPermissions(), true);
    }

    private ?Merchant $resolvedMerchant = null;

    private bool $merchantResolved = false;

    /**
     * @var array<int, string>|null
     */
    private ?array $resolvedPermissions = null;
}
