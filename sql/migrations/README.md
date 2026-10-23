# Database upgrades

`sql/install.sql` is **only for an empty database**. Never import it over an
existing installation. It includes current indexes and records migrations already
incorporated in the fresh schema.

## Existing installations

There are two upgrade generations; they must not be mixed:

1. **Legacy:** `web/pages/updater.php` runs consecutive `web/updater/<number>.php`
   files using `hlstats_Options.dbversion`. Follow its existing upgrade procedure
   through **82** first. Older pre-1.6.2 installations require the historical SQL
   upgrade files as directed by that updater. The `2019_*.sql` files here are
   historical patches, not part of the new migration sequence; do not glob-import
   this directory or assume those patches are unapplied.
2. **Post-82:** apply the pending files listed below manually, in order. The
   `hlstats_SchemaMigrations` ledger tracks their full filenames independently of
   `dbversion`; do not increment `dbversion` for these SQL migrations.

| Order | Migration | Effect |
| --- | --- | --- |
| 1 | `2026_10_23_1000_query_indexes.sql` | Event/profile lookup and graph time-order indexes |
| 2 | `2026_10_23_1030_search_ip_index.sql` | Covering IP-prefix/player lookup for connection searches and resolver |

Before each deployment:

- Back up the database and verify restoration. Test the upgrade on a recent copy,
  including any locally customized indexes. Check free disk space for table rebuilds.
- Confirm `SELECT value FROM hlstats_Options WHERE keyname = 'dbversion';` is 82.
- Inspect `SHOW TABLES LIKE 'hlstats_SchemaMigrations';`. If present, run
  `SELECT filename, applied_at FROM hlstats_SchemaMigrations ORDER BY filename;`.
  **Skip any file already recorded.** A fresh install records all included files.
- Pause the daemon, awards/resolver jobs, and web access for the migration window.
  Most application tables are MyISAM: ALTER can copy the entire table and block
  readers/writers. Index creation is not a zero-downtime operation. Measure the
  maintenance window on your copy; do not manually trigger a production upgrade
  without the operator's approval.
- From the repository root, execute **each pending file separately**, using your
  MySQL/MariaDB client and credentials (socket/option-file authentication is also
  fine). For example, substituting your own database/user:

  ```sh
  mysql --user=hlstats --password --database=hlstatsx --show-warnings \
    < sql/migrations/2026_10_23_1000_query_indexes.sql
  ```

  Do **not** use `--force`, a wildcard, or a client configured to continue after
  errors. Inspect the exit status/output and ledger after each file. The final
  INSERT records completion only when all earlier statements succeeded.
- Check affected pages and ingestion before reopening access. Compare query plans
  and results on representative data; small seed data cannot predict performance.

### Failure and rollback

DDL implicitly commits. A transaction cannot roll back a partly applied file,
especially on MyISAM. If a statement fails, keep writers paused and inspect
`SHOW CREATE TABLE` / `SHOW INDEX` for each affected table. Do not rerun the whole
file blindly: completed ADD/DROP operations will fail on replay. Reconcile custom
indexes, execute only the remaining statements in order, and run the final ledger
INSERT only after every intended change is verified. Preserve the original file;
record any recovery commands in the deployment log.

For the index-only migration, application SQL is unchanged; rolling back PHP does
not require removing the indexes. If the schema itself must be restored, use the
verified backup or explicitly reverse the ALTERs during maintenance (restore the
two dropped single-column keys as well as removing the added keys), then remove
that migration's ledger row. There is no automatic down migration.

## Adding a migration

- Use a unique UTC `YYYY_MM_DD_HHMM_<description>.sql` filename. Coordinate parallel
  branches targeting `db-fixes`; rebase and append to the table above in dependency
  order before merging. Never modify a migration already applied by operators.
- Use MySQL 5.7+ / MariaDB-compatible SQL, not MariaDB-only `ADD INDEX IF NOT EXISTS`.
  Include prerequisites and operational/rollback notes. Do not change historical
  updater numbers or install data merely to record a new SQL migration.
- End with an INSERT of the exact filename into `hlstats_SchemaMigrations`, after
  all changes succeed. Do not use `INSERT IGNORE` to conceal duplicate application.
  The ledger table definition is in the first migration and `install.sql`.
- Make the same schema change in `sql/install.sql` and append the filename to its
  ledger seed. Verify both a fresh import and an upgraded populated database.
  Data migrations must likewise leave fresh and upgraded installations equivalent.
- Benchmark indexes against their actual consumers; a composite can replace its
  redundant left-prefix key, but must not remove a time-leading purge key. Account
  for ingestion cost and disk space. Preserve sort, tie, visibility, retention and
  count semantics unless the issue explicitly requires a behavior correction.
