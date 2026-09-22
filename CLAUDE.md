# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Grand VendorService — a multi-tenant job-management and billing system for an appliance service
centre. It tracks repair tickets from intake through technician assignment, SLA timers, closure,
charge freezing, and downstream settlement (company invoices and technician payouts). Each client
company ("vendor") can have its own rate cards, master lists (job types, symptoms, resolutions,
hold reasons, product categories) and operational settings, layered over a shared baseline.

Two apps in one repo:
- `api/` — CakePHP 5 (PHP 8.2+), JSON-only API under `/api`.
- `web/` — React 19 + TypeScript SPA (Vite, TanStack Router/Query, Radix UI, Tailwind v4).

## Running the stack

```
docker compose up
```

- App (SPA via Vite, proxying `/api`): http://localhost:7007
- Direct API access: http://localhost:7008
- MySQL: 7002, Mailpit web UI: 7003, Adminer: 7004, Redis: 7005

Request path in dev: `Browser → web (Vite) --/api--> api (FrankenPHP) → Redis / MySQL`. The SPA and
API are same-origin by design (Vite proxies `/api`) — this is load-bearing for auth, see below.

Copy `.env.example` to `.env` first; all host ports live in the 7000–7010 block.

Schema migrations run automatically on every container boot (`api/docker/entrypoint.sh`), not in
CI — Phinx tracks what's applied and skips already-applied migrations, so this is safe on
multi-replica boots.

## Common commands

**API (run inside the `api` container or with a local PHP 8.2+/composer setup):**
```
composer test              # phpunit
composer cs-check          # phpcs
composer cs-fix            # phpcbf
composer check             # test + cs-check
vendor/bin/phpunit tests/TestCase/Domain/Charge/ChargeBuilderTest.php   # single test file
vendor/bin/phpunit --filter testMethodName                             # single test method
bin/cake migrations migrate                                            # apply migrations manually
bin/cake migrations create SomeMigrationName                           # new migration
```

**Web:**
```
npm run dev       # vite dev server
npm run build      # tsc -b && vite build
npm run lint        # oxlint
npm run preview
```

## Architecture (API)

The API follows a layered domain design, not the CakePHP-default "fat table/controller" style.
Reading multiple layers together is necessary to understand any one business action:

- **`src/Controller/Api/*Controller.php`** — thin. Parses the request, calls a service or domain
  object, and returns the envelope via `ApiController::respond()` / `fail()`. All responses share
  one JSON shape: `{"data": ..., "meta": {...}}` on success, `{"error": {"code", "message",
  "fields", "detail"}}` on failure — `fields` maps directly to react-hook-form field errors.
- **`src/Service/*`** — application/orchestration layer (`TicketWorkflow`, `SettlementService`,
  `TicketAdjustmentService`, `TicketClosureService`, `EvidenceService`, `SpareService`,
  `SavingsService`, `CompanyConfigRepository`, `RateCardAuthoring`, `RateCardRepository`,
  `TicketNumberAllocator`). Controllers never mutate entities directly for anything with a side
  effect (event logging, SLA recalculation, charge freezing) — that always goes through a service
  method, because a controller patching an entity gets the status field right and silently drops
  the side effect.
- **`src/Domain/*`** — pure business logic, framework-free where possible:
  - `Domain/Rate` — resolves a rate-card item for a job (`RateResolver`, `RateContext`,
    `ResolvedRate`).
  - `Domain/Sla` — SLA window calculation, hold periods, rule matching (`SlaCalculator`,
    `SlaEvaluator`).
  - `Domain/Charge` — `ChargeBuilder` turns a priced, measured, completed ticket into **frozen**
    charge lines at closure. This runs once. Nothing downstream (invoice, payout, margin reports)
    ever re-derives a rate from a live rate card — everything reads the frozen `ChargeLine` rows.
    Corrections after the fact are new adjustment lines, never edits to a frozen line.
  - `Domain/Payout` — `PayoutBuilder` builds technician payout lines from the same frozen charges.
  - `Domain/Company` — per-company settings catalog/definitions.
  - `Domain/Exception` — domain-specific exceptions (`RateCardLockedException`,
    `TicketTransitionException`, `UnrateableTicketException`, etc.) that controllers translate to
    the JSON error envelope.
  - `Domain/Money.php` — value object; money is never a bare float in domain code.

