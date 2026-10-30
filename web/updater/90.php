<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Cache each player's Steam avatar URL; avatarUpdated is the Unix time of the last lookup (0 = never).
    $db->query("ALTER TABLE hlstats_Players
        ADD COLUMN avatarUrl varchar(255) NULL DEFAULT NULL,
        ADD COLUMN avatarUpdated int(11) unsigned NOT NULL DEFAULT '0'");

    $db->query("UPDATE hlstats_Options SET value = '90' WHERE keyname = 'dbversion'");
