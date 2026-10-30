# HLstatsX Community Edition — Panda Community maintenance fork

HLstatsX Community Edition provides real-time player and clan statistics for
Half-Life and Source-engine games. A Perl daemon receives game-server logs,
stores statistics in MySQL/MariaDB, and serves rankings through a PHP frontend.

This is a **maintenance fork** of
[A1mDev/hlstatsx-community-edition](https://github.com/A1mDev/hlstatsx-community-edition),
focused on bug fixes, compatibility and performance rather than new features.

## Changes from upstream

- Upgraded the codebase to support **PHP 8.5**.
- Added database indexes and improved database access performance in general.
- Hardened admin access, added CSRF protection and improved input validation.
- Hid internal error details from public responses by default.
- Fixed graphs, heatmaps, player signatures and trend-image caching.
- Fixed search counts, rankings and in-game pagination.
- Improved awards, ribbon rebuilds, history pruning and hostname resolution.
- Fixed MySQL strict-mode queries and standardized database text collations.
- Player avatars use the Steam Web API with a 24-hour database cache; the Steam
  online status is no longer shown.

## Requirements

- **PHP 8.5+** with MySQLi, PDO MySQL, GD/FreeType, mbstring and cURL.
  Older PHP versions are not maintained.
- **MySQL 5.7+ or MariaDB 10.6+**, on the latest patch release of your chosen line.
  Prefer **MySQL 8.4 LTS or MariaDB 10.11 LTS**; compatibility with older lines
  does not imply vendor security support. Keep default SQL modes enabled,
  including `ONLY_FULL_GROUP_BY`.
- A PHP-capable web server and Perl with DBI/DBD::mysql; the awards job also needs
  Syntax::Keyword::Try.
- A dedicated database using **utf8mb4 / utf8mb4_general_ci**.
- Normal database read/write privileges plus **CREATE TEMPORARY TABLES**.
  Ribbon rebuilds also need CREATE, DROP and ALTER; legacy role reports need DROP.
  The updater needs SELECT, UPDATE and ALTER. See the
  [database guide](sql/migrations/README.md) for details.

## Upgrading an existing installation

Start from a **working database at dbversion 82**. This is the schema version in
`hlstats_Options`, not the application or database-server version. Older
installations must complete their historical upgrades first; do not simply
change the version value.

**Never import `sql/install.sql` over an existing database.**

1. **Back up and rehearse.** Back up the database, deployed files, configuration
   and local customizations, and verify that you can restore them. Rehearse on an
   isolated copy with PHP 8.5+, with live ingestion and scheduled jobs disabled.
   Many tables are MyISAM, so transaction-only backups need not be consistent
   while writers are active.
2. **Pause the installation.** Stop the daemon and scheduled jobs, restrict web
   access, and allow disk space and downtime for table rebuilds. Database updates
   can lock large tables; ensure PHP and proxy timeouts allow them to finish.
3. **Deploy this fork's files.** Use a reviewed revision from the **`dev`** branch.
   Deploy `web/` including `web/updater/`, the matching `scripts/`, and `heatmaps/`
   if used. Preserve custom assets and merge local configuration changes.
4. **Update configuration.** Merge your settings into the current `web/config.php`.
   Set `DB_CHARSET` to `utf8mb4` and `DB_COLLATE` to `utf8mb4_general_ci`; keep
   `DISPLAY_INTERNAL_ERRORS` false and PHP error display disabled in production.
   Retain your daemon's `scripts/hlstats.conf`, check database grants, and keep
   `web/hlstatsimg/progress/` writable by PHP. To show Steam avatars, set
   `STEAM_API_KEY` in `config.php` or the web server's environment; without a
   key, player pages show the default avatar.
5. **Run the Database Updater** at `hlstats.php?mode=updater`. It applies numbered
   updates **83–90**, adding the indexes, converting text collations and adding
   the Steam avatar cache columns. There is
   no separate SQL import or migration-ledger step. Confirm the output succeeds
   and `hlstats_Options.dbversion` is **90**, then **remove the deployed
   `web/updater/` directory** before reopening the site.
6. **Validate and resume.** Check admin login, statistics, search, chat, in-game
   pages, graphs and signatures. Verify awards/ribbons, pruning and the resolver
   on the isolated copy before restoring production schedules. Restart ingestion,
   confirm new events arrive, and monitor private logs and database load. Do not
   overlap ribbon rebuilds with administrative resets or schema maintenance.

**If an update fails:** keep writers paused and restore the backup or reconcile
the partial state manually. DDL commits independently, so blindly rerunning an
index update or changing `dbversion` is unsafe. Reverting files alone does not
undo schema changes; there is no automatic rollback.

**Collation conversion:** update 89 also converts custom base tables in the
selected database. It can change comparisons or encounter unique-key conflicts,
index-size limits and invalid historical data; it does not repair mislabelled
text. Test on your own data before upgrading.

See the [database upgrade guide](sql/migrations/README.md) for migration details,
recovery notes and unsupported experimental upgrade states. Do not import the
historical SQL files in `sql/migrations/` as part of this switch.

## Fresh installations

Import `sql/install.sql` **only into an empty, dedicated database**. It includes
the current schema at **dbversion 90**. Configure the frontend, daemon and
game-server log forwarding, supply the required grants, and remove the deployed
updater directory before normal use.

Do not import `sql/dev-seed.sql` into production: it contains development data
and a known demonstration admin login.

## Credits and license

Built on HLstatsX Community Edition, ELstatsNEO, HLstatsX and HLstats. See
[CREATORS](CREATORS), [LICENSE](LICENSE) and the source-file notices for credits
and GNU GPL licensing terms.
