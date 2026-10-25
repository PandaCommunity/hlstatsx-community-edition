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
        $result = $db->query("SELECT 1 FROM hlstats_SchemaMigrations WHERE filename = '2026_10_23_1030_search_ip_index.sql'");
        $applied = (bool) $db->fetch_row($result);
    }

    if (!$applied)
    {
        // Keep the player-leading key for player history lookups.
        $db->query("ALTER TABLE hlstats_Events_Connects ADD INDEX ipAddress_playerId (ipAddress,playerId)");
    }

    $db->query("UPDATE hlstats_Options SET value = '87' WHERE keyname = 'dbversion'");
