<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    require_once './updater/functions.php';
    hlstatsUpgradeApplyIndexes($db, 83);

    // DDL commits independently; advance the version only after success.
    $db->query("UPDATE hlstats_Options SET value = '83' WHERE keyname = 'dbversion'");
