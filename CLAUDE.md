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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.16
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/dusk (DUSK) - v8
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `pest-testing` — Tests applications using the Pest 4 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.
- `laravel-11-12-app-guidelines` — Guidelines and workflow for working on Laravel 11 or Laravel 12 applications across common stacks (API-only or full-stack), including optional Docker Compose/Sail, Inertia + React, Livewire, Vue, Blade, Tailwind v4, Fortify, Wayfinder, PHPUnit, Pint, and Laravel Boost MCP tools. Use when implementing features, fixing bugs, or making UI/backend changes while following project-specific instructions (AGENTS.md, docs/).
- `laravel-specialist` — Build and configure Laravel 10+ applications, including creating Eloquent models and relationships, implementing Sanctum authentication, configuring Horizon queues, designing RESTful APIs with API resources, and building reactive interfaces with Livewire. Use when creating Laravel models, setting up queue workers, implementing Sanctum auth flows, building Livewire components, optimising Eloquent queries, or writing Pest/PHPUnit tests for Laravel features.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs for the user.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
