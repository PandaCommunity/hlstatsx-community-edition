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
        $result = $db->query("SELECT 1 FROM hlstats_SchemaMigrations WHERE filename = '2026_10_23_1100_event_retention.sql'");
        $applied = (bool) $db->fetch_row($result);
    }

    if (!$applied)
    {
        // History, Trend and server_load already have time-leading keys.
        $db->query("ALTER TABLE hlstats_Events_Admin ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_ChangeName ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_ChangeRole ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_ChangeTeam ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Chat ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Connects ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Disconnects ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Entries ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Frags ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Latency ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_PlayerActions ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_PlayerPlayerActions ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Rcon ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Statsme ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Statsme2 ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_StatsmeLatency ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_StatsmeTime ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Suicides ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_TeamBonuses ADD INDEX eventTime (eventTime)");
        $db->query("ALTER TABLE hlstats_Events_Teamkills ADD INDEX eventTime (eventTime)");
    }

    $db->query("UPDATE hlstats_Options SET value = '88' WHERE keyname = 'dbversion'");
