# HLstatsX Community Edition — agent notes

## Environment

Amp orbs for this repository are prepared by `.agents/setup`:

- PHP 8.5 (`php`) is the default CLI and minimum supported version. Do not add compatibility branches for older PHP versions.
- MariaDB 10.11 runs as the systemd unit `mariadb`, with database `hlstatsx` and development user `hlstats` / `hlstats`. The schema from `sql/install.sql` is imported, and the seeded admin account is `admin` / `123456`.
- `web/config.php` reads `HLSTATS_DB_ADDR`, `HLSTATS_DB_USER`, `HLSTATS_DB_PASS`, and `HLSTATS_DB_NAME` from the environment, falling back to the values in the file.
- `sql/dev-seed.sql` adds a demo TF2 server, three players, live stats, and 24 hours of trend/load history. Setup imports it once after `sql/install.sql`.
- Composer, `phpcs` (with PHPCompatibility), and the Perl modules used by `scripts/hlstats.pl` are installed.

## Running the app

Start the declared development server and read its portal URL:

```
amp orb services ensure
```

Caddy serves `web/` and proxies `.php` requests to PHP-FPM. The FPM pool (`/etc/php/8.5/fpm/pool.d/zz-orb.conf`) runs as the orb user with `chdir` set to `web/`, because the frontend resolves `INCLUDE_PATH`, `PAGE_PATH`, and `IMAGE_PATH` relative to the working directory. It also supplies the `HLSTATS_DB_*` variables and `HLSTATS_SKIP_UPDATER_WARNING=1`.

Caddy listens on `$PORT` (see `.amp/Caddyfile`) and uses `/run/php/php8.5-fpm-orb.sock`. OPcache is enabled with timestamp validation, so edits apply without a restart.

`display_errors` is off for FPM so warnings do not corrupt image endpoints; PHP errors go to `/var/log/php8.5-fpm.log` (`sudo tail -f`). Caddy's access and error logs are in `amp orb service logs web`.

After changing the FPM pool, run `sudo systemctl restart php8.5-fpm`; after changing `.amp/Caddyfile`, run `amp orb service restart web`.

The Perl daemon reads `scripts/hlstats.conf`; pass the development database with its command-line flags (its `--configfile` option is currently ignored by `hlstats.pl`):

```
cd scripts && perl hlstats.pl \
  --db-host=127.0.0.1 --db-username=hlstats --db-password=hlstats --db-name=hlstatsx
```

It runs in the foreground and listens on UDP 27500. A non-fatal warning about the missing `GeoLiteCity/GeoLite2-City.mmdb` is expected because the GeoIP database is not part of the repository.

## Checks

```
# Syntax-check every PHP-bearing file (pChart uses .class files)
find web -type f \( -name '*.php' -o -name '*.class' \) -print0 |
  while IFS= read -r -d '' file; do
    php -l "$file" 2>&1 | grep -v 'No syntax errors detected'
  done

# PHP 8 compatibility scan
phpcs --standard=PHPCompatibility --runtime-set testVersion 8.5 --extensions=php,class,inc -p web
```

Run the bundled chart regression checks with `php tests/pchart.php` (GD and FreeType required).

Run `php tests/database.php` with the `HLSTATS_DB_*` environment variables set for the database regression checks. They issue only read-only queries and require no application schema or data.

Run `php tests/import_bans.php` with the same variables pointing to a disposable database for ban-import regression checks. These require CREATE/DROP TABLE privileges and clean up their uniquely named fixture tables.
