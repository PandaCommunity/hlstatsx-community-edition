<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Keep the player-leading key for player history lookups.
    $db->query("ALTER TABLE hlstats_Events_Connects ADD INDEX ipAddress_playerId (ipAddress,playerId)");

    $db->query("UPDATE hlstats_Options SET value = '87' WHERE keyname = 'dbversion'");
