<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    require_once './updater/functions.php';
    hlstatsUpgradeApplyIndexes($db, 87);

    $db->query("UPDATE hlstats_Options SET value = '87' WHERE keyname = 'dbversion'");
