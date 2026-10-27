<?php
if (!defined('IN_UPDATER')) {
    die('Do not access this file directly.');
}

// One definition for both preflight and execution. Do not replace custom keys.
function hlstatsUpgradeIndexPlan()
{
    $plan = [
        83 => [
            ['hlstats_Events_Disconnects', ['playerId' => 'playerId'], []],
            ['hlstats_Events_Teamkills', ['victimId' => 'victimId'], []],
            ['hlstats_Players_Awards', ['player_award_game' => 'playerId,awardId,game'], []],
            ['hlstats_Players_Ribbons', ['game_player_ribbon' => 'game,playerId,ribbonId', 'ribbon_player' => 'ribbonId,playerId'], []],
            ['hlstats_Players_History', ['player_time' => 'playerId,eventTime'], ['playerId' => 'playerId']],
            ['hlstats_server_load', ['server_timestamp' => 'server_id,timestamp'], ['server_id' => 'server_id']],
        ],
        84 => [['hlstats_Events_Chat', ['idx_chat_server_time' => 'serverId,eventTime,id,playerId'], []]],
        85 => [['hlstats_Events_Frags', [
            'map_killer_headshot_server' => 'map,killerId,headshot,serverId',
            'weapon_killer_headshot_server' => 'weapon,killerId,headshot,serverId',
        ], ['map' => 'map(5)', 'weapon16' => 'weapon(16)']]],
        86 => [['hlstats_Players', ['game_skill_rank' => 'game,hideranking,skill,kills,deaths'], ['game_hideranking' => 'game,hideranking']]],
        87 => [['hlstats_Events_Connects', ['ipAddress_playerId' => 'ipAddress,playerId'], []]],
        88 => [],
    ];
    foreach (['Admin', 'ChangeName', 'ChangeRole', 'ChangeTeam', 'Chat', 'Connects',
        'Disconnects', 'Entries', 'Frags', 'Latency', 'PlayerActions', 'PlayerPlayerActions',
        'Rcon', 'Statsme', 'Statsme2', 'StatsmeLatency', 'StatsmeTime', 'Suicides',
        'TeamBonuses', 'Teamkills'] as $event) {
        $plan[88][] = ['hlstats_Events_' . $event, ['eventTime' => 'eventTime'], []];
    }
    return $plan;
}

