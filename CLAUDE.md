# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**HermeX POS** — Laravel 12 API backend for a point-of-sale system with inventory management, purchasing, work orders, and multi-warehouse operations. Frontend is a separate Vue 3/Inertia app in `~/herd/pos-frontend`.

- **Runtime**: PHP ^8.2, SQLite (test), PostgreSQL (dev/prod)
- **Auth**: Laravel Sanctum (session-based + optional tokens)
- **Key packages**: Spatie Activity Log, Maatwebsite Excel, DomPDF, Picqer Barcode
- **Testing**: PestPHP ^4.3, Laravel Dusk (browser)
- **Language convention**: Error messages and comments in Spanish; code identifiers in English

## Essential Commands

```bash
# Dev server (concurrent: serve + queue + vite)
composer dev

# Run all tests (clears config cache, uses SQLite :memory: by default)
composer test

# Run by priority tier
php artisan test tests/Feature/P0 --stop-on-failure
php artisan test tests/Feature/P1
php artisan test tests/Feature/P2

# Single test file
php artisan test tests/Feature/P0/WarehouseEnforcementTest.php

# Format code
./vendor/bin/pint

# Show API routes
php artisan route:list --columns=method,uri,middleware | grep api/v1

# Fresh database
php artisan migrate:refresh --seed
```

## Architecture

### Request Flow

```
routes/api.php (single entry)
  → middleware: web, auth, throttle:120,1
  → loads 23 contextual route files (api_products.php, api_sales_orders.php, etc.)
    → Controllers (app/Http/Controllers/Api/) — thin, coordinate only
      → Form Requests (app/Http/Requests/) — all validation
        → Services (app/Services/) — business logic, transactions
          → Models (app/Models/) — Eloquent ORM, relationships
```

### Middleware (bootstrap/app.php)

| Alias | Class | Purpose |
|-------|-------|---------|
| `role` | `CheckRole` | Validates role membership + `is_active` flag. Supports multiple: `role:admin,manager` |
| `warehouse_context` | `EnforceWarehouseContext` | Forces `warehouse_id` on every request. Admins can override; non-admins must have `current_warehouse_id` set or get 403 |
| — | `RequestTimingMiddleware` | Logs slow requests (>500ms), adds `X-Response-Time` header |

### Key Design Decisions

**Warehouse-scoped multi-tenancy**: All inventory, POS sessions, transfers, and movements require warehouse context. Bypass via `withoutMiddleware(['warehouse'])` on specific routes.

**Thin controllers + Services**: Controllers only handle request/response coordination. Business logic, DB transactions, and complex queries live in Services (13 total in `app/Services/`).

**Pricing chain**: Product → ProductVariant (1:many) → ProductPricing (per variant). Categories have `pricing_formula` overrides. `PriceHelper::roundUp()` applies configurable rounding increment from store settings.

**Inventory model**: ProductInventory tracks stock per product/variant/warehouse/batch. InventoryMovement logs all operations. WarehouseBatch + ProductBatch enable lot tracking.

**Store Settings**: Database-driven config (tax rate, modules, rounding, SKU format). Loaded via Settings model, consumed by frontend via `useStoreSettings` composable.

### Database Schema (key relationships)

- Products → ProductVariants → ProductPricing + ProductInventory (per variant/warehouse/batch)
- Customers → CustomerTransactions (credit ledger, `store_credit` balance tracking)
- SalesOrder → SalesOrderItem → SalesOrderPayment (partial payments + store_credit)
- PurchaseOrder → PurchaseOrderItem → PurchaseOrderPayment
- VehicleMake → VehicleModel → VehicleEngine → VehicleChassis + ProductVehicleCompatibility
- WorkOrder (states: draft → assigned → in_progress → completed → invoiced)
- InventoryTransfer (source → destination → received)
- ActivityLog (Spatie: all mutations by user/timestamp)

### Role Hierarchy

`admin`, `manager`, `seller`, `cashier`, `buyer`, `assistant` — enforced via `CheckRole` middleware or controller guards.

Route access groups in `routes/api.php`:
- **All authenticated**: dashboard, inventory, products, warehouses, vehicles, categories, brands
- **POS roles** (seller, cashier, manager, admin): sales, POS sessions, tickets, quotes
- **Purchasing** (buyer, manager, admin): purchase orders, suppliers, reorder, accounts payable
- **Reports** (manager, admin): reports
- **Admin only**: users, backup, data reset, activity logs

## Test Organization

Tests use a priority-based directory structure:
- `tests/Feature/P0/` — Critical path (warehouse enforcement, role middleware, inventory transfers)
- `tests/Feature/P1/` — Important flows (sales, products, POS sessions, rate limiting)
- `tests/Feature/P2/` — Feature tests (work orders, customer balance)

**SQLite compatibility note**: `ILIKE` (PostgreSQL) is not supported in SQLite. Tests that require case-insensitive search may need to be skipped on SQLite or use `LIKE` (which is case-insensitive by default in SQLite). In production/dev code, prefer `ILIKE` for PostgreSQL.

## Code Conventions

### From PATTERNS.md (authoritative reference)

- **Never use `$guarded = []`** — always define `$fillable` explicitly on models
- **Form Requests for all input** — never validate inline in controllers
- **Services handle transactions** — `DB::transaction()` wraps all multi-step mutations
- **Git commits**: Conventional Commits format (`feat:`, `fix:`, `refactor:`, etc.)
- **API responses**: `{ "success": true, "data": {...}, "message": "optional" }`
- **Configurable over hardcoded**: anything that might change goes to store settings (Settings model), not constants

### Adding a New Feature

1. Route file: `routes/api_feature.php` → require in `routes/api.php`
2. Controller: `app/Http/Controllers/Api/FeatureController.php` (thin)
3. Form Request: `app/Http/Requests/StoreFeatureRequest.php`
4. Service: `app/Services/FeatureService.php` (if complex logic needed)
5. Test: `tests/Feature/P1/FeatureTest.php`

### Migration Compatibility

Migrations must work on both SQLite (tests) and PostgreSQL (dev/prod). SQLite doesn't support `CHECK` constraints or `ENUM` column modifications well — use compatible approaches (string columns with application-level validation, or dual-strategy migrations).

## Pitfalls

1. **Warehouse context forgotten** — Most operations require warehouse_id; middleware enforces this but tests will fail if not accounted for
2. **Silent data loss on update** — If conditional logic is added, ensure absence of a field doesn't trigger deletion (e.g., missing attributes array ≠ delete all attributes)
3. **Breaking existing relationships** — Never delete/rename columns in use; migrations must be backward-compatible
4. **Missing activity logging** — Critical mutations must be auditable via Spatie Activity Log
5. **ILIKE in tests** — SQLite doesn't support `ILIKE`; use `LIKE` or skip test on SQLite. In app code targeting PostgreSQL, `ILIKE` is preferred for case-insensitive search

## Reference

- **Patterns**: `_PROJECT_DOCS/PATTERNS.md` (detailed code conventions with examples, in Spanish)
- **Project context**: `_PROJECT_DOCS/AGENT_CONTEXT.md` (feature list, phase roadmap, bug tracking)
- **Frontend repo**: `~/herd/pos-frontend` (Vue 3 + Inertia, TypeScript)
