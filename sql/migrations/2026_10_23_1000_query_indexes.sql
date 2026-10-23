-- Requires the legacy web updater through dbversion 82. Apply once; see README.md.
-- MyISAM ALTERs rebuild/lock tables and commit independently. Stop writers first.
CREATE TABLE IF NOT EXISTS `hlstats_SchemaMigrations` (
  `filename` varchar(191) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Player event history currently scans these entire tables for each player.
ALTER TABLE `hlstats_Events_Disconnects` ADD INDEX `playerId` (`playerId`);
ALTER TABLE `hlstats_Events_Teamkills` ADD INDEX `victimId` (`victimId`);

-- Profile award counts and award detail joins have no player-leading key.
ALTER TABLE `hlstats_Players_Awards`
  ADD INDEX `player_award_game` (`playerId`,`awardId`,`game`);

-- Per-game rebuilding/profile lookup, and ribbon achievement counts respectively.
-- Nonunique: do not change the treatment of existing duplicate ribbon records.
ALTER TABLE `hlstats_Players_Ribbons`
  ADD INDEX `game_player_ribbon` (`game`,`playerId`,`ribbonId`),
  ADD INDEX `ribbon_player` (`ribbonId`,`playerId`);

-- Replace redundant single-column keys, retaining the time-leading retention keys.
ALTER TABLE `hlstats_Players_History`
  ADD INDEX `player_time` (`playerId`,`eventTime`), DROP INDEX `playerId`;
ALTER TABLE `hlstats_server_load`
  ADD INDEX `server_timestamp` (`server_id`,`timestamp`), DROP INDEX `server_id`;

-- Record success only after every ALTER. Do not use --force when applying this file.
INSERT INTO `hlstats_SchemaMigrations` (`filename`) VALUES
('2026_10_23_1000_query_indexes.sql');
