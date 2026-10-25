# Database upgrades

`sql/install.sql` is **only for an empty database**. Never import it over an
existing installation. It includes the current schema and sets
`hlstats_Options.dbversion` to **89**; no additional migrations are needed.

## Existing installations

Use the existing **HLX:CE Database Updater** (`hlstats.php?mode=updater`).
It runs consecutive `web/updater/<number>.php` files after the database's recorded
`dbversion`, including updates 83–89 below. There is no separate SQL import step.
Older installations must follow any historical prerequisites reported by the
updater. The `2019_*.sql` files in this directory are historical patches, not a
sequence to glob-import.

| Database version | Update | Effect |
| --- | --- | --- |
| 83 | `web/updater/83.php` | Event/profile lookup and graph time-order indexes |
| 84 | `web/updater/84.php` | Cover global chat's per-server date/id candidate prefixes; retain the compact server key for fast legacy totals (which may include orphaned-player messages) |
| 85 | `web/updater/85.php` | Replace Frags map/weapon prefixes with covering dimension indexes |
| 86 | `web/updater/86.php` | Cover exact player ranks and population counts; replace `(game,hideranking)` with `(game,hideranking,skill,kills,deaths)` |
| 87 | `web/updater/87.php` | Covering IP-prefix/player lookup for connection searches and resolver |
| 88 | `web/updater/88.php` | Time-leading event indexes for bounded retention deletes |
| 89 | `web/updater/89.php` | Normalize the database default, base-table defaults and text columns to `utf8mb4_unicode_ci` |

Before each deployment:

- Back up the database and verify restoration. Test the upgrade on a recent copy,
  including any locally customized indexes. Check free disk space for table rebuilds.
- Pause the daemon, awards/resolver jobs, and web access for the migration window.
  Most application tables are MyISAM: ALTER can copy the entire table and block
  readers/writers. Index creation is not a zero-downtime operation. Measure the
  maintenance window on your copy; do not manually trigger a production upgrade
  without the operator's approval.
- Deploy the web files including `web/updater/`, keeping the updater accessible
  only to the operator. Ensure the web database account has ALTER privileges and
  that PHP/proxy request timeouts allow the measured upgrade duration.
- Open the Database Updater once and inspect its output. Updates 83–89 advance
  `dbversion` only after their statements succeed. Do not run concurrent upgrades.
- Confirm `SELECT value FROM hlstats_Options WHERE keyname = 'dbversion';` returns
  **89**. Remove the deployed `web/updater/` folder as directed by the updater,
  then check affected pages and ingestion before reopening access. Compare query
  plans and results on representative data; small seed data cannot predict performance.

### Databases that used the earlier db-fixes SQL files

Updates 83–88 recognize each corresponding filename in an existing
`hlstats_SchemaMigrations` table and skip that update's already-completed DDL.
Missing updates run normally, even if the recorded files are not a consecutive
prefix. The updater then advances `dbversion` through 89. It does not create or
write the old ledger; an existing ledger is left intact as historical information.
Future updates use only numbered updater files and `dbversion`.

The six `2026_10_23_*.sql` files have been replaced by updates 83–88; do not apply
copies from an older checkout. If an old manual file failed partway through and
has no completion row, reconcile its partial DDL before running the updater.

### Failure and rollback

DDL implicitly commits. A transaction cannot roll back a partly applied update,
especially on MyISAM. If a statement fails, keep writers paused and inspect
`SHOW CREATE TABLE` / `SHOW INDEX` for each affected table. The failing update's
version is not recorded, but earlier DDL may already be committed. Do not rerun
the updater blindly: completed ADD/DROP operations will fail on replay. Reconcile
custom indexes, execute only the failing update's remaining statements in order,
and execute its final `dbversion` UPDATE only after every intended change is
verified. Then reopen the updater to continue with subsequent versions. Preserve
the original updater files and record recovery commands in the deployment log.

For update 83, application SQL is unchanged; rolling back PHP does
not require removing the indexes. If the schema itself must be restored, use the
verified backup or explicitly reverse the ALTERs during maintenance (restore the
two dropped single-column keys as well as removing the added keys). There is no
automatic down migration. Do not lower `dbversion` unless all subsequent schema
changes are also reversed. If reverting an old manually applied change, reconcile
its historical ledger row too, so a future upgrade does not incorrectly skip it.

For update 86, restore `game_hideranking(game,hideranking)`
and drop `game_skill_rank` if reversing its schema change. It does not change
ranking semantics or require simultaneous PHP/Perl deployment. The wider key
adds storage and ingestion cost; benchmark on your copy. It covers exact rank
and population scans, not computed K:D/accuracy/headshot ordering. Deep offsets
and period aggregation still require work proportional to the qualifying data;
arbitrary page navigation and exact totals are retained.

### Collation normalization (update 89)

Older installs can retain `utf8mb4_general_ci` or other collations even though
the application creates temporary tables using `utf8mb4_unicode_ci`. Changing
only the database default or PHP connection settings does not convert stored
columns, and joins can fail with error 1267 (illegal mix of collations).

Update 89 explicitly selects `utf8mb4` / `utf8mb4_unicode_ci`, matching
`web/config.php` and fresh installs rather than version-dependent server defaults.
Keep `DB_CHARSET` and `DB_COLLATE` set to those values. It changes the selected
database's default and converts every base table whose default or any text column
differs, including custom tables and an existing historical migration ledger.
Use a dedicated HLstatsX database. Views are not altered; binary columns are not
text columns and retain their binary semantics. Already-normalized tables are
skipped, including on retry after a partial conversion.

The updater account needs ALTER permission on the database and all affected
tables. Rehearse with a backup: conversions can rebuild large event tables,
change sorting/comparison results, encounter duplicate unique keys under the new
comparison rules, or exceed index limits when expanding an older charset. MySQL
may widen text types to preserve their character capacity. Do not suppress these
errors or delete conflicting data to force success. Resolve the reported schema
or data conflict before retrying; `dbversion` remains at 88 until all conversions
succeed. Rollback requires the verified backup or the original per-column/table/
database definitions; there is no universal reverse collation.

After upgrading, the following queries should return no rows (run with the
HLstatsX database selected):

```sql
SELECT SCHEMA_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = DATABASE()
  AND DEFAULT_COLLATION_NAME <> 'utf8mb4_unicode_ci';

SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci';

SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL
  AND COLLATION_NAME <> 'utf8mb4_unicode_ci'
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
