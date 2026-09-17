# User management & RBAC — design

The target design for managing users across all four audiences, and the
subset being built now.

Written so that the company and employee phases **extend** this schema
rather than migrate away from it. Every table and column below is either
built today or has its shape fixed here, so the later phases are additive.

---

## 1. The three layers of authorization

The codebase already separates these. Keeping them separate is the single
most important decision in this design, and it is already made — this
document only extends it.

| Layer | Question it answers | Storage | Enforced by |
| --- | --- | --- | --- |
| **Portal role** | Which app may you sign in to? | spatie `roles` (global) | `role:` middleware on the route group |
| **Tenant membership** | Which tenant are you part of? | `merchant_user` pivot / `company_user` pivot | `BelongsToMerchant` / `BelongsToCompany` global scopes |
| **Tenant permission** | What may you do inside it? | *code* — enum + presets | Policies, throwing `403 permission_denied` |

**Why permissions are code, not rows.** `MerchantPermission` is a backed
enum and `RolePresets` a `match` expression. Nothing is stored. That makes
the permission catalog versioned with the code that enforces it, and makes
a missing enforcement a test failure rather than a data problem. A later
custom-role phase layers a *name + chosen subset* on top without changing
the catalog. Do not start writing rows into spatie's `permissions` table —
that reintroduces the entanglement this split exists to avoid.

**Why spatie stays global (`teams => false`).** Portal roles answer "which
app", which is genuinely global. Per-tenant roles live on the pivot. Turning
spatie's team mode on would add a `team_id` to `roles` and `model_has_roles`
and make every existing role row ambiguous — a migration against live data
for no gain, since the pivot already carries per-tenant roles.

---

## 2. Target schema

### 2.1 Identity root — `users`

One table for every human, whatever portal they use. Exists today.

```
users
  id
  name
  email          citext, UNIQUE          -- case-insensitive
  company_id     FK -> companies, NULL   -- see 2.3
  password       hashed
  deleted_at     NULL                    -- soft delete = deactivated
  created_at / updated_at
```

**Deactivation is a soft delete, and that is not a shortcut.** Ten tables
reference `users`, nine of them `RESTRICT` on delete:

| Table | Column | On delete |
| --- | --- | --- |
| `merchants` | `owner_user_id` | RESTRICT |
| `orders` | `created_by_user_id`, `voided_by_user_id` | RESTRICT |
| `cash_sessions` | `opened_by_user_id`, `closed_by_user_id` | RESTRICT |
| `cash_movements` | `created_by_user_id` | RESTRICT |
| `cash_remittances` | `created_by_user_id`, `confirmed_by_user_id` | RESTRICT |
| `audit_logs` | `actor_user_id` | RESTRICT |
| `checkout_idempotency_keys` | `user_id` | RESTRICT |
| `merchant_user` | `user_id` | CASCADE |
| `team_invitations` | `user_id` | CASCADE |

A user who has ever transacted **cannot** be hard-deleted without erasing
financial attribution. `LoginAction` resolves users with
`User::where('email', ...)->first()`, which excludes soft-deleted rows, so
`deleted_at` already blocks sign-in with no new mechanism.

### 2.2 Merchant membership — `merchant_user` (exists)

```
merchant_user
  user_id           FK -> users      CASCADE
  merchant_id       FK -> merchants  CASCADE
  role_in_merchant  CHECK (owner|manager|staff)
  UNIQUE (user_id, merchant_id)
```

Many-to-many on purpose: its migration notes a user may later belong to
several merchants. `User::merchant()` returns the single **active** one
today; the schema does not need changing when that relaxes.

### 2.3 Company membership — the decision to make now

`users.company_id` exists, nullable, **with no foreign key**, commented
"FK added in the tenancy phase". It is the one piece of this design that is
half-built, and it encodes an assumption worth challenging before it
becomes load-bearing.

**Recommended: a `company_user` pivot, mirroring `merchant_user`.**

```
company_user
  user_id          FK -> users      CASCADE
  company_id       FK -> companies  CASCADE
  role_in_company  CHECK (admin|finance|member)
  UNIQUE (user_id, company_id)
```

Three reasons:

1. **Symmetry.** Two membership mechanisms for two tenant types means every
   future feature touching "the user's tenant" branches twice.
2. **Per-company roles need somewhere to live.** A column cannot carry
   `role_in_company`. Merchants already proved the pivot is where that goes.
