<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Exceptions\InvalidImportFile;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use App\Domains\Company\Models\Employee;
use App\Domains\Company\Support\EmployeeFieldRules;
use App\Domains\Company\Support\PhoneNumber;
use App\Domains\Shared\Http\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SplFileObject;
use Throwable;

/**
 * Imports a roster CSV via POST /company/employees/import, in two modes
 * that run the SAME code: `preview` does everything inside a transaction
 * and rolls it back, `commit` commits it. A preview can therefore never
 * promise something the commit then does differently, which is the whole
 * value of previewing.
 *
 * Every row goes through the same rules (EmployeeFieldRules) and the same
 * Actions (Create/UpdateEmployeeAction) as the form, so nothing gets in
 * through a file that the API would otherwise refuse.
 *
 * Rows are UPSERTS: matched to an existing employee by employee_no when
 * the row has one, else by email; otherwise created. A column that is in
 * the file is authoritative, so an empty cell clears that field on an
 * update; a column that is not in the file is left alone. Status is never
 * imported: pausing or separating someone stays a deliberate, per-person
 * act in the portal.
 *
 * Problems are PER ROW: a bad row is reported `invalid` and skipped, the
 * rest still import. Only a file that cannot be read at all (no header,
 * a required column missing, too many rows) is refused outright.
 *
 * Each row writes inside its own savepoint, so a row that fails cannot
 * poison the surrounding transaction (on Postgres a failed statement
 * aborts the whole transaction otherwise).
 */
class ImportEmployeesAction
{
    public const MAX_ROWS = 1000;

    /**
     * The columns an import understands, in the order the export writes
     * them, so an exported file re-imports as-is.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'employee_no',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'mobile',
        'department',
        'job_title',
        'employment_type',
        'hired_at',
        'birthdate',
    ];

    private const REQUIRED_COLUMNS = ['first_name', 'last_name', 'email'];

    /**
     * Headers an HR export is likely to use, mapped to ours.
     */
    private const COLUMN_ALIASES = [
        'employee_number' => 'employee_no',
        'employee_id' => 'employee_no',
        'id_number' => 'employee_no',
        'firstname' => 'first_name',
        'given_name' => 'first_name',
        'middlename' => 'middle_name',
        'lastname' => 'last_name',
        'surname' => 'last_name',
        'family_name' => 'last_name',
        'email_address' => 'email',
        'mobile_number' => 'mobile',
        'phone' => 'mobile',
        'contact_number' => 'mobile',
        'position' => 'job_title',
        'title' => 'job_title',
        'type' => 'employment_type',
        'date_hired' => 'hired_at',
        'hire_date' => 'hired_at',
        'birthday' => 'birthdate',
        'date_of_birth' => 'birthdate',
    ];

    public function __construct(
        private readonly CreateEmployeeAction $createEmployee,
        private readonly UpdateEmployeeAction $updateEmployee,
    ) {}

