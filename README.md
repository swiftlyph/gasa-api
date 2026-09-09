# GASA API

Laravel 12 REST API for the GASA platform — a modular monolith serving four
audiences (platform admin, company, employee, merchant) over `/api/v1`,
consumed cross-origin by separate frontend repos via Sanctum bearer tokens.

## Conventions

These conventions are load-bearing for every phase of this project. Read
them before adding routes, controllers, or domain code — this section is
the source of truth for anyone (human or AI) joining the repo.

### Route file per audience

Routes are split by audience under `routes/api/v1/`:

```
routes/api/v1/
├── admin.php      # platform_admin
├── company.php    # company_admin
├── employee.php   # employee
├── merchant.php   # merchant
└── public.php     # unauthenticated, no tenant/auth context
```

All five are registered in `bootstrap/app.php`, each wrapped in its own
named middleware group. A route belongs in the file matching who calls
it — never mix audiences in one file, and never add an authenticated
route to `public.php`. `admin.php`, `company.php`, `employee.php`, and
`merchant.php` are each prefixed with their own segment
(`api/v1/admin/...`, etc.); `public.php` is not, since it has no single
audience.

The per-audience middleware groups are filled:

| Group          | Middleware                          |
| -------------- | ------------------------------------ |
| `admin.api`    | `auth:sanctum`, `role:platform_admin`, `AllowsAdminContext` |
| `company.api`  | `auth:sanctum`, `role:company_admin`  |
| `employee.api` | `auth:sanctum`, `role:employee`       |
| `merchant.api` | `auth:sanctum`, `role:merchant`, `EnsureMerchantActive` |
| `public.api`   | *(none)*                              |

Order matters in the two tenant-aware groups. `AllowsAdminContext` runs
**after** `role:platform_admin`, so the tenancy bypass is only granted to a
request that already proved it's an admin. `EnsureMerchantActive` runs
**after** `role:merchant`, so a non-merchant gets a plain `forbidden`
rather than `merchant_inactive` — the latter would confirm the route exists
for merchants and invite probing.

Routes reachable by **any** authenticated role regardless of portal
(currently `/auth/me`, `/auth/logout`) live in `routes/api/v1/auth.php`,
registered once under `auth:sanctum` — never duplicated per audience file.

Each portal file also currently has a `GET /whoami` route returning
`{ "portal": "<name>" }` — a temporary placeholder proving its middleware
stack actually enforces the right role. It gets replaced by real endpoints
later; until then it's harmless and safe to leave in place.

### Domains folder rules

Domain code lives under `app/Domains/{Auth,Company,Merchant,Platform,Shared}`,
each with:

```
app/Domains/<Domain>/
├── Models/      # Eloquent models
├── Actions/     # single-purpose write/business-logic classes
├── Policies/    # authorization
└── Http/        # Controllers, FormRequests, Resources, Middleware
```

Rules:

- **Controllers are thin.** They validate via a FormRequest, call an
  Action, and return a Resource. No business logic in controllers.
- **Validation lives in FormRequests**, not in controllers or Actions.
- **Writes go through Action classes** (one action, one responsibility —
  e.g. `CreateCompanyAction`). Controllers never write to the database
  directly.
- **Authorization lives in Policies**, invoked via `$this->authorize()` or
  the `can` middleware — never inline `if ($user->role === ...)` checks in
  controllers.
- **Output goes through API Resources.** Controllers never return raw
  models or arrays built ad hoc.
- Code shared across domains (base traits, common middleware, generic
  Actions) lives in `Shared`, not duplicated per domain.

### Error shape

Every error response is JSON, always shaped:

```json
{
  "message": "Human-readable summary",
  "code": "machine_readable_code",
  "errors": { "field": ["Validation message"] }
}
```

`errors` is present only for validation failures. Unauthenticated requests
always return **401 JSON** — never a redirect to `/login`. This is enforced
globally: every `api/*` request forces an `Accept: application/json` header
and exceptions render as JSON via `bootstrap/app.php`, which delegates all
shaping to `App\Domains\Shared\Http\Exceptions\ApiExceptionRenderer` — the
**only** place API errors get turned into JSON. Any new error case must be
added there (or by throwing `ApiException`, which the renderer already
understands) rather than opening a second rendering path.

Proven live in `tests/Feature/Auth/WhoamiTest.php` and `MeAndLogoutTest.php`:
every protected route with no token returns 401 JSON with no `Location`
header — never a redirect.

#### Error codes (as of this phase)

