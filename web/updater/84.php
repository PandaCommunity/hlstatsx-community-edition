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
        $result = $db->query("SELECT 1 FROM hlstats_SchemaMigrations WHERE filename = '2026_10_23_1005_chat_pagination.sql'");
        $applied = (bool) $db->fetch_row($result);
    }

    if (!$applied)
    {
        // Retain serverId for fast counts and updater81's player/time key.
        $db->query("ALTER TABLE hlstats_Events_Chat
            ADD INDEX idx_chat_server_time (serverId,eventTime,id,playerId)");
    }

    $db->query("UPDATE hlstats_Options SET value = '84' WHERE keyname = 'dbversion'");