function hlstatsUpgradeIdentifier($name)
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function hlstatsUpgradeStop($message)
{
    error('Database upgrade stopped: ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '<br />Keep writers paused. See sql/migrations/README.md; do not change dbversion to bypass this check.');
}

function hlstatsUpgradeIndexes($db, $table)
{
    $result = $db->query('SHOW INDEX FROM ' . hlstatsUpgradeIdentifier($table));
    $indexes = [];
    while ($row = $db->fetch_array($result)) {
        $indexes[$row['Key_name']][] = $row;
    }
    return $indexes;
}

function hlstatsUpgradeIndexChanges($db, $table, $add, $drop)
{
    // MySQL/MariaDB index names are case-insensitive.
    $indexes = array_change_key_case(hlstatsUpgradeIndexes($db, $table), CASE_LOWER);
    $result = $db->query('SHOW COLUMNS FROM ' . hlstatsUpgradeIdentifier($table));
    $fields = [];
    while ($row = $db->fetch_array($result)) $fields[$row['Field']] = true;
    $changes = [];
    foreach (['ADD' => $add, 'DROP' => $drop] as $operation => $definitions) {
        foreach ($definitions as $name => $definition) {
            foreach (explode(',', $definition) as $part) {
                $field = explode('(', $part)[0];
                if (!isset($fields[$field])) {
                    hlstatsUpgradeStop("$table.$field is missing. Reconcile the version-82 schema before upgrading.");
                }
            }
            $lookup = strtolower($name);
            if (isset($indexes[$lookup])) {
                $columns = [];
                foreach ($indexes[$lookup] as $column) {
                    $columns[] = $column['Column_name'] . ($column['Sub_part'] === null ? '' : '(' . $column['Sub_part'] . ')');
                    if (!$column['Non_unique'] || $column['Index_type'] !== 'BTREE' || $column['Collation'] === 'D'
                        || ($column['Visible'] ?? 'YES') !== 'YES' || ($column['Ignored'] ?? 'NO') !== 'NO') {
                        hlstatsUpgradeStop("Custom index $table.$name does not match the required visible nonunique ascending BTREE ($definition).");
                    }
                }
                if (implode(',', $columns) !== $definition) {
                    hlstatsUpgradeStop("Custom index $table.$name does not match ($definition). Reconcile it before upgrading.");
                }
                if ($operation === 'DROP') {
                    $changes[] = 'DROP INDEX ' . hlstatsUpgradeIdentifier($name);
                }
            } elseif ($operation === 'ADD') {
                // Definitions above contain only repository-owned identifiers/prefixes.
                $changes[] = 'ADD INDEX ' . hlstatsUpgradeIdentifier($name) . " ($definition)";
            }
        }
    }
    return $changes;
}

function hlstatsUpgradeApplyIndexes($db, $version)
{
    foreach (hlstatsUpgradeIndexPlan()[$version] as [$table, $add, $drop]) {
        $changes = hlstatsUpgradeIndexChanges($db, $table, $add, $drop);
        if ($changes) {
            $db->query('ALTER TABLE ' . hlstatsUpgradeIdentifier($table) . ' ' . implode(', ', $changes));
        }
    }
}

// Read-only checks: return the tables which update 89 will convert. These are
// known blockers, not a promise that subsequent DDL cannot fail (disk, grants,
// timeouts, concurrent writes, custom schema features, etc.).
function hlstatsUpgradePreflight($db, $version)
{
    if (DB_CHARSET !== 'utf8mb4' || DB_COLLATE !== 'utf8mb4_general_ci') {
        hlstatsUpgradeStop('Set DB_CHARSET=utf8mb4 and DB_COLLATE=utf8mb4_general_ci in your local config.php first.');
    }
    $result = $db->query('SELECT VERSION()');
    $server = $db->fetch_row($result)[0];
    $minimum = stripos($server, 'MariaDB') === false ? '5.7.0' : '10.6.0';
    // Some MariaDB servers retain the protocol compatibility prefix.
    $numericVersion = preg_replace('/^5\.5\.5-(?=.*MariaDB)/i', '', $server);
    if (version_compare($numericVersion, $minimum, '<')) {
        hlstatsUpgradeStop("Database server $server is older than the supported minimum $minimum.");
    }

    $affected = [];
    foreach (hlstatsUpgradeIndexPlan() as $number => $tables) {
        if ($number <= $version) continue;
        foreach ($tables as [$table, $add, $drop]) {
            hlstatsUpgradeIndexChanges($db, $table, $add, $drop);
            $affected[$table] = true;
        }
    }

    $result = $db->query("SELECT TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $tables = $db->fetch_row_set($result);
    $conversions = [];
    $hasRank = false;
    foreach ($tables as $table) {
        $name = $table['TABLE_NAME'];
        $quoted = hlstatsUpgradeIdentifier($name);
        $columns = [];
        $changed = [];
        $dates = [];
        $result = $db->query("SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME, COLUMN_DEFAULT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->escape($name) . "'");
        while ($column = $db->fetch_array($result)) {
            $field = $column['COLUMN_NAME'];
            $columns[$field] = $column;
            if ($name === 'hlstats_Players' && $field === 'mmrank') $hasRank = true;
            if ($column['COLLATION_NAME'] !== null && $column['COLLATION_NAME'] !== 'utf8mb4_general_ci') {
                $changed[$field] = true;
            }
            if (in_array($column['DATA_TYPE'], ['date', 'datetime', 'timestamp'], true)) {
                if (preg_match('/^(0000-|[0-9]{4}-00-|[0-9]{4}-[0-9]{2}-00)/', trim((string) $column['COLUMN_DEFAULT'], "'"))) {
                    hlstatsUpgradeStop("$name.$field still has a zero-date default. This is not a complete version-82 schema; reconcile the historical date repair first.");
                }
                $dates[] = 'CAST(' . hlstatsUpgradeIdentifier($field) . " AS CHAR) REGEXP '^0000-|^[0-9]{4}-00-|^[0-9]{4}-[0-9]{2}-00'";
            }
        }
        $convert = $changed || $table['TABLE_COLLATION'] !== 'utf8mb4_general_ci';
        if ($dates && ($convert || isset($affected[$name]))) {
            $result = $db->query("SELECT 1 FROM $quoted WHERE " . implode(' OR ', $dates) . ' LIMIT 1');
            if ($db->fetch_row($result)) {
                hlstatsUpgradeStop("$name contains zero/partial-zero dates. Decide how to repair them before upgrading; the updater will not rewrite user data.");
            }
        }
        if (!$convert) continue;
        $conversions[] = $name;

        // Converting either end of a textual FK independently is not safe.
        $result = $db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->escape($name) . "'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            UNION ALL
            SELECT REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '" . $db->escape($name) . "'");
        while ($row = $db->fetch_row($result)) {
            if (isset($changed[$row[0]])) {
                hlstatsUpgradeStop("$name has a textual foreign key requiring coordinated conversion. Reconcile the custom constraint before upgrading.");
            }
        }
        foreach (hlstatsUpgradeIndexes($db, $name) as $index => $parts) {
            $group = [];
            $notNull = [];
            $textChanged = false;
            $textBytes = 0;
            foreach ($parts as $part) {
                $field = $part['Column_name'];
                if ($field === null) {
                    hlstatsUpgradeStop("$name.$index is an expression index; review it before collation conversion.");
                }
                $column = $columns[$field];
                $expression = hlstatsUpgradeIdentifier($field);
                $notNull[] = $expression . ' IS NOT NULL';
                if ($column['COLLATION_NAME'] !== null) {
                    $expression = "CONVERT($expression USING utf8mb4) COLLATE utf8mb4_general_ci";
                    $textChanged = $textChanged || isset($changed[$field]);
                    if (!in_array($column['DATA_TYPE'], ['enum', 'set'], true)) {
                        $textBytes += 4 * ($part['Sub_part'] ?? $column['CHARACTER_MAXIMUM_LENGTH']);
                    }
                }
                if ($part['Sub_part'] !== null) $expression = "LEFT($expression, " . (int) $part['Sub_part'] . ')';
                $group[] = $expression;
            }
            if ($table['ENGINE'] === 'InnoDB' && in_array($table['ROW_FORMAT'], ['Compact', 'Redundant'], true)
                && $parts[0]['Index_type'] !== 'FULLTEXT' && $textBytes > 767) {
                hlstatsUpgradeStop("$name.$index exceeds the 767-byte key limit after utf8mb4 conversion. Review the custom InnoDB row format/index before upgrading.");
            }
            if (!$parts[0]['Non_unique'] && $textChanged) {
                // SQL UNIQUE permits repeated rows if any key part is NULL.
                $result = $db->query("SELECT 1 FROM $quoted WHERE " . implode(' AND ', $notNull) .
                    ' GROUP BY ' . implode(', ', $group) . ' HAVING COUNT(*) > 1 LIMIT 1');
                if ($db->fetch_row($result)) {
                    hlstatsUpgradeStop("$name.$index contains values which collide under utf8mb4_general_ci. No rows were merged/deleted; resolve the conflict explicitly before upgrading.");
                }
            }
        }
    }
    if (!$hasRank) {
        hlstatsUpgradeStop('hlstats_Players.mmrank is missing. Start from a working version-82 installation; reconcile the historical mmrank patch first.');
    }
    return $conversions;
}
