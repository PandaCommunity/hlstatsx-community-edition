# HLstatsX Community Edition — agent notes

## Maintenance scope

- Most work here is bug fixes and adapting the codebase to newer PHP versions.
- Target PHP 8.5+ only. Do not add compatibility branches for older PHP versions.
- Keep fixes focused; avoid unrelated refactoring or expanding test infrastructure.

## Verification

- Verify each change manually against the affected behavior, including relevant edge cases, and report what you checked.
- Temporary scripts or checks are fine when useful; remove them afterward. Do not add permanent tests (including under `tests/`) unless explicitly requested.
- Do not maintain a growing test-command list here or run repository-wide syntax/compatibility scans by default. Use targeted checks when they help validate the specific change.

## Orb development environment

- `.agents/setup` provisions PHP 8.5, MariaDB, required dependencies, and demo data. See that script for setup details rather than duplicating them here.
- Start the frontend with `amp orb services ensure` and use the returned portal URL. Caddy serves `web/` through PHP-FPM; edits apply without a restart.
- The development database is `hlstatsx`; the seeded web admin login is `admin` / `123456`. Database settings use `HLSTATS_DB_ADDR`, `HLSTATS_DB_USER`, `HLSTATS_DB_PASS`, and `HLSTATS_DB_NAME` (see `web/config.php`).
- Run frontend PHP from `web/`: include and asset paths depend on the working directory.
- PHP errors: `/var/log/php8.5-fpm.log`. Web server logs: `amp orb service logs web`.