3. **It is the same question already answered once.** `merchant_user`'s
   migration argues the pivot case explicitly; nothing makes companies
   different.

`users.company_id` is then **dropped** in the company phase — it has no FK
and (verified) zero readers in application code, so removing it costs one
migration and breaks nothing.

> If instead a user must belong to exactly one company forever, keep the
> column and add the FK. Decide before the company phase ships; after it,
> changing costs a data migration.

### 2.4 Companies — the tenant table (company phase)

Mirrors `merchants` exactly, including the CHECK-constraint-plus-PHP-enum
pattern used for `merchants.status`.

```
companies
  id
  name
  status          CHECK (pending|active|suspended)   -- CompanyStatus enum
  owner_user_id   FK -> users  RESTRICT
  created_at / updated_at
```

### 2.5 Invitations — one table, one flow

`team_invitations.merchant_id` is currently `NOT NULL`. **Make it nullable**
so one invite mechanism serves every audience.

```
team_invitations
  id
  merchant_id   FK -> merchants  NULL   -- null = platform/company invite
  company_id    FK -> companies  NULL   -- added in the company phase
  user_id       FK -> users      CASCADE
  token_hash    UNIQUE                  -- SHA-256, plaintext never stored
  expires_at                            -- 72h
  used_at       NULL                    -- single-use
```

`AcceptInviteAction` was traced: it resolves purely by `token_hash` and
checks used/expired. **It never reads `merchant_id`.** The `NOT NULL` was an
assumption, not an invariant, and the redemption path already works for a
merchant-less invite.

One flow matters more than the isolation a second table would buy: expiry,
single-use, hashing and rate-limiting are each one implementation to audit
rather than two, and the less-used path is where such bugs survive.

**One bug to fix while doing this.** `AcceptInviteAction` hardcodes
`createToken('merchant')`. Nothing calls `tokenCan()` or checks abilities —
authorization comes from the spatie role — so the name is cosmetic *today*,
but an admin redeeming an invite gets a session labelled `merchant`. That is
misleading in an audit trail and dangerous if ability checks ever land. The
token name must derive from the user's actual portal role.

---

## 3. Role model

### 3.1 Portal roles (global, spatie, fixed)

| Portal | Role | Audience |
| --- | --- | --- |
| `admin` | `platform_admin` | GASA staff |
| `company` | `company_admin` | A company's administrator |
| `employee` | `employee` | A company's staff member |
| `merchant` | `merchant` | Merchant portal user |

The portal key is **not** the role name — `admin` maps to `platform_admin`.
Confusing the two is a real bug that already shipped once in the frontend.

### 3.2 Tenant roles (per-tenant, pivot column, CHECK-constrained)

| Tenant | Column | Values |
| --- | --- | --- |
| Merchant | `merchant_user.role_in_merchant` | `owner` / `manager` / `staff` |
| Company | `company_user.role_in_company` | `admin` / `finance` / `member` |

Each value maps to a permission preset in code. `MerchantPermission` +
`RolePresets` exist; `CompanyPermission` + `CompanyRolePresets` mirror them
in the company phase.

### 3.3 Platform permissions — deliberately deferred

`platform_admin` is all-or-nothing today, and that is correct for now: it is
the **only** admin-side role. A catalog would have one consumer and nothing
to exercise it — `PermissionsTest`'s "every permission is enforced" guard
cannot meaningfully exist for a single role.

Add `PlatformPermission` when a second admin role (e.g. `support`, read-only)
genuinely exists. It is additive: `role:platform_admin` on the route group
keeps working throughout.

---

## 4. Build order

Each phase depends only on those above it.

| Phase | Delivers | Blocked by |
| --- | --- | --- |
| **A — now** | Admin user management over the four existing roles; nullable invite `merchant_id`; token-name fix | nothing |
| **B** | `companies`, `Company`, `CompanyStatus`, `BelongsToCompany`, drop `users.company_id` | A |
| **C** | `company_user` pivot, `CompanyPermission`, `CompanyRolePresets`, company team management | B |
| **D** | Employee domain — requires the business model to be defined first | B/C |
| **E** | `PlatformPermission` catalog | a second admin role existing |

**Phase D is not schedulable yet.** `employee` appears only in
`config/portals.php`, the seeders, and a `whoami` stub. There is no model,
table, or business rule. Note `order_beneficiaries` is *not* it — that holds
senior/PWD statutory-discount claimants identified by name and ID number,
with no user account.

