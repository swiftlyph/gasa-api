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
| 422    | `invalid_transition`   | Order status change the transition map forbids (e.g. completing a voided order), OR a merchant status change `PATCH /admin/merchants/{merchant}/status` forbids (e.g. `pending` → `pending`) — one shared code across both, see § Platform admin |
| 422    | `product_unavailable`  | Checkout referenced a product that is missing, not the caller's, or flagged unavailable — `errors.product_ids` lists them |
| 422    | `discount_exceeds_subtotal` | Checkout discount is larger than the server-computed subtotal   |
| 422    | `split_mismatch`       | Split payment whose `cash_cents` + `gcash_cents` don't equal the server-computed total |
| 409    | `idempotency_key_reuse` | Checkout reused an `Idempotency-Key` with a different request body |
| 409    | `session_already_open` | Opening a cash session on a register that already has one open        |
| 422    | `session_closed`       | A movement, remittance, or second close attempted on a closed cash session |
| 422    | `remittance_exceeds_cash` | A remittance amount exceeds the session's currently expected cash on hand |
| 422    | `remittance_already_confirmed` | Confirming a remittance that is already confirmed                |
| 403    | `confirmation_requires_second_user` | Confirming a remittance you created yourself                |
| 422    | `range_too_large`      | A report's `?from=`/`?to=` spans more than 366 days (see § Reporting) |
| 422    | `member_already_exists` | `POST /merchant/team` — the email is already attached to this merchant |
| 422    | `email_unavailable`    | `POST /merchant/team` — the email belongs to a user not already on this merchant (never reveals which merchant); also `POST /admin/merchants` — the owner email already belongs to any user |
| 422    | `cannot_remove_owner`  | `DELETE /merchant/team/{user}` — the target is the merchant's owner |
| 422    | `cannot_demote_owner`  | `PATCH /merchant/team/{user}` — the target is the merchant's owner, and the new `role_in_merchant` isn't `owner` |
| 403    | `permission_denied`    | The caller's `role_in_merchant` preset doesn't carry the permission a merchant Policy requires — `errors.permission` names it (see § Permissions) |
| 422    | `invalid_invite`       | `/auth/accept-invite` — token missing, already used, or expired (never distinguished) |
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

- **Single resource** (`/auth/me`, `login`'s `user`, any `show` endpoint,
  and the 201 from checkout): flat object — `{ id, name, email, ... }`, no
  `data` key.
- **Paginated list** (`/merchant/orders`, `/merchant/cash-sessions`, any
  future `index` endpoint): always `{ data: [...], links: {...}, meta: {...} }`.
- **Unpaginated list** (`/merchant/menu`, `/merchant/kitchen-queue`,
  `/merchant/registers`, `/merchant/reports/sales-by-day`,
  `/merchant/reports/top-items`): `{ data: [...] }` — a `data` key so every
  list endpoint looks alike to a client, but no `links`/`meta`, because
  there are no pages to link to.
- **Scalar summary** (`/merchant/kitchen-queue/summary`,
  `/merchant/reports/sales-summary`): a flat object of the values
  themselves — `{ pending_count, oldest_waiting_seconds }`,
  `{ orders_count, ... }`. No `data` wrapper, because there is no
  collection to wrap.
