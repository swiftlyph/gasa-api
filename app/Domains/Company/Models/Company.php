<?php

namespace App\Domains\Company\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The company tenant: the employer whose admins run the company portal
 * and whose employees will spend allowance at merchants. Shaped like
 * Merchant on purpose (a display name, a status, an owner who is the
 * first company admin, nullable profile columns) so the two tenant
 * types read alike.
 *
 * Membership is users.company_id, not a pivot; see the
 * add_company_foreign_key_to_users_table migration for why.
 *
 * @property int $id
 * @property string $name
 * @property CompanyStatus $status
 * @property int $owner_user_id
 * @property string|null $legal_name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $contact_email
 * @property string|null $tax_identifier
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /**
     * Laravel guesses the factory from the model's namespace tail, which
     * doesn't exist under our Domains layout. Point it at the real one.
     */
    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Every user attached to this company via users.company_id: admins
     * now, employees with a portal account once the invite phase lands.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }
}
