# Database upgrades

`sql/install.sql` is **only for an empty database**. Never import it over an
existing installation. It includes the current schema at **dbversion 89**.

## Existing installations

Start from a **working dbversion-82 installation**. `dbversion` is the schema
update number stored in `hlstats_Options`, not the MySQL/MariaDB version or the
application release number. Older installations must first reach a working 82
using their historical upgrade tools; changing the version label is not enough.

Use the existing **HLX:CE Database Updater** (`hlstats.php?mode=updater`). It runs
consecutive `web/updater/<number>.php` files after the recorded version. There is
no separate SQL import step, migration ledger or shared migration helper.

| Database version | Effect |
| --- | --- |
| 83 | Event/profile lookup and graph time-order indexes |
| 84 | Global chat server/date/id index; retain the compact server key for totals |
| 85 | Replace Frags map/weapon prefixes with covering dimension indexes |
| 86 | Replace `(game,hideranking)` with `(game,hideranking,skill,kills,deaths)` for player ranking |
| 87 | IP-prefix/player index for connection searches and resolver |
| 88 | Time-leading event indexes for batched retention deletes |
| 89 | Convert database/table defaults and text columns to `utf8mb4_general_ci` |

Before upgrading, back up and test on a copy of your database. Pause the daemon,
awards/resolver jobs and web traffic during the upgrade. ALTER statements can
rebuild and lock large tables; allow enough disk space and request time. The
updater needs SELECT, UPDATE and ALTER privileges on the dedicated HLstatsX
database. Run it once, check the output and confirm `dbversion` is 89, then remove
the deployed `web/updater/` directory as directed by the updater.

These are ordinary, one-time numbered updates. Each records its version after
its statements succeed. Already-applied indexes, partial updates and earlier
unreleased db-fixes experiments are not supported starting states. SQL errors
are not suppressed; DDL may already have committed when an error occurs. Restore
the backup or reconcile the failure manually, rather than blindly rerunning or
changing `dbversion`. There is no automatic rollback or recovery update 90.

### Database compatibility

The compatibility baseline is **MySQL 5.7** or **MariaDB 10.6**, using the latest
patch release for the chosen line. Prefer **MySQL 8.4 LTS** or **MariaDB 10.11 LTS**
for maintained deployments. Legacy compatibility does not imply vendor security
support. Keep default SQL modes enabled, including MySQL's ONLY_FULL_GROUP_BY.

Compatibility work exercised actual MySQL **5.7.44**, **8.0.44**, **8.4.8** and
MariaDB **10.6.24**, **10.11.19** with PHP 8.5: populated upgrades, fresh installs,
frontend/in-game queries and the Perl awards/ribbon job. The simplified numbered
updater was subsequently rechecked on MariaDB 10.11; the full five-server matrix
was run before removing the extra migration checks, not repeated afterward.
Earlier server lines, every custom schema and production-scale migration times
have not been qualified. MariaDB testing is not a substitute for MySQL testing.

Normal operation needs SELECT/INSERT/UPDATE/DELETE and CREATE TEMPORARY TABLES.
Ribbon publication also needs CREATE, DROP and ALTER. Legacy role pages use
`DROP TABLE IF EXISTS` for their scratch tables, so need DROP and the names
`hlstats_Frags_as` / `hlstats_Frags_as_res` reserved for that purpose.

### Collation conversion (update 89)

Use **utf8mb4 / utf8mb4_general_ci** in the schema, connection configuration and
temporary tables. Update an existing local `config.php` accordingly. Changing
only the database default does not convert existing text columns; mixed stored
and temporary-table collations can cause “illegal mix of collations” join errors.

Update 89 converts base tables whose default or any text column differs, then
changes the database default. Already-matching tables are not rebuilt. This also
converts custom base tables, so use a dedicated database. Views, binary columns
and storage engines are not changed.

