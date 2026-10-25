<?php
    if (!defined('IN_UPDATER'))
    {
        die('Do not access this file directly.');
    }

    // Match install.sql and DB_COLLATE, not the server's version-dependent default.
    // ALTER DATABASE alone does not change existing tables or column overrides.
    $db->query("ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $tables = $db->query("SELECT t.TABLE_NAME
        FROM information_schema.TABLES AS t
        WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
          AND (t.TABLE_COLLATION <> 'utf8mb4_general_ci' OR EXISTS (
              SELECT 1 FROM information_schema.COLUMNS AS c
              WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
                AND c.COLLATION_NAME <> 'utf8mb4_general_ci'
          ))
        ORDER BY t.TABLE_NAME");

    while ($row = $db->fetch_row($tables))
    {
        $table = str_replace('`', '``', $row[0]);
        $db->query("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    // Conversions can rebuild/lock tables and commit independently. Stop on error.
    $db->query("UPDATE hlstats_Options SET value = '89' WHERE keyname = 'dbversion'");