- **Nullable single resource** (`/merchant/cash-sessions/current`):
  `{ "data": null }` when nothing matches — a cash session's "current" can
  legitimately not exist (no till opened yet), which is a different fact
  from "not found," so this is a 200 with a null payload, never a 404.

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
  "merchant": { "id": 1, "name": "Merchant One", "status": "active", "role_in_merchant": "owner" },
  "permissions": ["orders.view", "orders.create", "..."]
}
```

`merchant` is `null` for any user with no merchant membership (platform
admins, company admins, employees). It is **present regardless of status** —
a `pending` or `suspended` merchant still gets the object, with the status
that applies. That's deliberate: a suspended merchant is blocked from every
merchant route with 403 `merchant_inactive`, but `/auth/me` keeps working so
the frontend can read `merchant.status` and render a suspended screen rather
than bouncing the user back to login.

`permissions` (P8) is **empty, not merely absent, for any account with no
ACTIVE merchant** — a `pending`/`suspended` merchant's `permissions` is
`[]` even though `merchant` itself is still shown, matching
`User::merchant()`'s own active-only rule (see § Permissions). It is
**additive**: new values may appear here in a later phase (custom roles)
without warning, and the frontend should never treat an unrecognised
value as an error.

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

#### The cash session payload

Open, close, `current`, `show`, and the history list all return this
shape (the list wraps it in `{ data, links, meta }`):

```json
{
  "id": 3,
  "register_id": 1,
  "status": "open",
  "opening_float_cents": 100000,
  "opening_float_formatted": "₱1,000.00",
  "opened_by_user_id": 4,
  "closed_by_user_id": null,
  "opened_at": "2026-09-10T08:00:00.000000Z",
  "closed_at": null,
  "notes": null,
  "reconciliation": {
    "opening_float_cents": 100000,
    "cash_sales_cents": 14000,
    "voided_cash_cents": 0,
    "cash_in_cents": 0,
    "cash_out_cents": 0,
    "confirmed_remittances_cents": 0,
    "expected_cash_cents": 114000,
    "counted_cash_cents": null,
    "variance_cents": null
  },
  "movements": [],
  "remittances": []
}
```

`reconciliation` is **always present**, but what it reports depends on
`status` — see § Cash sessions for the full formula and why the figures
differ between an open and a closed session. `movements`/`remittances`
are only present when the endpoint loads them (`current` and `show` do;
the paginated history list does not, to keep a page of sessions from
turning into N+1 nested collections).

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

`DevSeeder` also gives **both active merchants** an eight-item menu (via
`ProductSeeder`, one item deliberately unavailable) and six orders each, in
mixed states (pending / completed / voided) across all three payment
methods, so the POS and kitchen frontends have something realistic to demo
against and cross-tenant scoping is visible by eye. The two menus are
different from each other on purpose. Both merchants' sequences start at
`ORD-000001`. Orders are skipped for a
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
| `GET`  | `/api/v1/merchant/menu`                  | POS product list; `?include_unavailable=1` |
| `GET`  | `/api/v1/merchant/kitchen-queue`         | Pending orders, oldest first, today by default; `?all=1` |
| `GET`  | `/api/v1/merchant/kitchen-queue/summary` | `{ pending_count, oldest_waiting_seconds }` |
| `GET`  | `/api/v1/merchant/orders`                | Paginated, newest first; `?status=`, `?date=YYYY-MM-DD`, `?per_page=` (max 100) |
| `POST` | `/api/v1/merchant/orders`                | Checkout — creates a paid order, returns 201; honours an `Idempotency-Key` header |
| `GET`  | `/api/v1/merchant/orders/{order}`        | Single, flat                            |
| `POST` | `/api/v1/merchant/orders/{order}/complete` | Returns the updated order             |
| `POST` | `/api/v1/merchant/orders/{order}/void`   | Returns the updated order               |

There is **no** `DELETE`, ever — see "Orders are never deleted" below.

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

### Day boundaries

Every "calendar day" question in the merchant API — the orders list'
`?date=`, the kitchen queue's default "today", cash session history's
`?from=`/`?to=`, and every reporting endpoint's date range — resolves
through **one class**: `App\Domains\Orders\Support\MerchantDay`. Nowhere
else decides where midnight is.

"Merchant-local" is `config('merchant.day_timezone')`
(`MERCHANT_DAY_TIMEZONE`, default `Asia/Manila`) — a **dedicated** setting,
deliberately not `config('app.timezone')` (which stays `UTC`, correctly,
for logging and storage). Conflating the two was a real production bug: a
merchant in UTC+8 asking for "today" got UTC's today instead of theirs, so
an order placed at 2:28 AM local — unambiguously "this morning" to
everyone in the shop — was filed under the previous UTC calendar date. A
merchant's "today" order count read 0 while the kitchen queue's own
"today" (which happened to share the same underlying bug, just
consistently) also silently disagreed with what a human would call today.

There is no per-merchant timezone column yet, so every merchant currently
shares this one value; when that column exists,
`MerchantDay::timezone()` is the one call site that changes.

**A second rule, easy to miss and worth stating on purpose:** every
`timestamp` column a day boundary is compared against (`orders.created_at`,
`cash_sessions.opened_at`) is `timestamp WITHOUT TIME ZONE` — naive UTC
wall-clock digits, since `app.timezone` is `UTC` and that is what plain
`now()` writes. Both Eloquent's date casting and the query grammar
stringify a `Carbon` instance by its **own** wall-clock digits with no
offset, so a `Asia/Manila`-zoned boundary bound straight into a query (or
written straight into a model attribute) is compared/stored as if those
digits were already UTC — silently several hours wrong, with no error.
`MerchantDay::forQuery()` converts a merchant-local instant to its UTC
equivalent, and is **required** at every point a value from this class
touches a query binding or a model attribute — `MerchantDay::constrain()`
already does this internally; any call site working with boundaries
directly (as `CashSessionController`'s `?from=`/`?to=` does) must call it
explicitly.

Regression-tested at the moment the bug actually manifests: time frozen at
00:30 merchant-local, which is the *previous* UTC calendar date. See
`tests/Feature/Orders/OrderListTest.php`,
`tests/Feature/Orders/KitchenQueueTest.php`, and the reporting tests below
— all three must agree an order created then is "today."

### Checkout

`POST /api/v1/merchant/orders` is the one endpoint that creates an order.
The client sends **what was ordered, never what it costs**:

```json
{
  "payment_method": "split",
  "cash_cents": 10000,
  "gcash_cents": 17000,
  "discount_cents": 5000,
  "items": [
    {
      "product_id": 3,
      "quantity": 2,
      "add_ons": [{ "name": "Extra shot", "price_cents": 2000 }]
    }
  ]
}
```

It responds `201` with the same flat order payload `GET` returns (see
§ Response shapes), so a POS can print the receipt without a second
request.

**Prices are looked up server-side and snapshotted.** There is no
per-item price field in the request, `CheckoutRequest` defines no rule for
one (so `validated()` strips it), and `CheckoutAction` never reads one —
every `unit_price_cents` comes from the `products` table. The audited
system took unit prices straight from the payload, which let a tampered
request set its own; this is the fix, deliberately enforced in two
independent places.

The arithmetic, in order:

```
line_total_cents = (product.price_cents + Σ add_on.price_cents) × quantity
subtotal_cents   = Σ line_total_cents
total_cents      = subtotal_cents − discount_cents
```

Add-ons are priced **per unit** — two lattes each with an extra shot is
two extra shots.

Everything is validated **before** an order number is drawn, so a rejected
basket never burns a sequence number; anything that fails afterwards rolls
back inside the same transaction, counter increment included.

Rejections (all `422`, see the error table):

| Cause | Code |
| ----- | ---- |
| Product missing, not yours, or `is_available = false` | `product_unavailable` |
| `discount_cents` > computed subtotal | `discount_exceeds_subtotal` |
| Split halves don't equal the computed total | `split_mismatch` |
| Malformed shape (bad quantity, unknown method, split half missing or ≤ 0, split amount on a non-split order) | `validation_failed` |

A basket is **all-or-nothing**: one unavailable line rejects the whole
order rather than quietly selling the rest, because a customer who paid
for three drinks should not get a receipt for two.

> **TODO — add-on prices are client-supplied.** There is no add-on catalog
> table yet (it belongs to the catalog module), so `add_ons` are accepted
> as `{ name, price_cents }` straight from the request. This is the one
> place a client still names a price. The risk is bounded, not removed:
> `CheckoutRequest` caps add-ons at 5 per line, requires a non-empty name
> ≤ 100 chars, and requires `price_cents` between 0 and 1,000,000 (₱10,000).
> When the catalog module adds a real add-on table, resolve them in
> `CheckoutAction` exactly as products are resolved and delete this note.

### Idempotency

The POS runs on tablets over shop wifi. A cashier double-taps "charge", or
the response to a successful checkout never arrives and the tablet retries
— and the customer is charged twice for one coffee. Checkout therefore
accepts a client-generated key that lets the server recognise a repeat
attempt.

```http
POST /api/v1/merchant/orders
Idempotency-Key: a3f1c8e2-0d4b-4a71-9f2e-8c1d6b5a4e30
```

A **header**, not a body field: it identifies the *attempt*, not what is
being sold, and it must stay out of the request fingerprint. An
`idempotency_key` in the body is ignored. The key is opaque (any string,
8–255 characters — a UUID is the expected shape) and is scoped per
merchant, so two shops picking the same value never see each other's
orders.

Four outcomes:

| Situation | Response |
| --------- | -------- |
| **No header** | Unchanged — `201`, a new order every time. Idempotency is opt-in per request. |
| **Key unseen** | `201` with the new order. The key is reserved in the same transaction. |
| **Key seen, same body** | `200` with the **original** order, plus `Idempotent-Replayed: true`. Nothing is created and the order counter does not move. |
| **Key seen, different body** | `409` `idempotency_key_reuse`. |

A replay answers `200`, never `201`, because a POS retrying after a lost
response has to distinguish "your order went through the first time" from
"you have just made a second one". The `Idempotent-Replayed` header says
the same thing for clients that prefer a header; its absence means the
order was created by this request.

The `409` is deliberately loud. Serving the original order would answer a
request for two lattes with a receipt for one americano and the cashier
would never know; creating a new order would defeat the mechanism
entirely. A client hitting it has a real bug — almost always one key
reused across checkouts.

**How it stays correct**

- **Reserve first, then sell.** The key row is inserted *before* pricing
  runs, so a concurrent duplicate collides on the unique index
  immediately — before any product lookup and before a number is drawn
  from the merchant's counter.
- **One transaction, so failure unreserves.** If the basket turns out to
  be invalid (`product_unavailable`, say), the rollback takes the
  reservation with it. **A failed checkout does not burn the key** — the
  cashier fixes the order and retries with the same one.
- **`UNIQUE (merchant_id, key)` is the concurrency arbiter.** Two
  simultaneous requests both try to insert; Postgres lets exactly one
  through, and the loser catches the violation and returns the winner's
  order rather than erroring or checking out again. There is no
  check-then-act window, because the check *is* the insert.

**The fingerprint** is a SHA-256 of the *normalised* payload — payment
method, split amounts, discount, and the lines, with add-ons sorted within
a line and lines sorted within the basket. Hashing the raw body would be
wrongly strict: a tablet that rebuilds its JSON on retry can legitimately
emit the same basket with different key order, different whitespace, or
`discount_cents` omitted instead of `0`, and every one of those would come
back as a `409` for a mistake nobody made. Sorting the lines means two
baskets differing *only* in line order are one request — intended, since
they sell the same drinks for the same money.

**Retention.** Keys are useful for the life of a retry and dead after
that, so `checkout_idempotency_keys` is swept on a schedule:

```bash
php artisan orders:prune-idempotency-keys            # default: 24 hours
php artisan orders:prune-idempotency-keys --hours=6
```

Scheduled hourly in `routes/console.php`; it needs a running scheduler
(`* * * * * php artisan schedule:run`). The default window is **24 hours**
— far beyond any real retry, short enough that the table stays roughly one
day of sales. Pruning never touches the orders themselves; a retry
arriving a day late is simply treated as a new checkout, which is the
right answer by then anyway.

> **For the POS frontend — the one rule that matters.** Generate **one key
> per checkout attempt**, at the moment the cashier commits to the sale,
> and **resend that same key on every retry** of that attempt. Generating
> a fresh key on retry defeats the entire mechanism and produces exactly
> the double charge it exists to prevent. Generate a new key only when the
> cashier starts a genuinely new sale — a customer really can buy the same
> coffee twice, and two different keys with identical bodies correctly
> produce two orders.

### Payment

Counter-service model — confirmed with the shop: **the cashier collects
payment before placing the order**, so orders are paid at creation and
there is no async capture step. `payment_method` is `cash | gcash | split`.

For `split`, `cash_cents` + `gcash_cents` must equal `total_cents` and both
must be positive; for anything else both stay `null` (sending one is a
422, not a silent drop). This is enforced in three places on purpose:
`CheckoutRequest` for shape, `CheckoutAction` for the sum against the
server-computed total, and the `orders_split_payment_check` constraint in
the database, which catches anything that ever writes around the Action.

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

### The kitchen queue

Two endpoints for the kitchen screen. Both are **views over `orders`** —
there is no `kitchen_queue` table and no kitchen-specific status column.
"In the queue" means `status = pending` and nothing else, so an order
cannot be closed on the till and still open in the kitchen, which is the
failure mode of every design that duplicates state.

```
GET /api/v1/merchant/kitchen-queue
GET /api/v1/merchant/kitchen-queue/summary
```

```json
{
  "data": [
    {
      "id": 41,
      "order_number": "ORD-000012",
      "created_at": "2026-09-09T10:02:11.000000Z",
      "waiting_seconds": 214,
      "items": [
        { "id": 88, "product_name": "Cafe Latte (16oz)", "quantity": 2, "add_ons": ["Extra shot"] }
      ]
    }
  ]
}
```

**Completion goes through the existing transition endpoint** —
`POST /merchant/orders/{order}/complete`. This phase adds no second way to
finish an order, so `OrderStatus`' transition map stays the only authority
on what a legal status change is. Voiding removes a ticket the same way.

The flow is deliberately **binary**: pending, then done. `preparing` and
`ready` were left out because the audited shop never used them;
`OrderStatus` is built so they can be *inserted* later if a real kitchen
asks for them.

| Property | Behaviour |
| -------- | --------- |
| **Ordering** | **FIFO — oldest first**, the opposite of the orders list. Tie-broken on `id`, so two tickets rung up in the same second don't shuffle between polls. |
| **Scope** | **Today** in the merchant day timezone by default (see § Day boundaries). `?all=1` drops the date filter — and only the date filter; it never widens the tenant. |
| **Pagination** | None. A barista cannot page through drinks. |
| **Cap** | `200` tickets (`KitchenQueueController::MAX_QUEUE_SIZE`). |
| **Money** | **Absent entirely.** No prices, no totals, no payment method. |

`?all=1` exists because the audited system silently lost work: an order
rung up at 23:58 vanished from the queue two minutes later, and a queue
left open overnight came back empty in the morning with drinks still
unmade. Today-by-default keeps the screen small; `all=1` is how the
kitchen finds anything that fell off the edge of a day.

`waiting_seconds` is **computed server-side** from `created_at`. A kitchen
tablet's clock can be minutes off, and "this order has been waiting 14
minutes" derived from a skewed clock is confidently wrong — and it drives
whether staff apologise to a customer. Every ticket in one response is
measured from the same instant, so orders created in the same second never
report different ages.

`KitchenOrderResource` is a **separate class** from `OrderResource`, not
`OrderResource` with flags. Different audience, different question: the
merchant's order list is a financial record, a kitchen ticket is a work
instruction. The kitchen screen is also the most-displayed and
least-access-controlled surface in the shop — often visible over the
counter — so it carries no money at all.

**The cap.** 200 is far beyond any real counter-service backlog, so in
practice it never truncates; it exists so the worst case is a large
response rather than an unbounded one. Because the queue is oldest-first,
truncation drops the *newest* tickets and keeps the front of the line.
The summary's `pending_count` is **uncapped**, so a screen comparing it to
`data.length` can always tell it is seeing a truncated view.

`oldest_waiting_seconds` is `null` on an empty queue, never `0` — "nothing
has been waiting" and "something has been waiting no time at all" are
different facts, and a badge that turns red past a threshold must not
confuse them.

#### Polling

There are no websockets yet, so the kitchen screen polls. Recommended
intervals:

| Endpoint | Interval |
| -------- | -------- |
| `/merchant/kitchen-queue` | **15s** |
| `/merchant/kitchen-queue/summary` | **30s** |

Both are built for it. They are pure reads with no cache mutation; items
and add-ons are eager loaded, so the query count is **flat in the size of
the queue** rather than `1 + 2N` (asserted in
`tests/Feature/Orders/KitchenQueueTest.php`); the summary answers with a
*single* aggregate query that loads no models and never touches
`order_items`; and the ordering is total, so two polls a second apart
return the same tickets in the same order.

> One caveat worth knowing before adding more pollers: every
> **authenticated** request writes `personal_access_tokens.last_used_at`
> (Sanctum, platform-wide — not specific to these endpoints). At the
> intervals above that is a few thousand small updates per screen per day.
> Harmless at coffee-shop scale, but it is the reason these endpoints are
> not literally write-free, and it is worth remembering if polling ever
> gets more aggressive.

### The POS menu, and its cache

`GET /api/v1/merchant/menu` is what the till draws its tiles from: `id`,
`name`, `price_cents`, `price_formatted`, `currency`, `is_available`.
Available items only by default; `?include_unavailable=1` returns
everything, for a manager screen that needs to see the greyed-out ones.

It is a **read-only projection** of the shared `products` table, owned by
the POS lane and living in `App\Domains\Orders` for that reason. Product
management — categories, images, availability rules — belongs to the
catalog module and will arrive in its own namespace. Nothing in the POS
lane writes to `products`.

**The cache key is namespaced by merchant, and nothing may bypass that.**

```
merchant:{merchant_id}:menu:available
merchant:{merchant_id}:menu:all
```

`App\Domains\Shared\Support\MenuCache` is the only place those keys are
constructed, and neither of its methods can produce one without a merchant
id. This is not stylistic: the audited system cached its product list under
a single global key (`pos_products`), so whichever merchant warmed the
cache served their menu — prices included — to every other shop on the
platform. That bug is invisible on a dev box with one merchant in it, which
is why `tests/Feature/Orders/MenuTest.php` and the leakage suite both use
two, and assert the key shape as well as the behaviour.

> **For the catalog module:** any write that creates, updates, deletes, or
> changes the price or availability of a product **must** call
> `MenuCache::forget($product->merchant_id)`. A model observer on `Product`
> is the obvious home for it. `forget()` clears every variant of that
> merchant's menu and touches no one else's. The 60-second TTL is a safety
> net for a missed invalidation, not a substitute for one — `ProductSeeder`
> shows the intended shape.

### The products table is a shared contract

`products` is a deliberately minimal table (`merchant_id`, `name`,
`price_cents`, `currency`, `is_available`) created here only because orders
need something to FK against and checkout needs a server-side price to
read. **The catalog module owns it** and extends it with its own
migrations; the POS lane never widens it, and
`App\Domains\Catalog\Models\Product` stays a bare model with no controller,
policy, or resource.

`ProductSeeder` fills it with demo menus — data only, not a module. The two
merchants get **deliberately different** catalogs: identical ones would make
a cross-tenant leak invisible, since a menu endpoint serving the wrong
merchant's products would still look perfectly correct on screen.

## Cash sessions

Counter-service shops collect cash, and a till has to be reconciled: does
what's physically in the drawer match what the system says was rung up?
The audited system answered that with one cash session per calendar day
for the entire deployment, cash movements appended to a growing free-text
blob, and remittances confirmable by whoever created them. All three are
rejected designs here.

| Method | Route                                              | Notes |
| ------ | --------------------------------------------------- | ----- |
| `GET`  | `/api/v1/merchant/registers`                         | Listing only; `is_default` marks the merchant's default |
| `POST` | `/api/v1/merchant/cash-sessions`                     | Open a session: `{ register_id?, opening_float_cents, notes? }` |
| `GET`  | `/api/v1/merchant/cash-sessions`                     | Paginated history; `?status=`, `?register_id=`, `?from=`, `?to=` |
| `GET`  | `/api/v1/merchant/cash-sessions/current`             | The open session for a register (or the default); `{ "data": null }` when nothing is open |
| `GET`  | `/api/v1/merchant/cash-sessions/{cashSession}`       | One session, with its movements, remittances, and reconciliation |
| `POST` | `/api/v1/merchant/cash-sessions/{cashSession}/movements` | Record cash in/out: `{ type, amount_cents, reason }` |
| `POST` | `/api/v1/merchant/cash-sessions/{cashSession}/close` | `{ counted_cash_cents, notes? }` — snapshots expected, computes variance |
| `POST` | `/api/v1/merchant/cash-sessions/{cashSession}/remittances` | Create a pending remittance: `{ amount_cents, note? }` |
| `POST` | `/api/v1/merchant/remittances/{remittance}/confirm`  | Confirm — must be a different user than the creator |

There is **no** `DELETE` anywhere in this group either: a session, a
movement, and a remittance are all financial records, the same rule that
governs orders.

### Registers

A `registers` table exists from day one, with **every merchant
guaranteed a default register the moment it's created** (see § Merchant
profile & team members) — a single-till shop never notices it exists.
"The default" is the merchant's oldest active register
(`App\Domains\CashSessions\Support\DefaultRegister`), not a stored flag:
that needs no uniqueness rule and no transfer-of-default logic when a
register is retired. There is no register CRUD beyond listing this phase —
creating and retiring registers is a merchant-settings concern for later.

### The cash session lifecycle

```
open ──▶ closed   (terminal)
```

One transition, and it never reverses — closing is not "paused," and
there is no reopen. `App\Domains\CashSessions\Enums\CashSessionStatus`
mirrors the pattern `OrderStatus` sets: a backed enum whose shape is the
typed view of the database's own CHECK constraint.

**Only one open session per register at a time**, enforced by a
**PARTIAL UNIQUE INDEX** — `UNIQUE (register_id) WHERE status = 'open'` —
not just a validation check. A composite unique index on
`(register_id, status)` can't express this: it would also forbid a
register from ever having two *closed* sessions in its history, which is
the ordinary case on day two. Opening a second session on an already-open
register is `409 session_already_open`; two different registers on one
merchant can each hold their own open session with no conflict.

### The cash ledger

`cash_movements` is a normalised row per movement — `type` (`cash_in` |
`cash_out`), `amount_cents` (always positive; the type alone carries
direction), and `reason` — never appended text. Rejected on a closed
session with `422 session_closed`.

### Reconciliation

`App\Domains\CashSessions\Actions\ReconcileCashSessionAction` is **the
single authority** on expected cash, and the figure is **derived, every
time it's asked for** — never a running total that could drift from its
own inputs. The formula uses the **gross** shape — "taken in" and "given
back" as two separate terms, never one that has already netted the other
out — summed in this order:

```
expected_cash =
    opening_float
  + cash_sales    (GROSS: every cash order in the session, voided ones
                    included — a sale is money in the drawer the moment
                    it's rung up, and voiding it later doesn't erase that
                    it once happened)
  − voided_cash   (the cash portion of VOIDED orders alone, given back)
  + cash_in movements − cash_out movements
  − confirmed remittances
