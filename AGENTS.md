# Repository Guidelines

## Project Structure & Module Organization

This CodeIgniter 4 PHP REST API supports business operations. Request handlers live in `app/Controllers`, persistence in `app/Models`, and shared base classes in `app/Core` (`MYT_Controller`, `MYT_Model`). Routes and configuration belong in `app/Config`; reusable services and helpers live in `app/Libraries` and `app/Helpers`. Tests are organized under `tests/unit`, `tests/database`, and `tests/session`, with fixtures in `tests/_support`. SQL changes and repair procedures live in `sql/`; operational tooling lives in `scripts/`. `assets/` holds local assets, `writable/` holds runtime data, and `vendor/` contains Composer dependencies.

## Build, Test, and Development Commands

Use PHP 8.1 or newer, as required by the application bootstrap; CI uses PHP 8.2.

- `composer install`: install locked dependencies, including development tools.
- `php spark routes`: inspect registered routes.
- `composer test`: run the PHPUnit suite.
- `vendor/bin/phpunit --filter SecurityTest`: run the security regression tests used in CI.
- `composer phpstan`: run PHPStan level 6, currently scoped to `app/Filters`.
- `composer audit`: check dependency advisories.

For local development, configure `.env` from `.env.example` and use XAMPP/Apache with the repository's root `index.php` and `.htaccess`. Ensure `writable/` is writable. There is no separate frontend build step.

## Coding Style & Naming Conventions

Use four-space indentation and follow surrounding brace style. Match class names to filenames and preserve existing module naming, including names such as `Billing_entry` and snake_case methods such as `get_details`. Follow the `App` namespace layout. Keep model `$allowedFields`, payload validation, and schema changes aligned. Use SQL parameter bindings for input values. No dedicated formatter is configured.

## Workflow for new features
1. Read `Templates.php` and `Template.php` before writing any code.
2. Create the controller and model by following those templates exactly.
3. Reuse base classes/traits from `app/Core/`.

## Coding standards
- PSR-12, 4-space indent, `declare(strict_types=1);` where the templates use it.
- Classes Snake_case, methods snake_case, DB columns snake_case.
- Controllers stay thin: validation and response only. Queries live in models.
- Use soft deletes (`deleted_at`) instead of hard deletes.

## Testing Guidelines

Use PHPUnit 9 and CodeIgniter test utilities. Name files `*Test.php` and methods `testDescriptiveBehavior`. Add regression tests for changed business rules and authorization checks. Configure database tests against a dedicated test database. Coverage requires Xdebug; no minimum coverage percentage is configured.

## Commit & Pull Request Guidelines

Recent commits use `fix:`, `enh:`, and occasionally `feat:` prefixes with short descriptive subjects. Follow that pattern. PRs should explain the behavior change, link relevant issues, list validation performed, and document any SQL migration, deployment order, or rollback steps.

## Security & Configuration

Keep credentials, tokens, database exports containing sensitive records, and runtime uploads out of commits. Review SQL repair instructions before execution; use local or test data for verification. Preserve existing authentication, branch/company scoping, and soft-delete behavior.