### Multi-tenancy / company scoping

Several "master list" tables (job types, product categories, symptoms, resolutions, hold reasons)
follow a **shared-baseline-with-override** convention, implemented via
`Model/Table/CompanyScopedListTrait`:
- `company_id IS NULL` = shared baseline row, visible to everyone.
- `company_id = N` = that company's override/addition, shadowing the shared row with the same
  code.
- Uniqueness is `(code, company_id)`, NOT `code` alone — and NULL company_id must NOT be treated
  as a wildcard match by the uniqueness check (`allowMultipleNulls: false`), or two shared rows
  with the same code would both validate.
- `findForCompany()` returns both layers unresolved (caller must reconcile shadowing);
  `CompanyConfigRepository::masterList()` is the place that returns the already-resolved list.

Rate cards are versioned and **immutable once published** — never edited in place. Publishing
locks it (`RateCardLockedException` on attempted mutation); corrections are a new version or a new
line, mirroring the frozen-charge-line pattern above.

### Auth

Session-cookie auth, not JWT, because the SPA is same-origin with the API. Session cookie is
HttpOnly + SameSite=Lax (unreadable by any script, including injected XSS). CSRF cookie
(`gvsCsrfToken`) is deliberately NOT HttpOnly, since the SPA must read it and echo it back in the
`X-CSRF-Token` header (double-submit pattern) — this is safe because the CSRF cookie alone isn't a
credential. See `api/src/Application.php` and `web/src/lib/api.ts` for the full reasoning; don't
"fix" the CSRF cookie's httponly flag without re-reading that comment.

`HostHeaderMiddleware` enforces `App.fullBaseUrl` against the incoming `Host` header in production
(skipped when `debug` is on) to prevent host-header injection in generated URLs (OTP links,
invoice mail).

### Money handling in tests / migrations

Migration files are timestamp-prefixed (`YYYYMMDDHHMMSS_Description.php`), applied via Phinx
(`cakephp/migrations`). `config/Migrations/schema-dump-default.lock` /
`schema-dump-test.lock` are generated snapshots — don't hand-edit them.

## Architecture (Web)

- `src/lib/api.ts` — the single point of contact with the backend. Reads the `ApiEnvelope<T>` /
  `ApiErrorBody` shapes described above; throws `ApiError` (carries `status`, `fields`,
  `isUnauthenticated`). Any new backend call should go through/alongside this file, not via ad hoc
  `fetch`.
- `src/lib/auth.tsx` — session/auth context built on the cookie-based auth above.
- `src/pages/*Panel.tsx` — one file per major screen (Dashboard, Tickets, Invoicing, Receivables,
  Profit & Loss, Spares, Settings). `DeskShell.tsx` / `FieldShell.tsx` are the app shells for the
  desk (office) vs field (technician) views.
- Builds with `erasableSyntaxOnly` — avoid TS syntax that requires emitted code (e.g. constructor
  parameter property shorthand); declare class fields explicitly instead.

## Conventions worth knowing before editing

- Don't add a generic "update" endpoint for something that is actually two different events with
  different rules (e.g. `POST /tickets/{id}/charges` for agreed extra work on an open ticket vs.
  `POST /tickets/{id}/adjustments` for a correction to a bill already sent — these were
  deliberately split; see the comment in `api/config/routes.php`).
- Anything that changes ticket status, SLA state, or money must go through the relevant
  `Service`/`Domain` class, not a direct entity save in a controller.
- Frozen data (published rate cards, closed-ticket charge lines, sent invoices) is corrected by
  appending a new row, never by editing the frozen row in place.
- Evidence uploads (closure photos) are dispute evidence: in production they must go to S3/R2
  object storage (see `.env.example`), not a container volume — local filesystem fallback is
  dev-only.
