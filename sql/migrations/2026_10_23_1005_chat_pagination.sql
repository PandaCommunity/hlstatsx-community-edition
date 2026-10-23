-- Requires legacy updater 82 and 2026_10_23_1000_query_indexes.sql. Apply once.
-- Back up and stop writers/web access first: MyISAM ALTER rebuilds/locks the table.
-- Keep updater81's idx_player_time; this key serves the global per-server
-- date/id candidate prefixes and covers server, delay and player-existence checks.
-- It does not index short-message LIKE predicates or remove FULLTEXT sort costs.
ALTER TABLE `hlstats_Events_Chat`
  ADD INDEX `idx_chat_server_time` (`serverId`,`eventTime`,`id`,`playerId`),
  DROP INDEX `serverId`;

-- Rollback during maintenance: restore KEY serverId(serverId), drop
-- idx_chat_server_time, then remove this ledger row. PHP can run with either key.
-- Record success only after the ALTER; never apply using --force.
INSERT INTO `hlstats_SchemaMigrations` (`filename`) VALUES
('2026_10_23_1005_chat_pagination.sql');
