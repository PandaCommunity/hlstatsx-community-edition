<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    $db->query("ALTER TABLE hlstats_Events_Frags
        DROP INDEX map,
        DROP INDEX weapon16,
        ADD INDEX map_killer_headshot_server (map,killerId,headshot,serverId),
        ADD INDEX weapon_killer_headshot_server (weapon,killerId,headshot,serverId)");

    $db->query("UPDATE hlstats_Options SET value = '85' WHERE keyname = 'dbversion'");