```

This shop is paid at creation and a void is a refund, so physically a
voided cash order's cents go into the drawer at checkout and back out at
void — net zero. Computing `cash_sales` **net of** voided orders and then
*also* subtracting `voided_cash` double-subtracts the void and understates
expected cash by twice the voided amount; keeping the two as independent
gross terms is what makes them match the physical drawer.

"Cash sales" means the **cash portion only**: a pure-cash order's full
`total_cents`, plus a split order's `cash_cents` half. **GCash never
counts**, in either direction — gcash settles electronically and never
touches the physical till, so a voided gcash order changes nothing here,
while a voided cash order nets to zero (counted once in `cash_sales`,
subtracted once in `voided_cash`). Only **confirmed** remittances subtract;
a pending one is a claim, not proof, and counting it early would make the
drawer look short of cash it still physically holds.

On an **open** session this figure is computed live on every read (`GET
.../current`, `GET .../{cashSession}`). On a **closed** session,
`expected_cash_cents`, `counted_cash_cents` and `variance_cents` are the
values **frozen at close** by `CloseCashSessionAction` — a later
correction on some other still-open session can never reach back and
change a closed session's history. `variance_cents = counted − expected`:
positive is an over, negative is a short, and it is never stored as an
absolute value — a shop needs to know which direction it went.

### Checkout attribution

`CheckoutAction` stamps `orders.cash_session_id` from the **open session
on the register in context** (an optional `register_id` on checkout,
defaulting to the merchant's default register). If no session is open,
**checkout still succeeds with a null session** — a cashier who forgot to
open the till must not be blocked from selling. Existing orders (pre-P4)
stay null and are **not backfilled**: there is no session a backfill could
correctly assign them to, and a guess would fabricate an audit trail for
sales that never went through one.

### Remittances and segregation of duties

A remittance is cash physically taken out of the till. It is created
`pending` and can only be moved to `confirmed` by a **different user**
than the one who created it — `403 confirmation_requires_second_user`
otherwise — enforced in `ConfirmRemittanceAction`, not left as a UI
convention a client could skip. The amount may not exceed the session's
**live** expected cash at the moment of creation (`422
remittance_exceeds_cash`); confirming twice is `422
remittance_already_confirmed`.

> **TODO — attachment uploads are out of scope this phase.** The
> `attachment_path` column exists on `cash_remittances` so a later upload
> feature only has to add behaviour, not schema, but no endpoint writes it
> yet, and it stays `null` on every row.

## Reporting

Date-range reporting over `orders`. **Scope: date-range only.**
Session-scoped reporting — a true Z-report per cash session — lives in
§ Shift report & receipt below (P9), and answers a different question
("what happened in this till shift") from everything in this section
("what happened in this date range").

| Method | Route                                    | Notes                        |
| ------ | ----------------------------------------- | ----------------------------- |
| `GET`  | `/api/v1/merchant/reports/sales-summary`  | One row of aggregate figures for the range |
| `GET`  | `/api/v1/merchant/reports/sales-by-day`   | One row per local day, zero-filled |
| `GET`  | `/api/v1/merchant/reports/top-items`      | Best sellers, from order line snapshots |

Every endpoint is **read-only** (Sanctum's `last_used_at` aside — see §
Polling) and takes the same `?from=`/`?to=` pair, handled once by
`ReportDateRangeRequest`:

- **Inclusive**, both ends: `?from=2026-09-01&to=2026-09-03` covers three
  whole calendar days.
- Resolved through **MerchantDay** — see § Day boundaries. A day belongs
  to the report exactly as it belongs to the orders list and the kitchen
  queue; this is the reason Part A's day-boundary fix mattered beyond the
  two endpoints that shipped it originally.
- **Both default to today** when omitted, so every report answers
  something with no query string at all rather than 422ing or scanning
  the whole table.
- Capped at **366 days** (`ReportDateRangeRequest::MAX_RANGE_DAYS`) — a
  leap year's worth of daily figures, generous for "how did last year
  compare" without leaving the aggregate queries unbounded. Beyond it:
  `422 range_too_large`, its own code because both dates are individually
  valid — this is a request for more aggregation than this phase serves
  without caching, not a malformed request. `from > to` is a plain `422
  validation_failed`.

**Accounting rules, the same across every report below:**

- **Voided orders never contribute to revenue.** Every `gross_cents` /
  `discount_cents` / `net_cents` / payment-method figure excludes them.
  Their count is reported separately (`voided_count` on the summary) so
  "how many sales" and "how many voids" stay two different numbers.
- **Pending orders DO count as revenue.** This is a counter-service shop:
  the customer paid at creation (see § Payment), and "not yet completed"
  describes the drink, not the sale.
- **A split order's `cash_cents`/`gcash_cents` land in their own
  buckets**, summing to the order's total exactly once — never the full
  total double-counted into both. This is the same accounting
  `ReconcileCashSessionAction` already uses for cash-session reconciliation
  (see § Reconciliation) — reporting and reconciliation must never disagree
  about what a split order contributed.

**Efficiency.** Every report is a **small, fixed number of aggregate SQL
queries** — conditional aggregates (`FILTER (WHERE ...)`, native to
Postgres 16) rather than one query per figure, and never orders loaded
into PHP to be summed by hand. Query counts are asserted flat in the size
of the dataset in `tests/Feature/Reports/ReportingTest.php`. No new index
was needed: `(merchant_id, created_at)` and `(merchant_id, status,
created_at)` (added for the kitchen queue — see its migration) already
cover every range scan these queries run; `order_items.order_id` (existing)
covers the join `top-items` performs back to `orders`.

### GET /merchant/reports/sales-summary

```json
{
  "orders_count": 4,
  "completed_count": 2,
  "voided_count": 1,
  "gross_cents": 23000,
  "gross_formatted": "₱230.00",
  "discount_cents": 500,
  "discount_formatted": "₱5.00",
  "net_cents": 22500,
  "net_formatted": "₱225.00",
  "by_payment_method": {
    "cash": { "count": 1, "amount_cents": 13000, "amount_formatted": "₱130.00" },
    "gcash": { "count": 1, "amount_cents": 9500, "amount_formatted": "₱95.00" },
    "split": { "count": 1, "amount_cents": 8000, "amount_formatted": "₱80.00" }
  },
  "average_order_cents": 7500,
  "average_order_formatted": "₱75.00"
}
```

A flat object (no `data` wrapper) — a scalar summary, matching the shape
`/merchant/kitchen-queue/summary` already established (see § Response
shapes). `by_payment_method.{cash,gcash}.count` is how many orders were
paid **purely** that way — a split order counts once, under `split`, not
under both — but its money still lands in all three `amount_cents`
figures, since the drawer and the gcash settlement both genuinely
received their share. `average_order_cents` divides `net_cents` by
`orders_count - voided_count`: the average size of a sale that actually
happened, not diluted by orders that were reversed.

### GET /merchant/reports/sales-by-day

```json
{
  "data": [
    { "date": "2026-09-01", "orders_count": 12, "net_cents": 184000, "net_formatted": "₱1,840.00" },
    { "date": "2026-09-02", "orders_count": 0, "net_cents": 0, "net_formatted": "₱0.00" },
    { "date": "2026-09-03", "orders_count": 9, "net_cents": 121500, "net_formatted": "₱1,215.00" }
  ]
}
```

One row **per local calendar day in the range, ordered ascending**, days
with no sales present as a **zero row** rather than absent — a chart built
on rows that silently skip empty days lies about the gap. Grouping happens
in the same query as the aggregation (`created_at` shifted into
merchant-local wall-clock time before truncating to a date), not by
pulling every order into PHP; empty days are filled in afterward over a
range bounded by the same 366-day cap that bounds the query itself.

### GET /merchant/reports/top-items

```json
{
  "data": [
    { "product_name": "Cafe Latte (16oz)", "quantity_sold": 142, "net_cents": 2130000, "net_formatted": "₱21,300.00" },
    { "product_name": "Americano (12oz)", "quantity_sold": 98, "net_cents": 882000, "net_formatted": "₱8,820.00" }
  ]
}
```

Ordered by `quantity_sold` descending, `?limit=` (default 10, max 50).
Grouped by `product_name` **as stored on the order line** — the SNAPSHOT
(see § Line items are snapshots) — never by `product_id`. A renamed
product still reports under the name it sold as at the time; a deleted
product (`product_id` set `NULL`) still reports in full, because this
report never joins back to `products` for anything. Voided orders'
lines are excluded entirely — a canceled sale sold nothing.

## Shift report & receipt

Two read-only endpoints (P9), composing what P4–P8 already built rather
than adding new business logic:

| Method | Route                                        | Notes |
| ------ | --------------------------------------------- | ----- |
| `GET`  | `/api/v1/merchant/cash-sessions/{cashSession}/z-report` | One printable summary of a till shift; `?limit=` on `top_items` (default 10, max 50) |
| `GET`  | `/api/v1/merchant/orders/{order}/receipt`     | Printable receipt data for one order — no PDF, no printer driver |

Both are **read-only** (Sanctum's `last_used_at` aside — see § Polling),
gated by the SAME permission their read-only sibling already uses
(`drawer.view` for the Z-report, matching `GET /cash-sessions/{id}`;
`orders.view` for the receipt, matching `GET /orders/{id}`) rather than a
new permission of their own — a Z-report and a receipt are reads over a
resource someone can already see, not a distinct capability.

### The Z-report (per session, not per day)

`GET /cash-sessions/{cashSession}/z-report` answers "what happened in
THIS till shift" — every figure is attributed by `cash_session_id`,
**never** by a date range or calendar day. This is the deliberate
difference from every report in § Reporting above: an order rung up just
before midnight belongs to the session that was open when it was created,
not to whichever calendar day it lands on, so this endpoint uses no
`MerchantDay` boundary at all. Works identically on an **open** session
(every figure computed live) and a **closed** one (sales/top-items
recomputed from immutable order data return the same answer every time;
the cash block's `expected_cash`/`counted_cash`/`variance` are the values
FROZEN at close — see § Reconciliation).

```json
{
  "session": {
    "id": 42,
    "register_id": 3,
    "register_name": "Front Counter",
    "opened_by_user_id": 7,
    "opened_at": "2026-09-10T06:00:00.000000Z",
    "closed_by_user_id": null,
    "closed_at": null,
    "status": "open"
  },
  "float": { "opening_float_cents": 100000, "opening_float_formatted": "₱1,000.00" },
  "sales": {
    "orders_count": 4, "completed_count": 0, "pending_count": 3, "voided_count": 1,
    "gross_cents": 30000, "gross_formatted": "₱300.00",
    "discounts_cents": 0, "discounts_formatted": "₱0.00",
    "net_cents": 30000, "net_formatted": "₱300.00",
    "by_payment_method": {
      "cash": { "count": 1, "amount_cents": 14000, "amount_formatted": "₱140.00" },
      "gcash": { "count": 1, "amount_cents": 16000, "amount_formatted": "₱160.00" },
      "split": { "count": 1, "amount_cents": 10000, "amount_formatted": "₱100.00" }
    }
  },
  "top_items": [
    { "product_name": "Cafe Latte (16oz)", "quantity_sold": 3, "net_cents": 30000, "net_formatted": "₱300.00" }
  ],
  "cash": {
    "cash_sales_gross_cents": 24000, "cash_sales_gross_formatted": "₱240.00",
    "voided_cash_cents": 10000, "voided_cash_formatted": "₱100.00",
    "cash_in_cents": 20000, "cash_in_formatted": "₱200.00",
    "cash_out_cents": 5000, "cash_out_formatted": "₱50.00",
    "confirmed_remittances_cents": 30000, "confirmed_remittances_formatted": "₱300.00",
    "expected_cash_cents": 99000, "expected_cash_formatted": "₱990.00",
    "counted_cash_cents": null, "counted_cash_formatted": null,
    "variance_cents": null, "variance_formatted": null
  },
  "movements": [ /* CashMovementResource, compact */ ],
  "remittances": [ /* CashRemittanceResource, compact */ ],
  "generated_at": "2026-09-10T14:32:00.000000Z"
}
```

`sales` uses the **same accounting rules** as § Reporting's sales-summary
(voided excluded from revenue, pending included, a split order's
`cash_cents`/`gcash_cents` landing in the `cash`/`gcash` buckets exactly
once each, in addition to its own `split` bucket) — a Z-report and
sales-summary reading the same orders must never disagree about what they
were worth. `top_items` uses the same snapshot-name grouping as
top-items, voided lines excluded, scoped to this session
(`cash_session_id`) instead of a date range.

`cash` is `App\Domains\CashSessions\Actions\ReconcileCashSessionAction`'s
own output, **reused, never re-derived** — the same reasoning as
`GET /cash-sessions/{cashSession}`'s `reconciliation` block (see §
Reconciliation): two implementations of a money formula would eventually
disagree, so there is exactly one.

**Orders with a `NULL cash_session_id` are not part of any Z-report.**
They exist when a shop sells with no drawer open (see § Checkout
attribution) — `cash_session_id` simply has nothing to attribute them to.
They are NOT lost, though: § Reporting's date-range reports still capture
them, since those key off `created_at`, not the session.

### The receipt

`GET /orders/{order}/receipt` composes the merchant's profile with one
order's stored data — data only, no PDF/HTML rendering and no printer
integration; the frontend renders and prints it.

```json
{
  "merchant": {
    "name": "Merchant One", "legal_name": "Merchant One Food Corp.",
    "address_line1": "123 Rizal St", "address_line2": "Unit 4",
    "city": "Cebu City", "postal_code": "6000", "phone": "+63 917 000 0000",
    "tax_identifier": "123-456-789-000",
    "receipt_header": "Thank you for visiting!",
    "receipt_footer": "No refunds after 24 hours."
  },
  "order": {
    "id": 91, "order_number": "ORD-000123", "status": "completed",
    "created_at": "2026-09-10T06:12:00.000000Z",
    "voided": false, "voided_at": null,
    "cashier_name": "Alice Cashier",
    "lines": [
      {
        "product_name": "Cafe Latte (16oz)", "quantity": 2,
        "unit_price_cents": 12000, "unit_price_formatted": "₱120.00",
        "line_total_cents": 24000, "line_total_formatted": "₱240.00",
        "add_ons": [{ "name": "Extra shot", "price_cents": 3000, "price_formatted": "₱30.00" }]
      }
    ],
    "subtotal_cents": 24000, "subtotal_formatted": "₱240.00",
    "discount_cents": 2000, "discount_formatted": "₱20.00",
    "total_cents": 22000, "total_formatted": "₱220.00",
    "payment_method": "cash", "cash_cents": null, "gcash_cents": null
  },
  "generated_at": "2026-09-10T14:32:00.000000Z"
}
```

A **VOIDED** order still returns `200` with this same shape — **never a
`404`** — carrying `"voided": true` and its `voided_at` timestamp, front
and center rather than buried in `"status": "voided"`: a reprint of a
voided slip is an ordinary, expected action (proof for the customer that
it was reversed), so the endpoint must always answer, prominently.

**Snapshot discipline, read carefully — the two halves of this payload
follow OPPOSITE rules on purpose:**

- The `order` block is entirely the order's OWN stored snapshots — line
  names, unit prices, add-ons, totals, payment split — exactly like
  `OrderResource` (see § Line items are snapshots). A renamed or deleted
  product, or a repriced catalog, never changes a past receipt.
- The `merchant` block is read from the merchant profile **AS IT IS
  NOW**, not as it was at sale time. Reprinting a receipt after the shop
  edits its profile (a new `receipt_footer`, a corrected `tax_identifier`)
  shows the **current** header/footer — this is intended, not a bug:
  snapshotting the profile per order is explicitly out of scope this
  phase. A shop that changes its printed letterhead mid-shift will see
  old and new receipts differ if reprinted side by side; there is no
  endpoint that freezes the profile at sale time.

## Merchant profile & team members

Two things a shop needs before it can operate: an identity to print on a
receipt, and more than one person who can work it. Before this phase,
nothing carried a merchant's own details, and a merchant had exactly one
user — which made P4's "a remittance must be confirmed by someone other
than its creator" rule impossible to satisfy in practice.

| Method   | Route                              | Notes |
| -------- | ------------------------------------ | ----- |
| `GET`    | `/api/v1/merchant/profile`           | The caller's own merchant, flat, every profile field |
| `PATCH`  | `/api/v1/merchant/profile`           | Update profile fields only — see below |
| `GET`    | `/api/v1/merchant/team`               | List members: id, name, email, `role_in_merchant`, `is_owner`, `created_at` |
| `POST`   | `/api/v1/merchant/team`               | Add a member: `{ name, email, role_in_merchant }` |
| `PATCH`  | `/api/v1/merchant/team/{user}`        | Change `role_in_merchant` only |
| `DELETE` | `/api/v1/merchant/team/{user}`        | Detach from the merchant (never deletes the user row) |
| `POST`   | `/api/v1/auth/accept-invite`          | Public. `{ token, password }` → sets the password, returns a token like login |

### Profile

`merchants` gained nullable profile columns: `legal_name`,
`address_line1`, `address_line2`, `city`, `postal_code`, `phone`,
`contact_email`, `tax_identifier`, `receipt_header` (short text printed
above a receipt), `receipt_footer` (e.g. "Thank you!"), and `timezone`
(see the rule below). The existing `name` stays the display name. There
is no `{merchant}` route parameter anywhere in this vertical — "which
merchant" always comes from the caller's own `$user->merchant()`, so a
request can never even name another merchant's profile.

**`status`, `owner_user_id`, `id`, `name`, and `timezone` are not
editable through `PATCH /merchant/profile`.** The FormRequest defines
validation rules for none of them, so they never reach `validated()`,
and the Action only ever writes what `validated()` contains — sending
them in the request body is silently ignored, never applied, even though
all five are `$fillable` on `Merchant` for other write paths.

**TIMEZONE COLUMN RULE:** `timezone` is a column only. It is **not**
wired into `App\Domains\Orders\Support\MerchantDay` this phase —
`MerchantDay` still resolves the single `day_timezone` from
`config/merchant.php` for every merchant, unconditionally. Reading this
column at some call sites and not others would silently split day
boundaries between endpoints depending on rollout order — exactly the
UTC-vs-local bug class a previous phase fixed. A dedicated later phase
migrates `MerchantDay` to read this column in one atomic change, backfilling
existing merchants first, never gradually.

### Team members

`merchant_user`'s `role_in_merchant` is now a backed enum
(`App\Domains\Merchant\Enums\RoleInMerchant`: `owner` | `manager` |
`staff`), mirrored by a Postgres CHECK constraint the same way
`MerchantStatus` mirrors `merchants.status`. The third value was renamed
from `cashier` to `staff` in P7.1: GASA serves any food business — coffee
shops, stalls, bakeries, canteens — and "cashier" is till-specific
vocabulary that has no business being a platform-level role name.
`cashier` is rejected by both the CHECK constraint and `POST`/`PATCH
/merchant/team`'s validation (a normal `422 validation_failed`, no
special-casing) — it is not a valid `role_in_merchant` value anymore.

**`role_in_merchant` IS an authorization boundary as of P8** — see
§ Permissions below for the full permission catalog, the preset each
role maps to, and how it's enforced. (Earlier phases shipped this field
recorded-but-unenforced; that is no longer true.)

`POST /merchant/team` creates a `User` (with the `merchant` spatie role)
if none exists for that email, attaches the `merchant_user` pivot, and
always issues an invite (see below) so the new member sets their own
password:

- An email already attached to **this** merchant → `422
  member_already_exists`.
- An email belonging to a user **not already on this merchant** —
  whether they belong to no merchant yet or to a different one — → `422
  email_unavailable`. Deliberately generic and deliberately identical in
  shape to `member_already_exists`: it never reveals that the email is
  registered at all, let alone to which merchant, which would otherwise
  let a caller enumerate registered emails by probing this endpoint. No
  pivot is ever created on this path.

`{user}` on `PATCH`/`DELETE /merchant/team/{user}` route-model-binds
directly to `App\Domains\Auth\Models\User` — unlike every other
merchant-owned resource in this file, `User` has no `merchant_id` column
of its own to scope through `BelongsToMerchant`, so `TeamController`
checks membership in the caller's own merchant explicitly. A foreign
user id is a `404 not_found` on both routes, **never** a 403 — a 403
would confirm the row exists for some other merchant, the same leak
every other tenant-owned resource in this API is built to avoid.

**The owner can never be removed** — `DELETE` on the owner's own id is
`422 cannot_remove_owner`. `DELETE` otherwise only detaches the
`merchant_user` pivot row — the `User` account itself is never deleted,
and immediately loses merchant-portal access the moment the pivot is
gone (`User::merchant()` no longer resolves it).

**The owner can never be changed to a different role, either (P8)** —
`PATCH /merchant/team/{owner}` with any `role_in_merchant` other than
`owner` is `422 cannot_demote_owner`. There is exactly one owner per
merchant this phase (`Merchant::owner_user_id`), and the owner's preset
is the only one that carries the full permission catalog — silently
allowing this would leave the merchant's actual owner unable to do owner
things while `owner_user_id` still pointed at them. Changing anyone
else's role is unaffected.

### Permissions

A FIXED, code-defined permission catalog
(`App\Domains\Merchant\Enums\MerchantPermission`, a backed enum) —
nothing here is stored in the database. Three PRESETS
(`App\Domains\Merchant\Support\RolePresets`) map each `role_in_merchant`
to a set of catalog permissions; presets are the only source of
permissions this phase. Enforcement lives in Policies (`Gate`/
`->authorize()`), never inline in controllers or middleware — the
tenant-ownership checks every Policy already had stay exactly as they
were, with the permission check added alongside, checked SECOND (tenant
ownership always wins first, so a cross-tenant request is still a 404
regardless of the caller's role — see § Error shape's cross-tenant note
and `TenantLeakageTest.php`).

**The catalog:**

| Permission | Label |
| --- | --- |
| `orders.view` | View orders |
| `orders.create` | Check out (create orders) |
| `orders.complete` | Complete orders |
| `orders.void` | Void orders |
| `queue.view` | View the kitchen queue |
| `menu.view` | View the menu |
| `drawer.view` | View cash sessions |
| `drawer.open` | Open the drawer |
| `drawer.close` | Close the drawer |
| `drawer.movements` | Record cash movements |
| `remittances.create` | Create remittances |
| `remittances.confirm` | Confirm remittances |
| `reports.view` | View reports |
| `profile.view` | View the merchant profile |
| `profile.edit` | Edit the merchant profile |
| `team.view` | View team members |
| `team.manage` | Manage team members (add, change role, remove) |

**The presets** (`role_in_merchant` → permissions):

| Preset | Permissions |
| --- | --- |
| `owner` | **All** 17 catalog permissions. |
| `manager` | Every permission **except** `profile.edit` and `team.manage`. |
| `staff` | `orders.view`, `orders.create`, `orders.complete`, `queue.view`, `menu.view`, `drawer.view`, `drawer.open`, `drawer.movements`, `remittances.create`. **Not** `orders.void`, `drawer.close`, `remittances.confirm` (all three are "someone signs off" actions), **not** `reports.view`, and **not** `profile.*`/`team.*`. |

**Enforcement, endpoint by endpoint:**

| Policy | Method | Permission |
| --- | --- | --- |
| `OrderPolicy` | `viewAny`, `view` | `orders.view` |
| `OrderPolicy` | `create` | `orders.create` |
| `OrderPolicy` | `complete` | `orders.complete` |
| `OrderPolicy` | `void` | `orders.void` |
| `OrderPolicy` | `viewKitchenQueue` | `queue.view` |
| `OrderPolicy` | `viewReports` | `reports.view` |
| — (`MenuController`, no Policy class) | `hasMerchantPermission()` directly | `menu.view` |
| `CashSessionPolicy` | `viewAny`, `view` | `drawer.view` |
| `CashSessionPolicy` | `create` | `drawer.open` |
| `CashSessionPolicy` | `close` | `drawer.close` |
| `CashMovementPolicy` | `create` | `drawer.movements` |
| `CashRemittancePolicy` | `create` | `remittances.create` |
| `CashRemittancePolicy` | `confirm` | `remittances.confirm` |
| `MerchantPolicy` | `view` | `profile.view` |
| `MerchantPolicy` | `update` | `profile.edit` |
| `TeamMemberPolicy` | `viewAny` | `team.view` |
| `TeamMemberPolicy` | `create`, `update`, `delete` | `team.manage` |

`queue.view` and `reports.view` are their own permissions rather than
reusing `orders.view`, even though every preset in this phase happens to
pair them — a role that can see orders and a role that can see the
kitchen screen or the day's numbers are independently assignable
questions, and treating them as one would make a later custom-role phase
unable to tell them apart.

**Denial**: `403 permission_denied`, with the specific permission named
in `errors.permission` (e.g. `{ "permission": ["orders.void"] }`) so the
frontend can explain rather than guess. This is a DIFFERENT code from
the generic `403 forbidden` a tenant-ownership failure still renders as,
and different again from `403 merchant_inactive` (no active merchant at
all) and the portal role middleware's `403 forbidden` (wrong PORTAL
entirely, before any merchant-specific check runs) — all four are
distinguishable by `code`.

`GET /auth/me` additionally returns `permissions: string[]`, resolved
from the caller's `role_in_merchant` the same way (see § The user
payload below) — empty, never missing, when there is no active merchant.
**Additive**: the frontend should tolerate new keys appearing in this
list later without treating them as invalid — a later custom-role phase
adds values here, it does not restructure the field.

**Future**: custom per-merchant roles (a name + an arbitrary chosen
subset of `MerchantPermission` cases, configurable per merchant instead
of only the three fixed presets) are a LATER phase, layered on top of
this catalog without changing it — see `MerchantPermission`'s and
`RolePresets`' docblocks. Nothing in P8 adds an endpoint to edit
permissions or define custom roles.

### Invitations

An invite is a random 40-character token, hashed with SHA-256 before
storage (`team_invitations.token_hash`) — the plaintext is never
persisted anywhere, only returned once at creation and (local/development
only) logged. It expires after **72 hours** and is single-use
(`used_at`).

`POST /auth/accept-invite` is public and unauthenticated, rate-limited
the same as login (`throttle:login` — note the shared limiter keys on
`email+IP`; an accept-invite request has no `email` field, so it degrades
to keying on IP alone, which still rate-limits effectively per IP). A
missing, already-used, or expired token are **all** reported identically
as `422 invalid_invite` — distinguishing them would let a caller probe
which tokens exist and whether they've been redeemed. On success it sets
the real password, marks the token used, and returns `{ token, user }`
exactly like `POST /auth/login` does.

**No real email is sent this phase.** In `local`/`development`
environments only, `POST /merchant/team`'s response includes an
`invite: { token, expires_at, url }` block and the invite is logged
(`Log::info('Team invite created', ...)`); in every other environment
that block is omitted entirely. Real delivery (email, SMS) is a later
phase.

There is still no way for a merchant to invalidate and reissue their own
team member's invite from inside the merchant portal — only a platform
admin can, and only for a merchant's owner specifically, via
`POST /admin/merchants/{merchant}/resend-invite` (see § Platform admin →
Resending an invite).

### Default register on merchant creation

Every merchant now gets a default (`"Front Counter"`) register the
moment it's created — `App\Domains\Merchant\Actions\
EnsureDefaultRegisterAction`, invoked from `Merchant::booted()`'s
`created` event, so this holds regardless of how the merchant was made
(factory, seeder, `POST /admin/merchants` — see § Platform admin). This
**narrows** when `DefaultRegister`'s `NoRegisterConfigured` fallback (see
§ Registers) can be hit — it does not replace it; a merchant created
before this phase shipped still falls through to that graceful behavior.

## Platform admin

Before this phase, a merchant existed only because a seeder made one —
there was no way to onboard a real second merchant without `tinker`.
Every route below sits behind the `admin.api` middleware group
(`auth:sanctum` + `role:platform_admin` + `AllowsAdminContext`, see
bootstrap/app.php) — that group's `AllowsAdminContext` flag is what lifts
`BelongsToMerchant`'s tenant scope for the whole request; nothing here
calls `TenantContext::runInAdminContext()` itself.

| Method  | Route                                          | Notes |
| ------- | ----------------------------------------------- | ----- |
| `GET`   | `/api/v1/admin/merchants`                       | Paginated, filterable by `status` and `search` (matches merchant name or owner name/email) |
| `GET`   | `/api/v1/admin/merchants/{merchant}`             | Full profile, owner, team, registers, and status history |
| `POST`  | `/api/v1/admin/merchants`                        | Provision a merchant: `{ name, owner: { name, email }, profile? }` |
| `PATCH` | `/api/v1/admin/merchants/{merchant}/status`      | `{ status, reason? }` — legal transitions only |
| `POST`  | `/api/v1/admin/merchants/{merchant}/resend-invite` | Reissues the owner's invite, invalidating the prior one |
| `GET`   | `/api/v1/admin/audit-logs`                       | Paginated, filterable by actor, action, subject, and date range |

### Provisioning

`POST /admin/merchants` does five things in **one transaction**
(`App\Domains\Merchant\Actions\ProvisionMerchantAction`): creates the
owner `User` (a random password, exactly like `AddTeamMemberAction`
does for a regular team member — the invite flow is what sets a real
one), creates the `Merchant` in status `pending`, attaches the
`merchant_user` pivot with `role_in_merchant` `owner`, assigns the
`merchant` spatie role, and issues an invite via P7's
`CreateTeamInvitationAction` — the exact same mechanism
`POST /merchant/team` uses. The default register is **not** provisioned
explicitly here; `Merchant::booted()`'s `created` hook already guarantees
one the moment the row exists (see § Default register on merchant
creation below), so it happens automatically as a side effect of step
two. A failure at any point rolls back everything — no orphaned user,
pivot, or invite.

The owner `User` is created **before** the `Merchant`, not after:
`merchants.owner_user_id` is `NOT NULL`, and creating the `Merchant`
first would leave no owner to point it at.

An owner email that already belongs to any user (on this merchant, a
different one, or none at all) → `422 email_unavailable`, mirroring
`POST /merchant/team`'s exact reasoning — never revealing that the email
is registered.

A newly-provisioned merchant is **`pending`**, so its owner's invite
leads to a working login but a `403 merchant_inactive` on every merchant
route until an admin approves it (see § Status transitions below).

Like `POST /merchant/team`, the response includes the plaintext invite
`{ token, expires_at, url }` **only** in `local`/`development`
environments — never sent by real email this phase, never surfaced in
production by this endpoint either.

### Status transitions

`App\Domains\Merchant\Enums\MerchantStatus::allowedTransitions()` is the
single authority on which status changes are legal — mirroring
`App\Domains\Orders\Enums\OrderStatus`'s shape exactly:

- `pending` → `active` (approve) or `suspended` (block before ever going live)
- `active` → `suspended`
- `suspended` → `active` (reinstate)

Every other pair — including `active`/`suspended` → `pending`, and a
status "changed" to itself — is `422 invalid_transition`, the same code
`OrderStatus` already uses (the error contract has no per-domain field,
so the code is deliberately shared).

Every **legal** change writes one `audit_logs` entry
(`merchant.status_changed`) with the actor, old status, new status, and
the optional `reason` in `context`. An **illegal** transition writes none
— `MerchantStatus::assertCanTransitionTo()` throws before
`ChangeMerchantStatusAction` does any write.

Suspension bites immediately: `EnsureMerchantActive` (P7) already 403s
every merchant-portal request from a non-`active` merchant, checked on
every request, not cached — this phase is what makes that reachable in
practice, since before it there was no way to suspend a merchant at all
outside a factory state.

### Resending an invite

P7 shipped `CreateTeamInvitationAction` (issuing) and `AcceptInviteAction`
(redeeming), but no way to invalidate a still-live invitation.
`POST /admin/merchants/{merchant}/resend-invite`
(`App\Domains\Merchant\Actions\ResendMerchantInviteAction`) closes that
gap: it marks every unused `team_invitations` row for the merchant's
owner as `used_at = now()` — the same "used" mechanism
`AcceptInviteAction` already sets on redemption, not a new column or
state — before issuing a fresh token via `CreateTeamInvitationAction`.
The old token then collapses into the ordinary `422 invalid_invite` any
other already-used token gets; no new error case was needed on the
accept-invite side. Writes an `audit_logs` entry
(`merchant.invite_resent`).

### The audit log

`audit_logs` (`App\Domains\Platform\Models\AuditLog`) is a general-purpose,
append-only record of platform-admin actions: `actor_user_id`, `action`
(e.g. `merchant.created`, `merchant.status_changed`,
`merchant.invite_resent`), a polymorphic `subject`, `old_values`/
`new_values`/`context` (all nullable JSON), `ip_address`, and
`created_at` only — an entry is never updated once written.
`App\Domains\Platform\Actions\RecordAuditLogAction` is the only place
`AuditLog::create()` is ever called; no controller or Action writes one
inline.

**Deliberately NOT tenant-scoped** (no `merchant_id` column, no
`BelongsToMerchant`) — it spans every merchant by design, e.g. a
merchant's own status history draws from it directly. Because it carries
no tenant scope of its own, `GET /admin/audit-logs` (behind `admin.api`)
is its **only** access path; there is no merchant-portal or public route
that can reach it, by construction rather than by an extra check (see
`TenantLeakageTest`'s admin-boundary cases).

`GET /admin/audit-logs` filters by `actor_user_id`, `action`,
`subject_type` + `subject_id`, and an optional `from`/`to` date range
converted through `MerchantDay::forQuery()` — the same UTC-conversion
rule `ReportDateRangeRequest` already follows (see § Day boundaries),
just with no range cap: this is a plain indexed paginated read, not a
report's whole-range aggregate.

### Admin scoping discipline

Only `AllowsAdminContext`, registered on the `admin.api` group, may lift
`BelongsToMerchant`'s scope for a request. No admin controller or Action
in this phase calls `TenantContext::runInAdminContext()` directly — the
one documented exception to that rule remains `EnsureDefaultRegisterAction`
(see § Default register on merchant creation), which already ran before
this phase and needed it for a different reason (no acting merchant to
inherit from at all, admin request or not).

A `platform_admin` token gets a plain `403 forbidden` from
`role:merchant` on every `merchant.api` route (being an admin is not by
itself a bypass — see § Tenancy), and a `merchant` token gets the same
`403 forbidden` from `role:platform_admin` on every route in this
section, including `/admin/audit-logs`.

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

Deployments also need a scheduler entry, or nothing in
`routes/console.php` ever runs — today that means checkout idempotency
keys are never pruned and the table grows forever:

```
* * * * * cd /path/to/gasa-api && php artisan schedule:run >> /dev/null 2>&1
```

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
