<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Retain serverId for fast counts and updater81's player/time key.
    $db->query("ALTER TABLE hlstats_Events_Chat
        ADD INDEX idx_chat_server_time (serverId,eventTime,id,playerId)");

    $db->query("UPDATE hlstats_Options SET value = '84' WHERE keyname = 'dbversion'");
