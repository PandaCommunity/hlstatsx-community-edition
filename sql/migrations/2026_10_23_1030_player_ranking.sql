-- Requires legacy dbversion 82 and 2026_10_23_1000_query_indexes.sql.
-- Apply once with writers paused; see README.md. MyISAM ALTER rebuilds/locks
-- Players and commits independently. Allow disk space for the rebuilt table.
-- Covers exact skill-rank comparisons and eligible-population counts, retaining
-- the game/hideranking prefix. Computed leaderboard sorts still use filesort.
ALTER TABLE `hlstats_Players`
  ADD INDEX `game_skill_rank` (`game`,`hideranking`,`skill`,`kills`,`deaths`),
  DROP INDEX `game_hideranking`;

-- Rollback, if required during maintenance: restore game_hideranking(game,
-- hideranking), drop game_skill_rank, then delete only this file's ledger row.
-- PHP/Perl queries work with either schema; rolling back code needs no DDL.
INSERT INTO `hlstats_SchemaMigrations` (`filename`) VALUES
('2026_10_23_1030_player_ranking.sql');
