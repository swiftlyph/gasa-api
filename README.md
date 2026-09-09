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

Both `role:` and `EnsureMerchantActive` are also registered in the
**middleware priority list** (`bootstrap/app.php`) ahead of
`SubstituteBindings`, so they run before route-model binding. Without that,
Laravel resolves `{order}` first, and a suspended merchant hitting
`/merchant/orders/{order}` gets a `404` (their tenant is null, so the
scoped lookup matches nothing) while `/merchant/orders` correctly returns
`403 merchant_inactive` — the same middleware group giving two different
answers depending on whether the route has a parameter. Add any future
gatekeeper middleware to that list too.

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

### Model safety

`Model::preventSilentlyDiscardingAttributes()` is enabled globally in
`AppServiceProvider`, in **every** environment including production. Mass
assigning a key that isn't fillable now throws instead of quietly dropping
it — the audit found two bugs of exactly that shape, where a write "succeeded"
having lost a column. A 500 on the one buggy request beats a silent data
loss nobody notices for a month.

It is a typo guard, not a security boundary. The security rule is unchanged:
**Actions build write payloads themselves; raw request input never reaches
`Model::create()`.** `$fillable` lists should stay as narrow as the domain
allows — `Order`, for example, deliberately omits `status`, `completed_at`,
`voided_at` and `voided_by_user_id`, so no `create()` or `update()` anywhere
can close an order or forge a void audit trail without going through the
transition Actions. (Factories are unaffected: Laravel builds factory models
unguarded by design.)

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
| 422    | `invalid_transition`   | Order status change the transition map forbids (e.g. completing a voided order) |
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

#### The order payload

`GET /merchant/orders/{order}` returns this flat; `GET /merchant/orders`
returns the same objects inside `{ data, links, meta }`; and both
transition endpoints return the updated order in the flat shape, so a POS
never needs a follow-up `GET`.

```json
{
  "id": 6,
  "order_number": "ORD-000006",
  "status": "voided",
  "currency": "PHP",
  "subtotal_cents": 61500,
  "subtotal_formatted": "₱615.00",
  "discount_cents": 0,
  "discount_formatted": "₱0.00",
  "total_cents": 61500,
  "total_formatted": "₱615.00",
  "payment_method": "gcash",
  "cash_cents": null,
  "cash_formatted": null,
  "gcash_cents": null,
  "gcash_formatted": null,
  "created_by_user_id": 4,
  "voided_by_user_id": 4,
  "completed_at": null,
  "voided_at": "2026-09-09T10:16:47.000000Z",
  "created_at": "2026-09-09T10:02:11.000000Z",
  "updated_at": "2026-09-09T10:16:47.000000Z",
  "items": [
    {
      "id": 11,
      "product_id": 3,
      "product_name": "Spanish Latte (16oz)",
      "quantity": 3,
      "unit_price_cents": 15000,
      "unit_price_formatted": "₱150.00",
      "line_total_cents": 45000,
      "line_total_formatted": "₱450.00",
      "add_ons": [
        { "id": 4, "name": "Extra shot", "price_cents": 2000, "price_formatted": "₱20.00" }
      ]
    }
  ]
}
```

`cash_cents`/`gcash_cents` are populated **only** for `payment_method:
"split"`, where they sum exactly to `total_cents`. `product_id` is `null`
once the catalog entry is deleted — the line still renders in full, which
is the whole point (see § Orders).

### Money

**Every monetary value is an integer number of cents, stored alongside a
currency column.** No floats, no decimals, no `DECIMAL` columns, anywhere
— not in migrations, not in models, not in request payloads. `₱150.00` is
`price_cents = 15000` plus `currency = 'PHP'`.

Division happens exactly once, at the very edge, in
`App\Domains\Shared\Support\Money::format()` — the only place cents ever
become a display string. Money responses therefore carry **both** forms:

```json
{ "total_cents": 61500, "total_formatted": "₱615.00", "currency": "PHP" }
```

`*_cents` is for anything that computes; `*_formatted` is for anything that
displays. Frontends that format cents themselves drift apart from each
other and from printed receipts on rounding and symbol placement, so the
server renders the string once and every surface agrees.

A nullable amount formats to `null`, never `"₱0.00"` — "no cash component"
and "zero pesos of cash" are different facts (see `cash_cents` on a
non-split order).

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

`DevSeeder` also gives **both active merchants** a five-item menu and six
orders each, in mixed states (pending / completed / voided) across all
three payment methods, so the POS and kitchen frontends have something
realistic to demo against and cross-tenant scoping is visible by eye. Both
merchants' sequences start at `ORD-000001`. Orders are skipped for a
merchant that already has some, which is what keeps re-seeding idempotent
— unlike the rows above they can't be `updateOrCreate`d, since each one
draws a fresh number from the merchant's counter.

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
adds its cases there rather than starting a new file. The first block still
runs against a test-only fixture (`tests/Fixtures/`) that exercises the
trait in isolation; everything after it uses the real Orders domain and
asserts isolation through the actual HTTP endpoints, which is the surface
an attacker really has.

