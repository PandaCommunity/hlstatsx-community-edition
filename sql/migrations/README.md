# Database upgrades

`sql/install.sql` is **only for an empty database**. Never import it over an
existing installation. It includes the current schema and sets
`hlstats_Options.dbversion` to **89**; no additional migrations are needed.

## Existing installations

Use the existing **HLX:CE Database Updater** (`hlstats.php?mode=updater`).
It runs consecutive `web/updater/<number>.php` files after the database's recorded
`dbversion`, including updates 83–89 below. There is no separate SQL import step.
The supported starting point is a **working database at version 82** (or a later
completed update in 83–88). `dbversion` is the schema update number stored in
`hlstats_Options`, not the MySQL/MariaDB version or the application release number.
The updater refuses earlier starting versions before changing the schema.

Stage older deployments to a verified version-82 installation separately, on a
backup, using their appropriate historical release/tools. Simply changing the
version label is not an upgrade. Historical numbered files are retained as
reference, not a supported PHP-8.5 upgrade route: some old files use obsolete
database APIs, and the numbered sequence alone does not establish every old
schema prerequisite. In particular, `2019_02_22_0000.sql` adds Players `mmrank`
(a matchmaking-rank field still selected by player profiles), and
`2019_04_28_0007.sql` repairs zero-date defaults. Existing zero/partial-zero date
rows may also need an operator-approved repair before DDL under strict SQL modes.
These files are historical patches, **not a sequence to glob-import**. The fork
does not invent rank values, delete events, or rewrite invalid dates for you.

### Database compatibility

The compatibility baseline is **MySQL 5.7** or **MariaDB 10.6**, using the latest
patch release available for the chosen line. Prefer **MySQL 8.4 LTS** or
**MariaDB 10.11 LTS** for maintained deployments. Legacy compatibility is not a
recommendation to run an unmaintained server or a promise of vendor security fixes.

Targeted checks used PHP 8.5 and these actual servers, not a MariaDB proxy for MySQL:

| Server exercised | Upgrade/fresh install | Runtime checks |
| --- | --- | --- |
| MySQL 5.7.44 | 82–88 → 89, fresh 89, retry/no-op | Default strict modes, including ONLY_FULL_GROUP_BY |
| MySQL 8.0.44 | Same | Same |
| MySQL 8.4.8 | Same | Same |
| MariaDB 10.6.24 | Same | Default strict modes |
| MariaDB 10.11.19 | Same | Default strict modes |

For every server, a populated fixture built from `origin/dev`'s install schema
plus the actual updates 81/82 upgraded successfully. All 56 original table data
hashes were preserved except the intended version label; fresh/upgraded schema
definitions matched. Interrupted index creation and collation conversion resumed
successfully. Conflict checks covered unique/prefix keys, NULL composite keys,
custom indexes, textual foreign keys, zero dates and COMPACT InnoDB key limits.
Runtime checks exercised 23 HTTP scenarios per server with content/DOM assertions,
including asymmetric role/action totals and separate award identities sharing a
display label. Another 25 broader frontend/in-game smoke requests per server
checked SQL/fatal errors. The actual Perl awards/ribbon job produced independently
checked weapon winners and ribbon ownership. MySQL SQL modes were not weakened.

Normal runtime needs the usual SELECT/INSERT/UPDATE/DELETE grants, plus
CREATE TEMPORARY TABLES for request-local and awards summaries. Ribbon publication
also needs CREATE, DROP and ALTER on its live/build/old tables. Legacy role pages
additionally require DROP because their existing cleanup uses `DROP TABLE IF
EXISTS`, not `DROP TEMPORARY TABLE`: reserve `hlstats_Frags_as` / `_res` for those
pages, never permanent custom tables. The updater's advisory lock does not
coordinate with jobs; pause them for schema work.

This is targeted compatibility evidence, not exhaustive game/daemon ingestion,
production-scale rebuild, concurrency or performance coverage. Earlier patch
releases, MariaDB before 10.6, MySQL before 5.7, other database products and every
possible custom schema have not been qualified. MariaDB's ONLY_FULL_GROUP_BY
mode is not the same implementation as MySQL's and was not the MariaDB test mode.
Rehearse your own data, grants, SQL modes, row formats and server settings.

