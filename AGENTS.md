# Repository Guidelines

## Project Structure & Module Organization

This is a Laravel application. Core application code is in `app/`: keep HTTP controllers in `app/Http/Controllers`, Eloquent models in `app/Models`, request validation in `app/Http/Requests`, and view components in `app/View/Components`. Routes live in `routes/`; configuration is in `config/`.

Database migrations, factories, and seeders belong under `database/`. Blade views, JavaScript, and CSS source files are in `resources/`, while built public assets are served from `public/`. Keep automated tests in `tests/Feature` or `tests/Unit` to match their scope.

## Build, Test, and Development Commands

- `composer install` installs PHP dependencies.
- `npm install` installs frontend dependencies.
- `php artisan serve` starts the Laravel development server.
- `npm run dev` runs Vite with hot reload; `npm run build` creates production assets.
- `php artisan reverb:start` runs the WebSocket server that pushes live draft updates. Run it alongside `php artisan serve` and `npm run dev`. Without it the picking page still works by polling.
- `php artisan test` runs the PHPUnit test suite. Use `php artisan test --filter TestName` while iterating.
- `php artisan migrate` applies local database migrations. Copy `.env.example` to `.env` and configure local credentials before running it.

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF endings, final newlines, and four-space indentation for PHP (two spaces for YAML). Follow Laravel conventions: PascalCase classes (`DraftController`), singular PascalCase models (`Team`), camelCase methods and variables, and snake_case database columns. Name migrations descriptively, for example `2026_09_01_120000_add_status_to_drafts_table.php`.

Keep controllers thin: place validation in Form Requests and persistence rules on models or focused services. Reuse Blade components and Tailwind utility classes instead of adding one-off global CSS when possible.

## Testing Guidelines

Write PHPUnit tests alongside each behavior change. Use feature tests for routes, middleware, views, and database workflows; reserve unit tests for isolated logic. Use descriptive test methods such as `test_team_owner_can_select_a_draft`. Run `php artisan test` before opening a pull request; use factories and migrations rather than relying on local data.

## Commit & Pull Request Guidelines

Use short, imperative commit subjects, such as `Add draft selection validation` or `Fix team token lookup`. Keep each commit focused. Pull requests should explain the behavior change, note migrations or configuration changes, link the relevant issue when available, and include screenshots for visible UI changes. Confirm tests and production asset builds pass before requesting review.

## Security & Configuration

Never commit `.env`, application keys, credentials, or tokens. Add new settings to `.env.example` with safe placeholders and document any required migration or deployment step in the pull request.

Reverb needs `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`; `php artisan reverb:install` generates them into your local `.env`. In production, restrict `allowed_origins` in `config/reverb.php` (it is `*` by default) and serve Reverb over TLS.

Automated tests run against in-memory SQLite (see `phpunit.xml`). Never point scripts that write data at the database in `.env`.
