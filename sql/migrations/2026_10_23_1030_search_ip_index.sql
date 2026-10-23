-- Requires dbversion 82 and 2026_10_23_1000_query_indexes.sql (ledger creation).
-- Apply once with writers paused; see README.md. MyISAM ALTER rebuilds/locks the
-- connection-history table. Budget disk space and a measured maintenance window.
-- Keep the player-leading key: player history lookups cannot use this left prefix.
ALTER TABLE `hlstats_Events_Connects`
  ADD INDEX `ipAddress_playerId` (`ipAddress`,`playerId`);

-- Record completion only after successful DDL; never apply with --force.
INSERT INTO `hlstats_SchemaMigrations` (`filename`) VALUES
('2026_10_23_1030_search_ip_index.sql');

-- PHP rollback does not require dropping the key. To reverse the schema change
-- during maintenance, DROP INDEX ipAddress_playerId ON hlstats_Events_Connects,
-- then delete only this migration's ledger row after verifying the reversal.
