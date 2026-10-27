<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    $db->query("ALTER TABLE hlstats_Players
        ADD INDEX game_skill_rank (game,hideranking,skill,kills,deaths),
        DROP INDEX game_hideranking");

    $db->query("UPDATE hlstats_Options SET value = '86' WHERE keyname = 'dbversion'");
