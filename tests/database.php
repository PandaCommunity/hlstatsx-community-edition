<?php
// Run with php tests/database.php and the HLSTATS_DB_* environment variables set.
// Uses read-only queries; no application schema or data is required or changed.
define('IN_HLSTATS', true);
require __DIR__ . '/../web/config.php';
require __DIR__ . '/../web/includes/class_db.php';

function error($message, $exit = true)
{
    throw new RuntimeException($message);
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([false, true] as $persistent) {
    $db = new DB_mysql(DB_ADDR, DB_USER, DB_PASS, DB_NAME, $persistent);
    $rows = $db->query("SELECT 17 AS value, NULL AS optional UNION ALL SELECT 29, 'second'");
    check($db->result(1, 'value', $rows) === '29', 'Named field at second row');
    check($db->result(0, 0, $rows) === '17', 'Numeric field at first row');
    check($db->result(0, 'optional', $rows) === null, 'SQL NULL must remain null');
    check($db->result(3, 'value', $rows) === false, 'Missing row must return false');
    check($db->result(0, 'missing', $rows) === false, 'Missing field must return false');
    $db->free_result($rows);

    check($db->query('SELECT FROM', false) === false, 'Suppressed SQL error must return false');
    try {
        $db->query('SELECT FROM');
        throw new LogicException('Expected application error handler');
    } catch (RuntimeException $e) {
        check(strpos($e->getMessage(), 'Bad query.') !== false, 'SQL error reaches application handler');
    }
    $db->query('SELECT 41 AS value');
    check($db->result(0, 'value') === '41', 'Connection remains usable after an SQL error');
    mysqli_close($db->link);
}

try {
    new DB_mysql(DB_ADDR, DB_USER, DB_PASS, 'php85_test_missing_database');
    throw new LogicException('Expected missing database error');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'Could not select database') !== false, 'Database selection error reaches application handler');
}

try {
    new DB_mysql(DB_ADDR, 'php85_test_missing_user', 'invalid', DB_NAME);
    throw new LogicException('Expected connection error');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'Could not connect') !== false, 'Connection error reaches application handler');
}

echo "PASS: normal/persistent connections, result access, and SQL/connection/database errors\n";
