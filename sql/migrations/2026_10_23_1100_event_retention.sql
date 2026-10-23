-- Requires updater 82 and 2026_10_23_1000_query_indexes.sql. Apply once; see README.md.
-- Pause writers and back up first: MyISAM ALTERs copy/lock tables and commit
-- independently. Check disk space and measure the window on a recent copy.
-- Global expiry needs a time-leading key; player/time keys cannot serve it.
ALTER TABLE `hlstats_Events_Admin` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_ChangeName` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_ChangeRole` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_ChangeTeam` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Chat` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Connects` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Disconnects` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Entries` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Frags` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Latency` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_PlayerActions` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_PlayerPlayerActions` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Rcon` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Statsme` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Statsme2` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_StatsmeLatency` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_StatsmeTime` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Suicides` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_TeamBonuses` ADD INDEX `eventTime` (`eventTime`);
ALTER TABLE `hlstats_Events_Teamkills` ADD INDEX `eventTime` (`eventTime`);

-- History, Trend and server_load already have time-leading retention keys.
-- On failure, reconcile completed ALTERs rather than replaying the whole file.
-- To reverse during maintenance, DROP INDEX eventTime on only the above tables
-- and remove this ledger row after all reversals succeed (or restore a backup).
-- Record completion only after every ALTER; do not use the client's --force.
INSERT INTO `hlstats_SchemaMigrations` (`filename`) VALUES
('2026_10_23_1100_event_retention.sql');
