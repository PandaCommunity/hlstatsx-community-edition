-- Requires legacy dbversion 82 and 2026_10_23_1000_query_indexes.sql. Apply once.
-- Follow README.md: back up, check disk space, and stop all writers/web access.
-- Frags is MyISAM: this ALTER rebuilds/locks the hot event table and commits DDL.
-- Replace the short prefixes, not the player/server lookup or retention keys.
-- killerId/headshot cover retained leaderboards; serverId also covers game totals.
ALTER TABLE `hlstats_Events_Frags`
  DROP INDEX `map`,
  DROP INDEX `weapon16`,
  ADD INDEX `map_killer_headshot_server` (`map`,`killerId`,`headshot`,`serverId`),
  ADD INDEX `weapon_killer_headshot_server` (`weapon`,`killerId`,`headshot`,`serverId`);

-- PHP rollback does not require index rollback. To reverse the schema during a
-- maintenance window, restore map(map(5)) and weapon16(weapon(16)), drop the two
-- composites, then remove this ledger row only after successful restoration.
-- Record success last; never apply with --force or rerun a partly applied file.
INSERT INTO `hlstats_SchemaMigrations` (`filename`) VALUES
('2026_10_23_1012_retained_dimensions.sql');