Open questions to answer before D:

- What is an employee — a company's staff member spending a meal allowance?
- Can one person be an employee at a company *and* staff at a merchant?
- Do employees have per-company roles, or is `employee` the whole story?

---

## 5. Phase A — what is being built now

### 5.1 Endpoints

All behind `admin.api` (`auth:sanctum` + `role:platform_admin` +
`AllowsAdminContext`).

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/admin/users` | Paginated; filter by `role`, `status`, `search` |
| `POST` | `/admin/users` | Create + assign portal role + issue invite |
| `GET` | `/admin/users/{user}` | Detail, memberships, role history |
| `PATCH` | `/admin/users/{user}/role` | Change portal role |
| `POST` | `/admin/users/{user}/resend-invite` | Reissue, invalidating prior |
| `DELETE` | `/admin/users/{user}` | Deactivate (soft delete) |
| `POST` | `/admin/users/{user}/restore` | Reactivate |

### 5.2 Security invariants

Each is enforced in an Action (not a controller or FormRequest) and has its
own test.

| # | Invariant | Failure |
| --- | --- | --- |
| 1 | An admin cannot change **their own** role | `422 cannot_modify_self` |
| 2 | An admin cannot deactivate **themselves** | `422 cannot_modify_self` |
| 3 | The **last** `platform_admin` cannot be demoted or deactivated | `422 last_platform_admin` |
| 4 | A merchant **owner** cannot be deactivated | `422 user_owns_merchant` |
| 5 | Email already in use → generic `422 email_unavailable` | never reveals registration |
| 6 | Every mutation writes an `audit_logs` entry | — |
| 7 | Invite tokens: 40-char random, SHA-256 at rest, single-use, 72h | — |
| 8 | Deactivation is a soft delete; hard delete is never exposed | — |

Invariants 1–3 are lockout protection: without them a platform can be
locked out of its own admin portal with no recovery path but database
access. 4 mirrors the existing `cannot_remove_owner` rule.

**Deliberately NOT included:** no endpoint sets a password directly. The
invite flow is the only way a password is established, so an admin never
learns another user's credentials.

### 5.3 Files

Follows the documented domain layout. User management is a Platform-domain
concern — it spans every audience and is reachable only by platform admins.

```
app/Domains/Platform/
├── Actions/
│   ├── CreateUserAction.php
│   ├── ChangeUserRoleAction.php
│   ├── DeactivateUserAction.php
│   ├── RestoreUserAction.php
│   └── ResendUserInviteAction.php
├── Exceptions/
│   ├── CannotModifySelf.php
│   ├── LastPlatformAdmin.php
│   └── UserOwnsMerchant.php
├── Http/
│   ├── Controllers/AdminUserController.php
│   ├── Requests/{IndexAdminUsers,CreateUser,ChangeUserRole}Request.php
│   └── Resources/{AdminUserList,AdminUserDetail}Resource.php
└── Models/AuditLog.php            (exists)
```

Migrations:

```
..._make_team_invitations_merchant_id_nullable.php
```

### 5.4 Response shape

`AdminUserListResource` — compact, every value from the eager-loaded query:

```json
{
  "id": 12,
  "name": "Jane Dela Cruz",
  "email": "jane@example.test",
  "roles": ["merchant"],
  "status": "active",
  "merchant": { "id": 3, "name": "Cafe One", "role_in_merchant": "manager" },
  "created_at": "2026-09-17T…"
}
```

`status` is derived (`deleted_at === null ? "active" : "deactivated"`) rather
than stored — one source of truth.

---

## 6. Invariants that must survive every later phase

1. **Tenant identity never comes from request input.** It derives from the
   authenticated user. A `merchant_id` or `company_id` in a payload is never
   authoritative.
2. **No tenant means no rows, never all rows.** Global scopes apply an
   always-false condition rather than skipping themselves.
3. **Cross-tenant access is a 404, not a 403.** A 403 confirms the row
   exists for someone else.
4. **Permission checks run second**, after tenant ownership — so a
   cross-tenant request is a 404 regardless of the caller's role.
5. **Authorization lives in Policies**, never inline in controllers.
6. **Writes go through Actions**, never directly from controllers.
7. **Every admin mutation is audited** through `RecordAuditLogAction`.
