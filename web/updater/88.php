<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

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

    $db->query("UPDATE hlstats_Options SET value = '88' WHERE keyname = 'dbversion'");
