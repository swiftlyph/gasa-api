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
| 422    | `product_unavailable`  | Checkout referenced a product that is missing, not the caller's, or flagged unavailable — `errors.product_ids` lists them |
| 422    | `discount_exceeds_subtotal` | Checkout discount is larger than the server-computed subtotal   |
| 422    | `split_mismatch`       | Split payment whose `cash_cents` + `gcash_cents` don't equal the server-computed total |
| 409    | `idempotency_key_reuse` | Checkout reused an `Idempotency-Key` with a different request body |
| 409    | `session_already_open` | Opening a cash session on a register that already has one open        |
| 422    | `session_closed`       | A movement, remittance, or second close attempted on a closed cash session |
| 422    | `remittance_exceeds_cash` | A remittance amount exceeds the session's currently expected cash on hand |
| 422    | `remittance_already_confirmed` | Confirming a remittance that is already confirmed                |
| 403    | `confirmation_requires_second_user` | Confirming a remittance you created yourself                |
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
  `/merchant/registers`): `{ data: [...] }` — a `data` key so every list
  endpoint looks alike to a client, but no `links`/`meta`, because there
  are no pages to link to.
- **Scalar summary** (`/merchant/kitchen-queue/summary`): a flat object of
  the values themselves — `{ pending_count, oldest_waiting_seconds }`. No
  `data` wrapper, because there is no collection to wrap.
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
| **Scope** | **Today** in the app timezone by default. `?all=1` drops the date filter — and only the date filter; it never widens the tenant. |
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

A `registers` table exists from day one, with **one default register
seeded per merchant** — a single-till shop never notices it exists. "The
default" is the merchant's oldest active register
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
own inputs. The formula, in the order its terms are summed:

```
expected_cash =
    opening_float
  + cash sales attributed to the session
  − voided orders' cash contribution
  + cash_in movements − cash_out movements
  − confirmed remittances
```

"Cash sales" means the **cash portion only**: a pure-cash order's full
`total_cents`, plus a split order's `cash_cents` half. **GCash never
counts**, in either direction — gcash settles electronically and never
touches the physical till, so a voided gcash order changes nothing here,
while a voided cash order subtracts back out exactly the cash portion it
had contributed. Only **confirmed** remittances subtract; a pending one is
a claim, not proof, and counting it early would make the drawer look short
of cash it still physically holds.

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