Tenant-owned tables so far: `merchants` (the tenant itself), `products`,
`orders`. `order_items` and `order_item_add_ons` deliberately have **no**
`merchant_id` — they inherit tenancy structurally, since they are only ever
reachable through a scoped order. A second owner column on those tables
would be a second source of truth that could disagree with the first.

## Orders

The merchant sales record. Phase P1 covers the data model, the status
machine, numbering, and the read + transition endpoints — orders are
**created** by P2's POS checkout, so today they only come into existence
through factories and the dev seeder.

| Method | Route                                    | Notes                                   |
| ------ | ---------------------------------------- | --------------------------------------- |
| `GET`  | `/api/v1/merchant/orders`                | Paginated, newest first; `?status=`, `?date=YYYY-MM-DD`, `?per_page=` (max 100) |
| `GET`  | `/api/v1/merchant/orders/{order}`        | Single, flat                            |
| `POST` | `/api/v1/merchant/orders/{order}/complete` | Returns the updated order             |
| `POST` | `/api/v1/merchant/orders/{order}/void`   | Returns the updated order               |

There is **no** `POST /orders` yet and **no** `DELETE`, ever — see below.

### The status machine

```
pending ──▶ completed   (terminal)
   └──────▶ voided      (terminal)
```

Three states, two of them terminal. `App\Domains\Orders\Enums\OrderStatus`
is a backed enum whose `allowedTransitions()` is **the single authority**
on which moves are legal: both `CompleteOrderAction` and `VoidOrderAction`
ask it, and any future transition asks it too. Nothing else in the codebase
decides. An illegal move — completing a voided order, voiding a completed
one, or repeating either — is a `422 invalid_transition`.

The shape is built for **insertion**: adding `preparing` and `ready`
between `pending` and `completed` means adding two enum cases, widening the
`orders_status_check` constraint in a migration, and editing the `match`
arms. No caller changes, because no caller hard-codes a state pair.

Both transition actions re-read the order under `lockForUpdate()` inside a
transaction. Two taps on a POS "complete" button, or a completing terminal
racing a voiding manager, would otherwise both pass the guard and the
second write would silently overwrite the first.

### Payment

Counter-service model: **orders are paid at creation**. `payment_method` is
`cash | gcash | split`, and for `split` the `cash_cents` + `gcash_cents`
columns sum to `total_cents` (validated by P2's checkout; the columns exist
now). They stay `null` for non-split orders.

There is deliberately **no `payment_status` column**. The audited system
carried both a status and a payment_status, they drifted into combinations
nobody could interpret, and one status is the design here.

### Order numbering

Per-merchant sequential, zero-padded, no daily reset: `ORD-000001`,
`ORD-000002`, … Merchant One and Merchant Two **both** have an
`ORD-000001`; the unique index is on `(merchant_id, order_number)`, not on
the number alone.

`merchant_order_counters` holds one row per merchant, and
`GenerateOrderNumberAction` is its only writer. It takes a `SELECT … FOR
UPDATE` row lock and **requires an open transaction** (it throws a
`LogicException` otherwise), because a lock released before the order it
numbers is committed would let the next caller take the same number. The
factory issues numbers through that same action, so the numbering tests
exercise the mechanism production uses rather than a parallel one.

Rejected designs, all of which the audited system used: `MAX(id) + 1` and
`latest()->first()` (two concurrent checkouts read the same maximum), a
global sequence (gaps leak other merchants' volume), and random strings
(unreadable at a counter, unordered).

### Line items are snapshots

`order_items.product_name` and `unit_price_cents` are **copied** from the
product at creation and never re-read. Rendering code that reaches through
`product_id` for a name or price is a bug.

That is a correctness requirement, not an optimisation: a receipt printed
today must still show what was actually charged after the merchant renames
the drink or raises the price tomorrow, and it must survive the product
being deleted outright. `product_id` is a nullable FK with
`ON DELETE SET NULL` — a convenience link back to the live catalog for
reporting, nothing more. Add-ons (`order_item_add_ons`) snapshot the same
way.

### Orders are never deleted

No `SoftDeletes`, no `DELETE` route, no `destroy` action — and none should
ever be added. Financial records are append-only, and **voiding is the
reversal mechanism**. `voided` is terminal, and `voided_by_user_id` records
who did it: a voided sale is money leaving the till, and the audit trail is
the only thing separating a mis-keyed order from theft.

`created_by_user_id` is likewise non-nullable on every order. The audited
system had no such column, so once an order closed there was no answer to
"who rang this up?" — the first question asked when a till is short.

### The products table is a shared contract

`products` is a deliberately minimal table (`merchant_id`, `name`,
`price_cents`, `currency`, `is_available`) created here only because orders
need something to FK against and P2's checkout needs a server-side price to
read. **The catalog module owns it** and extends it with its own
migrations; Orders never widens it, and `App\Domains\Catalog\Models\Product`
stays a bare model with no controller, policy, or resource.

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
