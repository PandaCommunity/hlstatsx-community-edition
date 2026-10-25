<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Also normalize databases that completed the earlier unicode_ci update 89.
    require __DIR__ . '/89.php';
    $db->query("UPDATE hlstats_Options SET value = '90' WHERE keyname = 'dbversion'");