General-ci preserves the reported distinct clan tags `[ß]` / `[SS]` and
`[TM]` / `[™]` that Unicode-ci collapsed. It is available on both supported
database products, unlike MySQL-specific 0900 collations. A binary/case-sensitive
collation would change application matching semantics, so is not a drop-in fix.

Conversion is not guaranteed conflict-free for every source collation: for
example, Unicode-ci can distinguish `s` / `ß`, which general-ci considers equal.
Unique-key conflicts, old InnoDB key-size limits, custom foreign keys and invalid
historical dates can prevent ALTER. The updater leaves these to the database's
normal errors; it does not rename/delete data or automatically change row formats
or constraints. Rehearse on your own data. Conversion trusts the declared source
charset and does not repair mislabelled text or mojibake.

### Retention job

Complete update **88** before using the batched pruning job;
without the time-leading keys each batch can scan and sort the remaining table.
The job freezes its cutoffs once, deletes oldest eligible timestamps in batches
of at most 1,000 rows, and yields 10 ms between full batches. It drains the backlog;
there is no whole-run time limit. Row limits reduce lock duration, not guarantee a
wall-clock bound on slow disks or busy servers. Interrupted runs can be restarted;
completed MyISAM deletes are not rolled back. Each restart computes new cutoffs.
Late-arriving historical events remain eligible regardless of their auto-increment
ID; NULL timestamps and exact cutoff timestamps are retained.

When pruning and awards run together, the event/history cutoff is capped at the
requested award period's start (`--date` minus `--numdays`). This also retains the
boundary DATE history row. Prune-only runs retain the original datetime comparison
for DATE history: a cutoff at noon expires that day's midnight history row.
Trend's two-day and server load's one-year retention policies/keys are unchanged.
Global OPTIMIZE behavior is unchanged and can still lock tables independently.

### Retained map/weapon dimensions (PAN-18)

Update 85 replaces two indexes, rather than adding redundant candidates.
The full dimension, killer, headshot and server keys cover request-local leaderboard
aggregation and server-game totals. They do not introduce lifetime counters, cached
summaries, expiry jobs, or per-frag application writes. Player/server lookup keys
remain unchanged. Leaderboards still aggregate and sort the matching retained
events before LIMIT; very popular dimensions are not constant-time lookups.

A warm-cache MariaDB 10.11/MyISAM benchmark used 600,000 synthetic retained frags,
20,000 players, 40 servers and four games, including popular dimensions (~150,000
rows), selective dimensions (~30,000), and colliding map/weapon prefixes. Median
of five ANALYZE executions: popular map leaderboard 190 → 55 ms, weapon 204 → 65 ms
with request-local grouping before the player join; server-game totals 265 → 135 ms
and 303 → 128 ms. Full single-column keys alone did not improve popular queries.
Three-column covering keys improved leaderboards but did not cover server totals,
which is why the chosen keys also include serverId.

Total Frags index space grew from 31.93 to 38.93 MiB on that fixture. Median append
time for 20 batches of 1,000 rows grew from 907 to 1,146 ms (~26%); this is a short
synthetic batch benchmark, not a production throughput guarantee. Benchmark your
own data and ingestion workload before adopting the migration. Prefix-heavy
synthetic data exaggerates some selectivity gains; no concurrent-load claim is made.

## Adding a migration

- Add the next consecutive `web/updater/<number>.php` file, guarded by
  `IN_UPDATER`, using the existing `$db` connection and direct SQL.
- Use MySQL 5.7+ / MariaDB-compatible SQL, not MariaDB-only `ADD INDEX IF NOT EXISTS`.
  Do not suppress SQL errors or modify updates already released to operators.
- Update `hlstats_Options.dbversion` at the end, after all statements succeed.
- Make the equivalent change in `sql/install.sql` and advance its `@DBVERSION`.
  Verify both a fresh import and an upgraded populated database.
- Benchmark indexes against their consumers and account for ingestion/disk cost.
