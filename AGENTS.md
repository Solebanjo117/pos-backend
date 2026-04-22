# AGENTS.md

## Project

HermeX POS — Laravel 12 API backend for a point-of-sale system. Frontend is a separate Vue 3/Inertia app (`~/herd/pos-frontend`).

- **Runtime**: PHP ^8.2, **PostgreSQL** (dev/prod), **SQLite :memory:** (tests)
- **Auth**: Sanctum (session-based, optional tokens)
- **Key packages**: Spatie Activity Log, Maatwebsite Excel, DomPDF, Picqer Barcode, Laravel Dusk
- **Language convention**: Code identifiers in English; user-facing strings and error messages in **Spanish**

## Commands

```bash
composer dev                    # Concurrent: serve + queue + vite
composer test                   # All tests (clears config cache first, SQLite :memory:)
composer setup                   # First-time: install, .env, key, migrate, npm

php artisan test tests/Feature/P0/WarehouseEnforcementTest.php  # Single test file
php artisan test tests/Feature/P0 --stop-on-failure             # Priority tier
php artisan test tests/Feature/P1
php artisan test tests/Feature/P2

./vendor/bin/pint               # Format code (Laravel Pint)
php artisan route:list --columns=method,uri,middleware | grep api/v1  # API routes
php artisan migrate:refresh --seed   # Fresh DB with seed data
```

## Architecture

### Request Flow

```
routes/api.php (single entry, prefix: /api/v1)
  → middleware: web, auth, throttle:120,1
  → requires 30 contextual route files (api_products.php, api_sales_orders.php, etc.)
    → Controllers (app/Http/Controllers/Api/) — thin, coordinate only
      → Form Requests (app/Http/Requests/) — all validation
        → Services (app/Services/) — business logic, DB transactions
          → Models (app/Models/) — Eloquent ORM + relationships
```

### Middleware

| Alias | Class | Behavior |
|-------|-------|----------|
| `role` | `CheckRole` | Validates role + `is_active` flag. Supports multiple: `role:admin,manager`. Returns 403 with `required_roles` / `your_role` on failure. |
| `warehouse_context` | `EnforceWarehouseContext` | Forces `warehouse_id` on every request. Admins can specify any accessible warehouse; non-admins must have `current_warehouse_id` set or get **409**. Bypass: `withoutMiddleware(['warehouse'])`. |

### Warehouse Scoping (mandatory)

All inventory, POS sessions, transfers, and movements are warehouse-scoped. Two modes:
- **Multi-warehouse** (default): middleware enforces warehouse context per request
- **Single warehouse**: enabled via `.env` — `POS_SINGLE_WAREHOUSE_MODE=true` + `POS_DEFAULT_WAREHOUSE_ID=1`. Auto-assigns default warehouse, bypasses selection UI.

### Form Request → Controller → Service Pattern

Controllers must **never** contain validation or business logic. Use Form Requests for input validation, delegate to Services for any multi-step or transactional operation. 13 Services exist in `app/Services/`.

### Pricing Chain

Product → ProductVariant (1:many) → ProductPricing (per variant). Categories have `pricing_formula` overrides. `PriceHelper::roundUp()` applies store-wide rounding increment from Settings.

### API Response Format

`{ "success": true, "data": {...}, "message": "optional" }`

## Test Organization

- `tests/Feature/P0/` — Critical path (warehouse enforcement, role middleware, inventory transfers)
- `tests/Feature/P1/` — Important flows (sales, products, POS sessions)
- `tests/Feature/P2/` — Nice-to-have (work orders, customer balance)

Tests run on SQLite :memory: via phpunit.xml overrides. **`ILIKE` is PostgreSQL-only** — use `LIKE` in tests (case-insensitive by default in SQLite). In app code, prefer `ILIKE` for PostgreSQL.

## Key Conventions

- **Never use `$guarded = []`** on models — always define `$fillable` explicitly
- **Form Requests for all input** — no inline validation in controllers
- **Services handle transactions** — `DB::transaction()` wraps multi-step mutations
- **Configurable over hardcoded** — use Settings model, not constants
- **Git commits**: Conventional Commits format (`feat:`, `fix:`, `refactor:`)
- **Activity logging** — critical mutations must be auditable via Spatie Activity Log

## Adding a Feature

1. Route file: `routes/api_feature.php` → `require` in `routes/api.php`
2. Controller: `app/Http/Controllers/Api/FeatureController.php`
3. Form Request: `app/Http/Requests/StoreFeatureRequest.php`
4. Service: `app/Services/FeatureService.php` (if complex logic)
5. Test: `tests/Feature/P1/FeatureTest.php`
6. Verify: `php artisan test` + `php artisan route:list`

## Pitfalls

1. **Warehouse context** — Most operations require `warehouse_id`. Tests will fail if not set. Middleware returns 409 (not 403) when missing.
2. **SQLite vs PostgreSQL** — Migrations must work on both. No `CHECK` constraints or `ENUM` modifications in SQLite; use string columns + app-level validation. `ILIKE` unavailable in SQLite tests.
3. **Silent data loss** — Missing attributes on update must not trigger deletion (e.g., empty array ≠ delete all).
4. **Breaking relationships** — Never delete/rename columns in use; migrations must be backward-compatible.

## Reference

- **Patterns & conventions**: `_PROJECT_DOCS/PATTERNS.md` (detailed examples, in Spanish)
- **Project context**: `_PROJECT_DOCS/AGENT_CONTEXT.md` (feature list, bugs, roadmap)
- **Single warehouse mode**: `SINGLE_WAREHOUSE_MODE.md`
- **POS config**: `config/pos.php`
- **CORS origins**: `pos-frontend.test`, `localhost:5173`, `hermexpos.app` (see `config/cors.php`)