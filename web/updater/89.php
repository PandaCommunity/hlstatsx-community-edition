<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    require_once './updater/functions.php';
    // The updater preflights all pending updates before the first ALTER. Also
    // check when this numbered file is invoked on its own during recovery.
    $upgradeTables = $upgradeTables ?? hlstatsUpgradePreflight($db, 88);

    foreach ($upgradeTables as $name)
    {
        $table = hlstatsUpgradeIdentifier($name);
        $db->query("ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    // Change the database default only after its existing tables are converted.
    $db->query("ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    // Conversions can rebuild/lock tables and commit independently. Stop on error.
    $db->query("UPDATE hlstats_Options SET value = '89' WHERE keyname = 'dbversion'");
