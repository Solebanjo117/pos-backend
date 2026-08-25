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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.22
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

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

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