| Database version | Update | Effect |
| --- | --- | --- |
| 83 | `web/updater/83.php` | Event/profile lookup and graph time-order indexes |
| 84 | `web/updater/84.php` | Cover global chat's per-server date/id candidate prefixes; retain the compact server key for fast legacy totals (which may include orphaned-player messages) |
| 85 | `web/updater/85.php` | Replace Frags map/weapon prefixes with covering dimension indexes |
| 86 | `web/updater/86.php` | Cover exact player ranks and population counts; replace `(game,hideranking)` with `(game,hideranking,skill,kills,deaths)` |
| 87 | `web/updater/87.php` | Covering IP-prefix/player lookup for connection searches and resolver |
| 88 | `web/updater/88.php` | Time-leading event indexes for bounded retention deletes |
| 89 | `web/updater/89.php` | Normalize the database default, base-table defaults and text columns to `utf8mb4_general_ci` |

Before each deployment:

- Back up the database and verify restoration. Test the upgrade on a recent copy,
  including any locally customized indexes. Check free disk space for table rebuilds.
- Pause the daemon, awards/resolver jobs, and web access for the migration window.
  Most application tables are MyISAM: ALTER can copy the entire table and block
  readers/writers. Index creation is not a zero-downtime operation. Measure the
  maintenance window on your copy; do not manually trigger a production upgrade
  without the operator's approval.
- Deploy the web files including `web/updater/`, keeping the updater accessible
  only to the operator. The updater needs SELECT on **all** base tables in the
  dedicated database, UPDATE on `hlstats_Options`, and ALTER on the database and
  affected tables. Verify any additional engine/custom-DDL grants in rehearsal and
  that PHP/proxy request timeouts allow the measured upgrade duration.
- Open the Database Updater once and inspect its output. Updates 83–89 advance
  `dbversion` only after their statements succeed. A database-scoped advisory lock
  excludes another updater request; it does **not** stop daemon/jobs/web writers.
  The read-only preflight runs before the first ALTER, including when resuming.
- Confirm `SELECT value FROM hlstats_Options WHERE keyname = 'dbversion';` returns
  **89**. Remove the deployed `web/updater/` folder as directed by the updater,
  then check affected pages and ingestion before reopening access. Compare query
  plans and results on representative data; small seed data cannot predict performance.

### Existing, custom and partially applied indexes

Updates 83–88 inspect the actual schema instead of trusting historical ledger
filenames. An already-present required index must have the exact column order,
prefix lengths, nonunique ascending BTREE definition and visibility. Matching
indexes are retained; missing ones are added. An old key is dropped only when its
definition matches the repository's known old key; an already-absent old key is
accepted. An unexpected namesake stops the whole pending upgrade in preflight
instead of overwriting a local customization. Extra keys under other names are
left alone, even if redundant. Index names are compared case-insensitively.

This makes a genuine partial 83–88 upgrade retryable. An existing
`hlstats_SchemaMigrations` ledger remains historical data, not authority to skip
missing DDL. It is neither created nor updated. The obsolete manual
`2026_10_23_*.sql` files must not be applied from another checkout.

Earlier unreleased branch-testing states are **not a separate supported migration
path**, especially databases already labeled 89/90 under the discarded Unicode-ci
policy. No recovery-only update 90 is provided. Restore the appropriate pre-branch
backup or reconcile those experimental states explicitly; do not lower their
version label and hope the normal upgrade reconstructs the original schema/data.

### Failure and rollback

DDL implicitly commits. A transaction cannot roll back a partly applied update,
especially on MyISAM. If a statement fails, keep writers paused and inspect
`SHOW CREATE TABLE` / `SHOW INDEX` for each affected table. The failing update's
version is not recorded, but earlier DDL may already be committed. Fix the reported
cause (for example grants, disk space or a schema/data conflict), then reopen the
updater: completed matching indexes and normalized tables are skipped. Do not
manually advance `dbversion` to bypass a failure. A mismatched custom definition
still requires an explicit operator decision. Preserve the original updater files
and record recovery commands in the deployment log. Preflight is not a DDL
transaction, comprehensive privilege checker or guarantee against later failures.

For update 83, application SQL is unchanged; rolling back PHP does
not require removing the indexes. If the schema itself must be restored, use the
verified backup or explicitly reverse the ALTERs during maintenance (restore the
two dropped single-column keys as well as removing the added keys). There is no
automatic down migration. Do not lower `dbversion` unless all subsequent schema
changes are also reversed. Historical ledger entries do not override actual
index inspection.

For update 86, restore `game_hideranking(game,hideranking)`
and drop `game_skill_rank` if reversing its schema change. It does not change
ranking semantics or require simultaneous PHP/Perl deployment. The wider key
adds storage and ingestion cost; benchmark on your copy. It covers exact rank
and population scans, not computed K:D/accuracy/headshot ordering. Deep offsets
and period aggregation still require work proportional to the qualifying data;
arbitrary page navigation and exact totals are retained.