| Status | `code`                | When                                                          |
| ------ | --------------------- | -------------------------------------------------------------- |
| 401    | `unauthenticated`      | No/invalid Sanctum token on a protected route                  |
| 401    | `invalid_credentials`  | `/auth/login` — email not found, or wrong password             |
| 403    | `portal_forbidden`     | `/auth/login` — valid credentials, but wrong `portal` for role  |
| 403    | `forbidden`            | Authenticated, but role/policy check failed (e.g. wrong portal's `/whoami`) |
| 403    | `merchant_inactive`    | Merchant portal, but the user has no merchant or theirs isn't `active` |
| 404    | `not_found`            | Route or model not found                                        |
| 422    | `validation_failed`    | FormRequest validation failure                                  |
| 429    | `too_many_attempts`    | `/auth/login` — 6th+ attempt from the same email+IP within a minute |
| 500    | `server_error`         | Unhandled exception (message hidden unless `APP_DEBUG=true`)    |

**Bad login credentials return 401, not 422** — chosen because "these
credentials are wrong" is an authentication failure, not a malformed
request; the fields themselves are syntactically valid. Missing/malformed
fields (e.g. `portal` not one of the four allowed values) still return
422 `validation_failed` via the `LoginRequest` FormRequest, since that
genuinely is a request-shape problem.

### Response shapes

`JsonResource::withoutWrapping()` is enabled globally
(`AppServiceProvider`), but it only flattens **single** resources — a
resource **collection with pagination** wraps regardless, because
Laravel forces a `data` key whenever there's extra `with()`/`additional`
data to merge in (pagination adds `links`/`meta`), independent of the
wrapping setting. So the actual contract, going forward:

- **Single resource** (`/auth/me`, `login`'s `user`, any future `show`
  endpoint): flat object — `{ id, name, email, ... }`, no `data` key.
- **Paginated list** (any future `index` endpoint): always
  `{ data: [...], links: {...}, meta: {...} }`.

This is why: don't "fix" a paginated endpoint that returns a wrapped
shape later — that's correct, expected Laravel behavior, not a
regression from the `withoutWrapping()` call above.

#### The user payload

`GET /auth/me` and `login`'s `user` return the same flat shape:

```json
{
  "id": 1,
  "name": "Merchant",
  "email": "merchant@gasa.test",
  "roles": ["merchant"],
  "merchant": { "id": 1, "name": "Merchant One", "status": "active" }
}
```

`merchant` is `null` for any user with no merchant membership (platform
admins, company admins, employees). It is **present regardless of status** —
a `pending` or `suspended` merchant still gets the object, with the status
that applies. That's deliberate: a suspended merchant is blocked from every
merchant route with 403 `merchant_inactive`, but `/auth/me` keeps working so
the frontend can read `merchant.status` and render a suspended screen rather
than bouncing the user back to login.

### CORS

Allowed origins are driven by the comma-separated `FRONTEND_ORIGINS` env var
(default `http://localhost:5173`). Each frontend portal repo adds its own
origin there — no code change needed. Auth is bearer-token only (Sanctum
personal access tokens); there are no cookies and no stateful domains, so
`supports_credentials` stays `false`, and `config/sanctum.php`'s `guard`
list is deliberately empty (no `web`/session guard is ever checked before
the bearer token).

### Auth

One `users` table (`app/Domains/Auth/Models/User.php`), no registration —
companies and merchants get provisioned in later phases. Four fixed
spatie/laravel-permission roles, one per portal:

| Portal     | Role             |
| ---------- | ---------------- |
| `admin`    | `platform_admin` |
| `company`  | `company_admin`  |
| `employee` | `employee`       |
| `merchant` | `merchant`       |

`POST /api/v1/auth/login` takes `{ email, password, portal }`. It checks
credentials **and** that the user's role is permitted for the given
`portal` — valid credentials against the wrong portal is a 403
`portal_forbidden`, not a login success (see the error-codes table
above). On success it returns a Sanctum token named after the portal
(so `/auth/logout` only ever revokes that one token) plus the user. Login
is rate-limited to 5/minute keyed on `email+IP`
(`RateLimiter::for('login', ...)` in `AppServiceProvider`).

`GET /api/v1/auth/me` and `POST /api/v1/auth/logout` are shared by every
authenticated role (`routes/api/v1/auth.php`, `auth:sanctum` only, no
role check) — logout revokes only the token used for that request, never
the user's other sessions.

Login logic lives in `app/Domains/Auth/Actions/LoginAction.php` — the
controller (`AuthController`) stays thin. No role checks live inside any
controller: portal enforcement at login is `LoginAction`'s job, and route
access is enforced entirely by the `role:` middleware.

Email is stored case-insensitively: on PostgreSQL via the `citext`
extension, on every other driver (sqlite in tests) via a plain string
plus a unique index on `lower(email)`. Application code always lowercases
email before lookup, so behavior is identical regardless of which layer
is actually enforcing it.

Seed data: `php artisan db:seed` runs `RoleSeeder` (the four roles, safe
in every environment) and, outside production, `DevSeeder` — one account
per role, idempotent (`updateOrCreate` by email, safe to re-run):

| Email                  | Password   | Role             |
| ----------------------- | ---------- | ---------------- |
| `admin@gasa.test`       | `password` | `platform_admin` |
| `company@gasa.test`     | `password` | `company_admin`  |
| `employee@gasa.test`    | `password` | `employee`       |
| `merchant@gasa.test`    | `password` | `merchant`       |
| `merchant2@gasa.test`   | `password` | `merchant`       |
| `suspended@gasa.test`   | `password` | `merchant`       |

The three merchant accounts each own one merchant, which is what makes
cross-tenant behavior checkable by hand:

| Account                 | Merchant             | Status      |
| ----------------------- | -------------------- | ----------- |
| `merchant@gasa.test`    | Merchant One         | `active`    |
| `merchant2@gasa.test`   | Merchant Two         | `active`    |
| `suspended@gasa.test`   | Suspended Merchant   | `suspended` |

Two active merchants exist on purpose: with only one, correct scoping and
no scoping at all look identical. `suspended@gasa.test` drives the 403
`merchant_inactive` path and the frontend's suspended screen.

## Tenancy

Merchant is the first tenant type. Tenant identity **always** derives from
the authenticated user — nothing in the tenancy path reads request input,
so a `merchant_id` in a payload is never authoritative.

Any model with a `merchant_id` column gets automatic scoping by applying
`App\Domains\Shared\Concerns\BelongsToMerchant`, which:

1. adds a global scope restricting every query to the authenticated user's
   **active** merchant;
2. stamps `merchant_id` on create, **overwriting** whatever was
   mass-assigned;
3. bypasses both, and only, in platform-admin context.

Three rules that are load-bearing rather than incidental:

- **No tenant means no rows, never all rows.** An unauthenticated request,
  a company admin, or a merchant whose account is pending/suspended matches
  *zero* rows — the scope applies an always-false condition rather than
  skipping itself. A missing tenant must never silently widen a query.
- **The admin bypass is context, not role.** `AllowsAdminContext` sets a
  flag on the `admin.api` group; the trait checks only that flag and never
  asks whether a user is an admin. A platform admin hitting a merchant
  route goes through `merchant.api`, never gets the flag, and stays scoped
  like anyone else (in practice they get a 403 from `role:merchant` first).
- **Tenant resolution is lazy.** A global scope is registered once per
  model class per process, but the authenticated user differs per request,
  so the merchant id is read at query time — never captured at boot.

`User::merchant()` returns the user's single **active** merchant or null;
membership itself is the `merchant_user` pivot, never a column on `users`,
so a user can gain merchant team members later without a schema change.

All of this is enforced by `tests/Feature/TenantLeakageTest.php`, which is
permanent and only grows: every later phase that adds a tenant-owned table
adds its cases there rather than starting a new file. Because no
merchant-owned domain tables exist yet, the trait is tested against a
test-only fixture (`tests/Fixtures/`) rather than a premature domain model.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# one-time: the dedicated test database (prompts for the gasa password)
createdb -h 127.0.0.1 -U gasa -O gasa gasa_api_test

php artisan migrate --seed   # needs a real Postgres connection (see below)
composer test                # runs the Pest suite against gasa_api_test
composer lint                # Pint, style check only
composer analyse              # Larastan static analysis
```

The app expects PostgreSQL 16 (`DB_CONNECTION=pgsql`) and Redis for cache
and queues — `php artisan migrate` needs a database that actually exists
and a role with privileges on it.

### The test database

**The Pest suite runs against real PostgreSQL, not SQLite.** It uses a
dedicated `gasa_api_test` database (see `phpunit.xml`), which
`RefreshDatabase` drops every table in on each run — so it must never
point at a database holding anything you care about, least of all the
`gasa` development database.

This is deliberate. On SQLite the test database would differ from
production in exactly the places auth and tenancy depend on:

| Behavior | PostgreSQL | SQLite |
| -------- | ---------- | ------ |
| `merchants.status` CHECK constraint | enforced | absent |
| `citext` case-insensitive email | enforced by the column | emulated by a `lower(email)` index |
| `LIKE` case sensitivity | case-sensitive | case-insensitive |

Green tests against SQLite would have been green against a *different*
database than the deployed one. Running on Postgres means a constraint
violation fails in CI rather than in production.

The `phpunit.xml` values are defaults, not hardcoded — PHPUnit's `env`
elements don't override a real environment variable unless marked
`force`, so CI can point `DB_HOST`/`DB_DATABASE` at its own Postgres
service without editing the file.

---

Built on the [Laravel](https://laravel.com) framework. See the
[Laravel documentation](https://laravel.com/docs) for framework-level
concepts not covered above.
