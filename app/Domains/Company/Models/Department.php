<?php

namespace App\Domains\Company\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of a company's departments: a name employees are grouped under, so
 * that allowance (and later reporting) can target a group instead of a
 * list of people. Tenant-owned through BelongsToCompany, so another
 * company's department id is a 404, and an employee can only ever be put
 * in one of its own company's departments (DepartmentOwnership checks).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * company_id is fillable for the same reason it is on Employee: the
     * trait overwrites it outside admin context, and seeders/factories
     * need to set it explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
    ];

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    /**
     * Non-deleted employees only (Employee soft-deletes), which is the
     * right question for both the list's head count and "can this
     * department be deleted".
     *
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Case-insensitive name match, the same comparison the unique index
     * (company_id, lower(name)) makes, so an application check and the
     * database can never disagree about what counts as a duplicate.
     *
     * @param  Builder<Department>  $query
     */
    public function scopeNamed(Builder $query, string $name): void
    {
        $query->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
    }
}
