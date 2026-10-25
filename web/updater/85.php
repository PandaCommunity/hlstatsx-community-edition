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
        $result = $db->query("SELECT 1 FROM hlstats_SchemaMigrations WHERE filename = '2026_10_23_1012_retained_dimensions.sql'");
        $applied = (bool) $db->fetch_row($result);
    }

    if (!$applied)
    {
        $db->query("ALTER TABLE hlstats_Events_Frags
            DROP INDEX map,
            DROP INDEX weapon16,
            ADD INDEX map_killer_headshot_server (map,killerId,headshot,serverId),
            ADD INDEX weapon_killer_headshot_server (weapon,killerId,headshot,serverId)");
    }

    $db->query("UPDATE hlstats_Options SET value = '85' WHERE keyname = 'dbversion'");
