<?php

namespace App\Domains\Company\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Shared\Concerns\BelongsToCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One row of a company's roster: an HR record, NOT a login. user_id is
 * null until a later phase invites the employee into the employee portal
 * (the way a merchant's profile shipped before its team did), and that
 * phase's Action sets it. It is deliberately absent from $fillable so no
 * create()/update() anywhere can link an account by accident.
 *
 * Tenant-owned through BelongsToCompany: every query is scoped to the
 * caller's active company, so another company's employee id is a 404,
 * never a 403. company_id IS fillable: the trait overwrites it outside
 * admin context, and seeders/factories (which run in admin context)
 * need to set it explicitly, the same arrangement every merchant-owned
 * model has.
 *
 * Soft-deleted, never hard-deleted: removing an employee keeps the row
 * for whatever later references it (a wallet ledger), and the partial
 * unique indexes let a removed employee's email/employee_no be reused.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string|null $employee_no
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $mobile
 * @property string|null $department
 * @property string|null $job_title
 * @property Carbon|null $hired_at
 * @property EmployeeStatus $status
 * @property Carbon|null $deleted_at
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_no',
        'first_name',
        'last_name',
        'email',
        'mobile',
        'department',
        'job_title',
        'hired_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'status' => EmployeeStatus::class,
        ];
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    /**
     * The linked portal account, once invited. Null this phase.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
