# FoodOnTheGo — backend (Laravel API)

The FoodOnTheGo API (`/api/v1`), queue workers, scheduler and realtime server. PHP 8.3, Laravel 13, PostgreSQL + PostGIS,
Redis, Laravel Reverb.

- Setup, run and test: the root [README](../README.md) and [docs/backend/README.md](../docs/backend/README.md) (conventions,
  every domain, commands).
- API description: [openapi/openapi.json](openapi/openapi.json) (421 operations; six are development-only routes that
  exist only where fake providers are allowed).
- Configuration: `.env.example` (local development values), `.env.production.example` (every key a production
  deployment must decide), [docs/production/environment-matrix.md](../docs/production/environment-matrix.md).
- Production gate: `php artisan security:check` (41 items; exit 1 outside local / testing when one fails).
- Operations: `php artisan ops:reliability --alerts`, runbooks in [docs/reliability/runbooks.md](../docs/reliability/runbooks.md),
  launch / rollback in [docs/production/](../docs/production/).

```bash
composer install
php artisan migrate
php artisan db:seed
php artisan test        # real PostgreSQL + PostGIS + Redis (foodonthego_test); never two runs at once
```