    /**
     * @return array{
     *     mode: string,
     *     summary: array{total: int, create: int, update: int, unchanged: int, invalid: int},
     *     ignored_columns: list<string>,
     *     rows: list<array<string, mixed>>,
     * }
     *
     * @throws InvalidImportFile
     */
    public function execute(Company $company, string $path, bool $commit): array
    {
        $file = $this->read($path);

        $rows = [];
        $seenEmails = [];
        $seenNumbers = [];

        DB::beginTransaction();

        try {
            foreach ($file['records'] as $record) {
                $rows[] = $this->importRow($company, $record['line'], $record['values'], $seenEmails, $seenNumbers);
            }

            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $count = fn (string $action): int => count(array_filter($rows, fn (array $row) => $row['action'] === $action));

        return [
            'mode' => $commit ? 'commit' : 'preview',
            'summary' => [
                'total' => count($rows),
                'create' => $count('create'),
                'update' => $count('update'),
                'unchanged' => $count('unchanged'),
                'invalid' => $count('invalid'),
            ],
            'ignored_columns' => $file['ignored_columns'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  array<string, int>  $seenEmails  email => first line, across the whole file
     * @param  array<string, int>  $seenNumbers  employee_no => first line
     * @return array<string, mixed>
     */
    private function importRow(Company $company, int $line, array $values, array &$seenEmails, array &$seenNumbers): array
    {
        $values = $this->clean($values);

        $validator = Validator::make($values, EmployeeFieldRules::forImportRow(), EmployeeFieldRules::messages());

        if ($validator->fails()) {
            return $this->row($line, 'invalid', $values, $validator->errors()->toArray());
        }

        /**
         * The validated row, typed at the validation boundary exactly the
         * way the FormRequests type their payload().
         *
         * @var array{
         *     employee_no?: string|null,
         *     first_name: string,
         *     middle_name?: string|null,
         *     last_name: string,
         *     suffix?: string|null,
         *     email: string,
         *     mobile?: string|null,
         *     department?: string|null,
         *     job_title?: string|null,
         *     employment_type?: string,
         *     hired_at?: string|null,
         *     birthdate?: string|null,
         * } $payload
         */
        $payload = $validator->validated();

        $email = $payload['email'];
        $employeeNo = $payload['employee_no'] ?? null;

        // A roster that lists the same person twice is a mistake in the
        // file; the second occurrence is refused rather than silently
        // overwriting the first.
        if (isset($seenEmails[$email])) {
            return $this->row($line, 'invalid', $values, [
                'email' => ["This email already appears on line {$seenEmails[$email]} of the file."],
            ]);
        }

        if ($employeeNo !== null && isset($seenNumbers[$employeeNo])) {
            return $this->row($line, 'invalid', $values, [
                'employee_no' => ["This employee number already appears on line {$seenNumbers[$employeeNo]} of the file."],
            ]);
        }

        $seenEmails[$email] = $line;

        if ($employeeNo !== null) {
            $seenNumbers[$employeeNo] = $line;
        }

        try {
            $action = DB::transaction(fn (): string => $this->upsert($company, $payload));
        } catch (ApiException $e) {
            return $this->row($line, 'invalid', $values, $e->errors() ?? ['row' => [$e->getMessage()]]);
        }

        return $this->row($line, $action, $values, null);
    }

    /**
     * @param  array{
     *     employee_no?: string|null,
     *     first_name: string,
     *     middle_name?: string|null,
     *     last_name: string,
     *     suffix?: string|null,
     *     email: string,
     *     mobile?: string|null,
     *     department?: string|null,
     *     job_title?: string|null,
     *     employment_type?: string,
     *     hired_at?: string|null,
     *     birthdate?: string|null,
     * }  $row
     * @return 'create'|'update'|'unchanged'
     */
    private function upsert(Company $company, array $row): string
    {
        // The file's department NAME becomes the payload's department_id,
        // but only when the file has that column at all: a file without it
        // must leave everyone's department alone.
        $hasDepartmentColumn = array_key_exists('department', $row);
        $departmentName = $row['department'] ?? null;
        unset($row['department']);

        $payload = $row;

        if ($hasDepartmentColumn) {
            $payload['department_id'] = $this->departmentIdFor($company, $departmentName);
        }

        $existing = $this->findExisting($company, $payload['email'], $payload['employee_no'] ?? null);

        if ($existing === null) {
            $this->createEmployee->execute($company, $payload);

            return 'create';
        }

        if (! $existing->fill($payload)->isDirty()) {
            return 'unchanged';
        }

        $this->updateEmployee->execute($existing, $payload);

        return 'update';
    }

    /**
     * By employee number when the row has one and it matches, else by
     * email (already lowercased by clean()).
     */
    private function findExisting(Company $company, string $email, ?string $employeeNo): ?Employee
    {
        $roster = Employee::query()->where('company_id', $company->getKey());

        if ($employeeNo !== null) {
            $byNumber = (clone $roster)->where('employee_no', $employeeNo)->first();

            if ($byNumber !== null) {
                return $byNumber;
            }
        }

        return (clone $roster)->where('email', $email)->first();
    }

    /**
     * A file names its departments; the importer finds each one
     * case-insensitively or creates it, so a first import can bring a
     * company's whole structure in at once.
     */
    private function departmentIdFor(Company $company, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        $department = Department::query()
            ->where('company_id', $company->getKey())
            ->named($name)
            ->first();

        $department ??= Department::create([
            'company_id' => $company->getKey(),
            'name' => $name,
        ]);

        return $department->getKey();
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    private function clean(array $values): array
    {
        foreach ($values as $column => $value) {
            $value = $value === null ? null : trim($value);

            // Spreadsheets prefix an apostrophe to force "text"; our own
            // export does it to neutralise formula-looking cells.
            if ($value !== null && str_starts_with($value, "'")) {
                $value = ltrim($value, "'");
            }

            $values[$column] = $value === '' ? null : $value;
        }

        if (isset($values['email'])) {
            $values['email'] = mb_strtolower($values['email']);
        }

        if (isset($values['mobile'])) {
            $values['mobile'] = PhoneNumber::normalize($values['mobile']);
        }

        if (isset($values['employment_type'])) {
            $values['employment_type'] = str_replace([' ', '-'], '_', mb_strtolower($values['employment_type']));
        } else {
            // An empty cell means "not stated", not "set it to nothing":
            // a new row gets the default, an existing one keeps its type.
            unset($values['employment_type']);
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  array<string, array<int, string>>|null  $errors
     * @return array<string, mixed>
     */
    private function row(int $line, string $action, array $values, ?array $errors): array
    {
        return [
            'line' => $line,
            'action' => $action,
            'employee_no' => $values['employee_no'] ?? null,
            'first_name' => $values['first_name'] ?? null,
            'last_name' => $values['last_name'] ?? null,
            'email' => $values['email'] ?? null,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{records: list<array{line: int, values: array<string, string|null>}>, ignored_columns: list<string>}
     *
     * @throws InvalidImportFile
     */
    private function read(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        // Escape set explicitly (to none): PHP 8.4 deprecates relying on
        // its default, and RFC 4180 CSV has no escape character anyway.
        $file->setCsvControl(',', '"', '');

        $columns = null;
        $ignored = [];
        $records = [];

        foreach ($file as $index => $cells) {
            if (! is_array($cells) || $this->isBlank($cells)) {
                continue;
            }

            if ($columns === null) {
                [$columns, $ignored] = $this->mapHeader($cells);

                continue;
            }

            if (count($records) >= self::MAX_ROWS) {
                throw new InvalidImportFile('The file has more than '.self::MAX_ROWS.' rows. Split it and import each part.');
            }

            $values = [];

            foreach ($columns as $position => $column) {
                if ($column !== null && ! array_key_exists($column, $values)) {
                    $cell = $cells[$position] ?? null;
                    $values[$column] = $cell === null ? null : (string) $cell;
                }
            }

            $records[] = ['line' => (int) $index + 1, 'values' => $values];
        }

        if ($columns === null) {
            throw new InvalidImportFile('The file is empty. It needs a header row and at least one employee.');
        }

        return ['records' => $records, 'ignored_columns' => $ignored];
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array{0: array<int, string|null>, 1: list<string>}
     *
     * @throws InvalidImportFile
     */
    private function mapHeader(array $cells): array
    {
        $columns = [];
        $ignored = [];

        foreach ($cells as $position => $cell) {
            $typed = trim((string) $cell);

            // Excel writes a UTF-8 byte-order mark before the first header.
            if ($position === 0) {
                $typed = preg_replace('/^\xEF\xBB\xBF/', '', $typed) ?? $typed;
            }

            $name = str_replace([' ', '-', '.'], '_', mb_strtolower($typed));
            $name = self::COLUMN_ALIASES[$name] ?? $name;

            if (in_array($name, self::COLUMNS, true)) {
                $columns[$position] = $name;
            } else {
                $columns[$position] = null;

                if ($typed !== '') {
                    $ignored[] = $typed;
                }
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_filter($columns)));

        if ($missing !== []) {
            throw new InvalidImportFile('The file is missing required columns: '.implode(', ', $missing).'.');
        }

        return [$columns, $ignored];
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
