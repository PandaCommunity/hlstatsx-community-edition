<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Killer's event history (kills / headshots by time) and retained kill/headshot totals.
    $db->query("ALTER TABLE hlstats_Events_Frags
        ADD INDEX killer_headshot_time (killerId, headshot, eventTime, id)");

    // Daily award details filter by award.
    $db->query("ALTER TABLE hlstats_Players_Awards
        ADD INDEX award_time_player (awardId, awardTime, playerId)");

    $db->query("UPDATE hlstats_Options SET value = '91' WHERE keyname = 'dbversion'");
