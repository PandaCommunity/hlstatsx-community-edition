<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Avatar refresh walks recently active players backwards along last_event,
    // skipping fresh avatars; playerId makes the MyISAM key covering. Runs first
    // because it rebuilds hlstats_Players, blocks writes to it and is the step
    // most likely to fail. Ascending key parts only: MySQL 8 rejects DESC keys
    // on MyISAM.
    $db->query("ALTER TABLE hlstats_Players ADD INDEX last_event_avatar (last_event, avatarUpdated, playerId)");

    // One shared row decides which web request may call the Steam Web API next
    // (Unix times from the database clock). InnoDB so the claim UPDATE locks only
    // this row, not a MyISAM table.
    $db->query("CREATE TABLE hlstats_SteamAvatarGate (
        id tinyint(3) unsigned NOT NULL,
        next_allowed_at int(11) unsigned NOT NULL DEFAULT '0',
        lease_owner varchar(32) NOT NULL DEFAULT '',
        lease_until int(11) unsigned NOT NULL DEFAULT '0',
        last_attempt_at int(11) unsigned NOT NULL DEFAULT '0',
        last_success_at int(11) unsigned NOT NULL DEFAULT '0',
        last_status varchar(64) NOT NULL DEFAULT '',
        failures int(10) unsigned NOT NULL DEFAULT '0',
        calls int(10) unsigned NOT NULL DEFAULT '0',
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $db->query("INSERT INTO hlstats_SteamAvatarGate (id) VALUES (1)");

    $db->query("UPDATE hlstats_Options SET value = '92' WHERE keyname = 'dbversion'");
