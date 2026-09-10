<?php

namespace App\Domains\Merchant\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\EnsureDefaultRegisterAction;
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
 * @property string|null $legal_name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $contact_email
 * @property string|null $tax_identifier
 * @property string|null $receipt_header
 * @property string|null $receipt_footer
 * @property string|null $timezone
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
     * Every merchant gets a default register the moment it exists — P4's
     * DefaultRegister no longer has to tolerate a merchant with none as
     * the common case, only as a defensive fallback (see
     * NoRegisterConfigured's docblock). Runs on every `created` merchant
     * regardless of how it was made (factory, seeder, future admin
     * provisioning), so there is exactly one place this guarantee lives.
     */
    protected static function booted(): void
    {
        static::created(function (Merchant $merchant): void {
            app(EnsureDefaultRegisterAction::class)->execute($merchant);
        });
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
