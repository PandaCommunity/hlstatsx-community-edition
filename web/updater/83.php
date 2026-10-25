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
        $result = $db->query("SELECT 1 FROM hlstats_SchemaMigrations WHERE filename = '2026_10_23_1000_query_indexes.sql'");
        $applied = (bool) $db->fetch_row($result);
    }

    if (!$applied)
    {
        $db->query("ALTER TABLE hlstats_Events_Disconnects ADD INDEX playerId (playerId)");
        $db->query("ALTER TABLE hlstats_Events_Teamkills ADD INDEX victimId (victimId)");
        $db->query("ALTER TABLE hlstats_Players_Awards ADD INDEX player_award_game (playerId,awardId,game)");
        $db->query("ALTER TABLE hlstats_Players_Ribbons
            ADD INDEX game_player_ribbon (game,playerId,ribbonId),
            ADD INDEX ribbon_player (ribbonId,playerId)");
        $db->query("ALTER TABLE hlstats_Players_History
            ADD INDEX player_time (playerId,eventTime), DROP INDEX playerId");
        $db->query("ALTER TABLE hlstats_server_load
            ADD INDEX server_timestamp (server_id,timestamp), DROP INDEX server_id");
    }

    // DDL commits independently; advance the version only after success.
    $db->query("UPDATE hlstats_Options SET value = '83' WHERE keyname = 'dbversion'");
