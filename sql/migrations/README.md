# Database upgrades

`sql/install.sql` is **only for an empty database**. Never import it over an
existing installation. It includes the current schema at **dbversion 91**.

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
| 90 | Add `hlstats_Players.avatarUrl` / `avatarUpdated` to cache Steam avatars for 24 hours |
| 91 | Killer/headshot/time frag index for event history and profile totals; award-leading daily award index |

Before upgrading, back up and test on a copy of your database. Pause the daemon,
awards/resolver jobs and web traffic during the upgrade. ALTER statements can
rebuild and lock large tables; allow enough disk space and request time. The
updater needs SELECT, UPDATE and ALTER privileges on the dedicated HLstatsX
database. Run it once, check the output and confirm `dbversion` is 91, then remove
the deployed `web/updater/` directory as directed by the updater.

These are ordinary, one-time numbered updates. Each records its version after
its statements succeed. Already-applied indexes, partial updates and earlier
unreleased db-fixes experiments are not supported starting states. SQL errors
are not suppressed; DDL may already have committed when an error occurs. Restore
the backup or reconcile the failure manually, rather than blindly rerunning or
changing `dbversion`. There is no automatic rollback or recovery update.

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

### Event history, profile totals and daily award indexes (update 91)

Update 91 adds two secondary indexes and changes no data:

```sql
ALTER TABLE hlstats_Events_Frags
    ADD INDEX killer_headshot_time (killerId, headshot, eventTime, id);
ALTER TABLE hlstats_Players_Awards
    ADD INDEX award_time_player (awardId, awardTime, playerId);
```

The Frags key serves the two kill branches of a player's event history and covers
the profile's retained kill/headshot totals. Oldest-first history reads only as
many rows as the page needs. The default newest-first order breaks timestamp ties
by ascending event ID, which no single-direction key matches, so that page still
sorts the player's kills or headshots, but now from a covering index instead of
merging the `killerId` and `headshot` keys. A descending `eventTime` key part was
not used: MySQL 8.x rejects descending key parts on MyISAM, the default engine in
`install.sql`. The awards key lets daily award details read only that award's rows
instead of scanning the whole table.

Queries return the same set of rows with or without these keys. Where a page's
sort columns can tie (for example two players with the same award count), tied
rows may come back in a different order, which can also move them across a page
boundary; that order already depended on the query plan.

**Locking.** On InnoDB, ADD INDEX builds online (`ALGORITHM=INPLACE`,
`LOCK=NONE`): other connections keep reading and writing the table while the
index builds. Each ALTER needs a short exclusive metadata lock at its start and
end. It waits behind any open transaction or long query on that table, and new
queries on the table queue behind it while it waits. Writes made during the build
are kept in the online alter log (`innodb_online_alter_log_max_size`, default
128 MiB); if that fills up, the ALTER fails and no index is added. On **MyISAM**
the ALTER copies the whole table and rebuilds all its indexes: reads continue,
writes wait until it finishes.

The Database Updater path takes the site down anyway: while `web/updater/`
exists, every page shows the updater notice, and this guide asks you to pause the
daemon. Only the manual route below keeps the site up, and only on InnoDB.

**Disk space and time.** Measured on a 2-vCPU orb with MariaDB 10.11.14, a 1 GiB
buffer pool and 2,000,000-row synthetic InnoDB tables: the Frags key took about
4.0 s to build and 43.6 MiB; the awards key about 2.9 s and 42.6 MiB, or roughly
2 s and 22 MiB per million rows each. Treat that as a lower bound; a cold buffer
pool, slower disks and concurrent load make it slower, so rehearse on a copy of
production. On InnoDB, allow free space for the new index plus temporary sort
files of a similar size in `innodb_tmpdir` (or `tmpdir`, often `/tmp`). On
**MyISAM**, allow free space for a full copy of the table and its indexes.
During the local online build a concurrent INSERT/SELECT probe kept running; its
slowest single statement took 159 ms.

**Replication.** A replica applies each ALTER only after it has finished on the
primary, and on MariaDB 10.11 its SQL thread is busy for the whole build, so
expect replication lag of about the build time per ALTER.

**Write cost.** Every inserted frag and award also updates the new key. In a
local benchmark of 1,000-row batches, inserts took 32% longer for Frags and 18%
longer for awards. Single-row daemon inserts are dominated by their round trip,
but check ingestion throughput after the upgrade.

**Running it.** Use the Database Updater as usual. Alternatively, on InnoDB and
**only when `dbversion` is already 90** (`SELECT value FROM hlstats_Options WHERE
keyname = 'dbversion'`), run the statements yourself before deploying
`web/updater/`. The explicit options make each ALTER give up after waiting 10 s
for the metadata lock, and fail at once instead of copying the table:

```sql
SET SESSION lock_wait_timeout = 10;
ALTER TABLE hlstats_Events_Frags
    ADD INDEX killer_headshot_time (killerId, headshot, eventTime, id),
    ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE hlstats_Players_Awards
    ADD INDEX award_time_player (awardId, awardTime, playerId),
    ALGORITHM=INPLACE, LOCK=NONE;
UPDATE hlstats_Options SET value = '91' WHERE keyname = 'dbversion';
```

A timeout while taking the lock at the end of the build discards the finished
build; retry when the table is quiet. Set `dbversion` only after both indexes
exist. On MyISAM, `LOCK=NONE` is rejected; use the updater with ingestion paused.

**Checking it.** `SHOW INDEX FROM hlstats_Events_Frags WHERE Key_name =
'killer_headshot_time'` lists four columns and `SHOW INDEX FROM
hlstats_Players_Awards WHERE Key_name = 'award_time_player'` lists three.

**Partial failure.** If the first ALTER succeeds and the second fails, `dbversion`
stays at 90 and rerunning the updater fails on the existing key name. Run the
missing statement by hand and set `dbversion` to 91, or drop the index that was
created and run the updater again. A PHP or proxy timeout does not stop an ALTER
already running on the server: check `SHOW PROCESSLIST` and `SHOW INDEX` before
doing anything else.

**Rolling back.** Earlier code works with the new indexes in place, so a code
rollback does not require dropping them. To remove them (only if no later update
has been applied):

```sql
ALTER TABLE hlstats_Events_Frags DROP INDEX killer_headshot_time;
ALTER TABLE hlstats_Players_Awards DROP INDEX award_time_player;
UPDATE hlstats_Options SET value = '90' WHERE keyname = 'dbversion';
```

On InnoDB, dropping a secondary index is an in-place metadata change and is quick
(under 0.5 s for both locally); it takes the same short metadata locks. On MyISAM it
copies the table again.

## Adding a migration

- Add the next consecutive `web/updater/<number>.php` file, guarded by
  `IN_UPDATER`, using the existing `$db` connection and direct SQL.
- Use MySQL 5.7+ / MariaDB-compatible SQL, not MariaDB-only `ADD INDEX IF NOT EXISTS`.
  Do not suppress SQL errors or modify updates already released to operators.
- Update `hlstats_Options.dbversion` at the end, after all statements succeed.
- Make the equivalent change in `sql/install.sql` and advance its `@DBVERSION`.
  Verify both a fresh import and an upgraded populated database.
- Benchmark indexes against their consumers and account for ingestion/disk cost.
