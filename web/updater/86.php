<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Recognize the manually applied version of this update, if present.
    $ledger = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hlstats_SchemaMigrations'");
    $applied = false;
    if ($db->fetch_row($ledger))
    {
        $result = $db->query("SELECT 1 FROM hlstats_SchemaMigrations WHERE filename = '2026_10_23_1030_player_ranking.sql'");
        $applied = (bool) $db->fetch_row($result);
    }

    if (!$applied)
    {
        $db->query("ALTER TABLE hlstats_Players
            ADD INDEX game_skill_rank (game,hideranking,skill,kills,deaths),
            DROP INDEX game_hideranking");
    }

    $db->query("UPDATE hlstats_Options SET value = '86' WHERE keyname = 'dbversion'");
