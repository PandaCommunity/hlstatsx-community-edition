<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    require_once './updater/functions.php';
    hlstatsUpgradeApplyIndexes($db, 88);

    $db->query("UPDATE hlstats_Options SET value = '88' WHERE keyname = 'dbversion'");
