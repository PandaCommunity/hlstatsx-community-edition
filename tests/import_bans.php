<?php
// Run with php tests/import_bans.php and HLSTATS_DB_* set to a disposable database.
// Requires CREATE/DROP TABLE privileges; touches only uniquely named fixture tables.
define('IN_HLSTATS', true);
require __DIR__ . '/../web/config.php';

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new mysqli(DB_ADDR, DB_USER, DB_PASS, DB_NAME);
$prefix = 'php85_import_' . bin2hex(random_bytes(6));
$missing = $prefix . '_missing';
$directory = sys_get_temp_dir() . '/' . $prefix;
mkdir($directory, 0700);
$created = [];

try {
    $db->query("CREATE TABLE {$prefix}_bans (player_id VARCHAR(32), ban_length INT, expired INT)");
    $created[] = $prefix . '_bans';
    // No _banhistory table: successful imports must use the AMXBans schema fallback.
    $db->query("INSERT INTO {$prefix}_bans VALUES ('STEAM_0:1:123',0,0),('STEAM_0:1:456',30,1)");
    $db->query("CREATE TABLE {$prefix}_Players (playerId INT, hideranking INT)");
    $created[] = $prefix . '_Players';
    $db->query("INSERT INTO {$prefix}_Players VALUES (1,0),(2,2),(3,0)");
    $db->query("CREATE TABLE {$prefix}_PlayerUniqueIds (playerId INT, uniqueId VARCHAR(32))");
    $created[] = $prefix . '_PlayerUniqueIds';
    $db->query("INSERT INTO {$prefix}_PlayerUniqueIds VALUES (1,'1:123'),(2,'1:456'),(3,'1:789')");

    $config = "<?php\n";
    foreach (['SB', 'AMX', 'BM', 'GB', 'MB', 'HLX'] as $adapter) {
        $values = [
            'HOST' => DB_ADDR, 'PORT' => (int)ini_get('mysqli.default_port'),
            'USER' => DB_USER, 'PASS' => DB_PASS, 'NAME' => DB_NAME, 'PREFIX' => $prefix,
        ];
        foreach ($values as $key => $value) {
            if (!in_array($adapter, ['AMX', 'HLX'], true)) {
                $value = '';
            }
            $config .= 'define(' . var_export($adapter . '_' . $key, true) . ', ' . var_export($value, true) . ");\n";
        }
    }

    foreach ([
        'valid target' => [DB_NAME],
        'valid then missing target' => [DB_NAME, $missing, DB_NAME],
        'single missing target' => [$missing],
    ] as $scenario => $targets) {
        $db->query("UPDATE {$prefix}_Players SET hideranking = IF(playerId = 2, 2, 0)");
        file_put_contents($directory . '/hlstatsxban.cfg', $config . '$hlxdbs = ' . var_export($targets, true) . ";\n");
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../scripts/ImportBans/hlstatsxban.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if (in_array($missing, $targets, true)) {
            check($status !== 0, "$scenario: selection failure must exit nonzero");
            check(str_contains($stderr, 'Error selecting HLstatsX database') && str_contains($stderr, $missing), "$scenario: identify the failed database");
            check(preg_match('/\([0-9]+\) .+/', $stderr) === 1, "$scenario: include the database error");
            check(!str_contains($stdout, "$missing: No player changed"), "$scenario: do not report the failed target as processed");
        } else {
            check($status === 0 && $stderr === '', 'AMXBans fallback must still succeed');
        }

        $validFirst = $targets[0] === DB_NAME;
        check(substr_count($stdout, DB_NAME . ': 1 players were marked as banned, 1 players were reenabled again.') === (int)$validFirst, "$scenario: process valid targets only before the failure");
        check(!str_contains($stdout, DB_NAME . ': No player changed'), "$scenario: do not continue to later targets after failure");
        $rows = $db->query("SELECT playerId, hideranking FROM {$prefix}_Players ORDER BY playerId")->fetch_all(MYSQLI_NUM);
        $expected = $validFirst ? [['1','2'], ['2','0'], ['3','0']] : [['1','0'], ['2','2'], ['3','0']];
        check($rows === $expected, "$scenario: banned/unbanned/unaffected rankings");
        echo "PASS: $scenario (exit $status)\n";
    }
} finally {
    foreach (array_reverse($created) as $table) {
        $db->query("DROP TABLE $table");
    }
    if (file_exists($directory . '/hlstatsxban.cfg')) {
        unlink($directory . '/hlstatsxban.cfg');
    }
    rmdir($directory);
    $db->close();
}