### Collation normalization (update 89)

Mixed stored and temporary-table collations can make joins fail with error 1267
(illegal mix of collations). Changing only the database default or PHP connection
settings does not convert stored columns.

Update 89 explicitly selects `utf8mb4` / `utf8mb4_general_ci`, matching
`web/config.php` and fresh installs rather than version-dependent server defaults.
Keep `DB_CHARSET` and `DB_COLLATE` set to those values, including in an existing
locally maintained config.php. The conversion changes the selected
database's default and converts every base table whose default or any text column
differs, including custom tables and an existing historical migration ledger.
Use a dedicated HLstatsX database. Views are not altered; binary columns are not
text columns and retain their binary semantics. Already-normalized tables are
skipped, including on retry after a partial conversion.

General-ci preserves distinct clan tags such as `[ß]` / `[SS]` and `[TM]` / `[™]`
that Unicode-ci would collapse, violating the unique `(game,tag)` index. No clans
are deleted, renamed or merged. It also works across the selected MySQL/MariaDB
lines, unlike MySQL-specific 0900 collations. It is a deliberate legacy comparison
policy, not the most linguistically complete Unicode sort. Binary/case-sensitive
collations would change application matching semantics rather than provide a safe
drop-in fix. Keep one explicit policy for schema, connections and temporary tables.

Before **any** pending update's DDL, preflight checks the configured target,
minimum server version, required index columns/definitions, `mmrank`, zero-date
defaults and zero/partial-zero rows in tables that will be altered. For each table
needing conversion it checks textual foreign keys, expression indexes, obvious
COMPACT/REDUNDANT InnoDB text keys exceeding 767 bytes, and potential unique-key
collisions under the target comparison. Collision checks respect prefix lengths
and exclude rows with a NULL unique-key component. They report the table/key and
stop; they do not choose which identity to keep. For example, source Unicode-ci
can distinguish `s` / `ß`, which general-ci does not.

These read-only scans/groupings can be expensive on large event tables and need
their own maintenance-window budget. They are conservative checks for known
blockers, not complete validation of arbitrary generated/check constraints,
partitioning, key/row-size limits, other engine settings or encoding damage.
Conversion trusts each column's declared charset; it does not repair mislabelled
text/mojibake. A custom schema still needs a full rehearsal. No storage engine,
row format, index prefix or user data is automatically changed to evade a blocker.

The database default changes only after all selected tables convert; version 89
is recorded last. Rehearse with a backup: conversions can rebuild large event tables,
change sorting/comparison results, encounter duplicate unique keys under the new
comparison rules, or exceed index limits when expanding an older charset. MySQL
may widen text types to preserve their character capacity. Do not suppress these
errors or delete conflicting data to force success. General-ci is not guaranteed
collision-free for arbitrary source collations or custom data. Resolve any
reported schema or data conflict before retrying; the failing update's version
is not recorded. Rollback requires the verified backup or original per-column/table/
database definitions; there is no universal reverse collation.

After upgrading, the following queries should return no rows (run with the
HLstatsX database selected):

```sql
SELECT SCHEMA_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = DATABASE()
  AND DEFAULT_COLLATION_NAME <> 'utf8mb4_general_ci';

SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_COLLATION <> 'utf8mb4_general_ci';

SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL
  AND COLLATION_NAME <> 'utf8mb4_general_ci'
  AND TABLE_NAME IN (
      SELECT TABLE_NAME FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
  );
```

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
  `IN_UPDATER`, using the existing `$db` connection. Coordinate numbers across
  parallel branches before integration; do not introduce another migration ledger.
  Never modify an update already applied by operators.
- Use MySQL 5.7+ / MariaDB-compatible SQL, not MariaDB-only `ADD INDEX IF NOT EXISTS`.
  Include prerequisites and operational/rollback notes. Do not renumber historical
  updates or suppress SQL errors.
- Update `hlstats_Options.dbversion` at the end, after all statements succeed.
- Make the same schema/data change in `sql/install.sql` and advance its
  `@DBVERSION`. Verify both a fresh import and an upgraded populated database.
  Data migrations must likewise leave fresh and upgraded installations equivalent.
- Benchmark indexes against their actual consumers; a composite can replace its
  redundant left-prefix key, but must not remove a time-leading purge key. Account
  for ingestion cost and disk space. Preserve sort, tie, visibility, retention and
  count semantics unless the issue explicitly requires a behavior correction.
