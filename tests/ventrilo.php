<?php
/* Run: php tests/ventrilo.php (PHP 8.5+, pcntl on Unix).
 * Browser fixture: php tests/ventrilo.php --serve 37840 success
 * Scenarios: success, empty-lists, empty, timeout, error, malformed, missing,
 * bad-crc, bad-length, bad-index, inconsistent, hostile-counts, delayed,
 * unrelated-invalid, irrelevant-traffic.
 *
 * Wire arithmetic follows Luigi Auriemma's ventrilo_udp.h:
 * https://aluigi.altervista.org/papers/ventrilo_udp.h
 * Copyright 2005 Luigi Auriemma; GPL v2 or later, like the production port.
 * Only immutable cipher tables are shared with production. CRC, encoding,
 * request decoding, and expected parsed/rendered values are independent.
 */
require_once __DIR__ . '/../web/pages/ventrilostatus.php';
error_reporting(E_ALL);
set_error_handler(function ($level, $message, $file, $line) {
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
    return false;
});

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureCRC($data)
{
    $crc = 0;
    foreach (unpack('C*', $data) as $byte) {
        // Bitwise polynomial division, independent of the production CRC table.
        $top = $crc & 0xff00;
        for ($bit = 0; $bit < 8; $bit++) {
            $top = (($top << 1) ^ (($top & 0x8000) ? 0x1021 : 0)) & 0xffff;
        }
        $crc = ($top ^ ($crc << 8) ^ $byte) & 0xffff;
    }
    return $crc;
}

function fixtureCipher($bytes, $key, $header, $direction)
{
    $table = $header ? getHeadEncodeRef() : getDataEncodeRef();
    $out = '';
    $index = $key >> 8;
    foreach (unpack('C*', $bytes) as $i => $byte) {
        $out .= chr(($byte + $direction * ($table[$index] + (($i - 1) % ($header ? 5 : 72)))) & 255);
        $index = ($index + ($key & 255)) & 255;
    }
    return $out;
}

function fixturePacket($id, $data, $total, $count, $index, $crc)
{
    $header = pack('n9', 0, 3, $id, $total, strlen($data), $count, $index, 0x2345, $crc);
    return pack('n', 0x1237) . fixtureCipher($header, 0x1237, true, 1)
        . fixtureCipher($data, 0x2345, false, 1);
}

function fixtureText($scenario)
{
    if ($scenario === 'empty') { return ''; }
    if ($scenario === 'error') { return "ERROR: Status%20disabled\n"; }
    $text = "NAME: Fixture%20Voice\r\nAUTH: 0\nMAXCLIENTS: 24\tPLATFORM: Linux\nUPTIME: 3661\n";
    if ($scenario === 'empty-lists') {
        return $text . "CHANNELCOUNT: 0\nCLIENTCOUNT: 0\n";
    }
    if ($scenario === 'hostile-counts') {
        return str_replace('MAXCLIENTS: 24', 'MAXCLIENTS: <iframe srcdoc=x>', $text)
            . "CHANNELCOUNT: <img src=x onerror=alert(document.domain)>\n"
            . "CLIENTCOUNT: <svg onload=alert(document.domain)>\n";
    }
    return $text . 'COMMENT: ' . str_repeat('Padding ', 60) . "\n"
        . "CHANNELCOUNT: 3\nCLIENTCOUNT: 3\n"
        . "CHANNEL: CID=1,PID=0,PROT=0,NAME=Lobby\r\n"
        . "CHANNEL: CID=2,PID=1,PROT=1,NAME=Squad%20%26%20Friends,COMM=Private\n"
        . "CHANNEL: CID=8,PID=0,PROT=2,NAME=Empty\n"
        . "CLIENT: UID=10,ADMIN=1,PHAN=0,CID=0,NAME=Root\n"
        . "CLIENT: UID=11,ADMIN=0,PHAN=1,CID=2,NAME=Zoë%2C%20Scout,COMM=%3Cb%3Eready%3C%2Fb%3E\n"
        . "CLIENT: UID=12,ADMIN=0,PHAN=0,CID=1,NAME=Bob\n";
}

function fixtureReply($socket, $scenario)
{
    $request = stream_socket_recvfrom($socket, 1024, 0, $peer);
    check(strlen($request) === 36, 'Request must have a 20-byte header and 16-byte password');
    $key = unpack('n', $request)[1];
    check(($key & 255) !== 0, 'Request header key must be decryptable');
    $head = array_values(unpack('n9', fixtureCipher(substr($request, 2, 18), $key, true, -1)));
    check($head[0] === 0 && $head[1] === 2 && $head[3] === 16 && $head[4] === 16
        && $head[5] === 1 && $head[6] === 0, 'Request wire header differs from protocol');
    check(($head[7] & 255) !== 0, 'Request data key must be decryptable');
    $password = fixtureCipher(substr($request, 20), $head[7], false, -1);
    check($password === pack('a16', 'status'), 'Status password was not sent intact');
    check($head[8] === fixtureCRC($password), 'Request checksum differs from protocol');
    if ($scenario === 'timeout') { return; }
    if ($scenario === 'malformed') {
        stream_socket_sendto($socket, 'short', 0, $peer);
        return;
    }
    $text = fixtureText($scenario);
    $chunks = $text === '' ? array('') : str_split($text, 492);
    $crc = fixtureCRC($text) ^ ($scenario === 'bad-crc' ? 1 : 0);
    $total = strlen($text) + ($scenario === 'bad-length' ? 1 : 0);
    // Deliver out of order, with a wrong-ID packet and a duplicate before packet 0.
    $unrelated = fixturePacket(($head[2] + 1) & 65535, 'ignored', 7, 1,
        $scenario === 'unrelated-invalid' ? 1 : 0, fixtureCRC('ignored'));
    stream_socket_sendto($socket, $unrelated, 0, $peer);
    if ($scenario === 'irrelevant-traffic') {
        $until = microtime(true) + 1.2;
        while (microtime(true) < $until) {
            stream_socket_sendto($socket, $unrelated, 0, $peer);
            usleep(5000);
        }
        return;
    }
    foreach (array_reverse(array_keys($chunks)) as $i) {
        if ($scenario === 'missing' && $i === 0) { continue; }
        if ($scenario === 'delayed') { usleep(60000); }
        $packet = fixturePacket($head[2], $chunks[$i], $total, count($chunks),
            $scenario === 'bad-index' ? count($chunks) : $i,
            $crc ^ ($scenario === 'inconsistent' && $i === 0 ? 1 : 0));
        stream_socket_sendto($socket, $packet, 0, $peer);
        if ($i > 0) { stream_socket_sendto($socket, $packet, 0, $peer); }
    }
}

function withFixture($scenario, $callback, $requests = 1)
{
    $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    check($socket !== false, $errstr);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    $pid = pcntl_fork();
    check($pid !== -1, 'Could not fork UDP fixture');
    if ($pid === 0) {
        pcntl_alarm(5);
        try {
            for ($i = 0; $i < $requests; $i++) { fixtureReply($socket, $scenario); }
            // Keep timeout sockets open so the caller exercises read timeout, not ICMP refusal.
            if ($scenario === 'timeout' || $scenario === 'missing') { usleep(200000); }
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, $error . "\n");
            exit(1);
        }
    }
    fclose($socket);
    try {
        return $callback($port);
    } finally {
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'UDP fixture failed');
    }
}

// Minimal DB/page shell for exercising the real viewer and server-list includes.
if (($argv[1] ?? '') === '--render') {
    define('PAGE_PATH', __DIR__ . '/../web/pages');
    define('IMAGE_PATH', './hlstatsimg');
    function eHtml($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function pageHeader($a, $b) {}
    function printSectionTitle($title) { echo $title; }
    function valid_request($value, $numeric) { return (int) $value; }
    function error($message, $exit = true) { echo eHtml($message); }
    class FixtureDB
    {
        public $rows;
        public $row;
        function query($sql) { $this->rows = array($this->row); return true; }
        function num_rows($result) { return count($this->rows); }
        function fetch_array() { return array_shift($this->rows); }
    }
    $db = new FixtureDB;
    $db->row = array('serverId' => 1, 'name' => 'Fixture Voice', 'addr' => '127.0.0.1',
        'password' => 'status', 'descr' => 'Local UDP fixture', 'queryPort' => (int) $argv[2], 'serverType' => 1);
    $_GET['veId'] = 1;
    $game = 'tf';
    $g_options = array('scripturl' => 'hlstats.php');
    include PAGE_PATH . '/ventrilo.php';
    exit;
}

if (($argv[1] ?? '') === '--serve') {
    $socket = stream_socket_server('udp://127.0.0.1:' . (int) $argv[2], $errno, $errstr, STREAM_SERVER_BIND);
    check($socket !== false, $errstr);
    echo "Ventrilo UDP fixture ready\n";
    while (true) { fixtureReply($socket, $argv[3] ?? 'success'); }
}

$empty = new CVentriloStatus;
check($empty->m_channellist === array() && $empty->m_clientlist === array() && $empty->ChannelFind(1) === null, 'Empty lists must be usable');
$empty->Parse('CHANNEL: CID=1,PID=0,NAME=Lobby');
$empty->Parse('CLIENT: UID=7,CID=1,NAME=Alice');
check($empty->ChannelFind(1)->m_name === 'Lobby' && count($empty->m_clientlist) === 1, 'Direct parsing failed');
echo "PASS direct parsing and initially empty lists\n";

// Binary bytes distinguish unsigned CRC/wrapping from the legacy signed implementation.
foreach (array('', "\x80\xff\x00\x01", '123456789') as $bytes) {
    check(Vent::getCRC($bytes) === fixtureCRC($bytes), 'CRC differs from C protocol');
}
$request = new VentRequestPacket(2, 123, "\x80secret");
$key = unpack('n', $request->packet)[1];
$head = array_values(unpack('n9', fixtureCipher(substr($request->packet, 2, 18), $key, true, -1)));
check($head[2] === 123 && $head[8] === fixtureCRC(pack('a16', "\x80secret")), 'Binary-password request header failed');
check(fixtureCipher(substr($request->packet, 20), $head[7], false, -1) === pack('a16', "\x80secret"), 'Binary-password encoding failed');
echo "PASS request encoding and unsigned CRC\n";

foreach (array('hostile-counts', 'delayed', 'unrelated-invalid', 'success', 'empty-lists', 'empty', 'timeout', 'error', 'malformed', 'missing', 'bad-crc', 'bad-length', 'bad-index', 'inconsistent') as $scenario) {
    withFixture($scenario, function ($port) use ($scenario) {
        $status = new CVentriloStatus;
        $status->m_cmdcode = 2;
        $status->m_cmdhost = '127.0.0.1';
        $status->m_cmdport = $port;
        $status->m_cmdpass = 'status';
        $start = microtime(true);
        ob_start();
        $rc = $status->Request();
        check(ob_get_clean() === '', 'Request must not print transport diagnostics');
        check(microtime(true) - $start < 1, 'Request did not respect its timeout');
        if (in_array($scenario, array('success', 'delayed', 'unrelated-invalid'), true)) {
            check($rc === 0 && $status->m_name === 'Fixture Voice' && $status->m_auth === '0', 'Server fields failed');
            check((int) $status->m_channelcount === 3 && count($status->m_channellist) === 3, 'Channel lines were lost');
            check((int) $status->m_clientcount === 3 && count($status->m_clientlist) === 3, 'Client lines were lost');
            check($status->ChannelPathName(1) === 'Lobby/Squad & Friends', 'Channel hierarchy failed');
            check($status->m_clientlist[1]->m_name === 'Zoë, Scout' && $status->m_clientlist[1]->m_comm === '<b>ready</b>', 'Client text decoding failed');
        } elseif ($scenario === 'hostile-counts') {
            check($rc === 0 && $status->m_channelcount === 0 && $status->m_clientcount === 0
                && $status->m_maxclients === 0, 'Hostile counts were not normalized');
        } elseif ($scenario === 'empty-lists') {
            check($rc === 0 && $status->m_channellist === array() && $status->m_clientlist === array(), 'Empty server failed');
        } else {
            $errors = array('empty' => 'Empty Ventrilo', 'timeout' => 'Timed out',
                'error' => 'Status%20disabled', 'malformed' => 'Response packet was 5 bytes',
                'missing' => 'Timed out', 'bad-crc' => 'Response crc', 'bad-length' => 'Response data',
                'bad-index' => 'Invalid packet count or index', 'inconsistent' => 'Inconsistent Ventrilo');
            check($rc === -1 && strpos($status->m_error, $errors[$scenario]) !== false,
                'Wrong failure for ' . $scenario . ': ' . $status->m_error);
        }
    });
    echo "PASS UDP $scenario\n";
}

withFixture('irrelevant-traffic', function ($port) {
    $vent = new Vent;
    $vent->setTimeout(20000);
    $start = microtime(true);
    check(!$vent->makeRequest(2, '127.0.0.1', $port, 'status'), 'Irrelevant traffic must not succeed');
    $elapsed = microtime(true) - $start;
    // 32 packet slots × 20 ms idle budget = 640 ms maximum overall collection.
    check($elapsed >= 0.5 && $elapsed < 1 && strpos($vent->error, 'Timed out') !== false,
        'Irrelevant traffic must stop at the overall deadline: ' . $elapsed);
});
echo "PASS overall deadline under continuous irrelevant traffic\n";

foreach (array('success', 'empty-lists', 'timeout', 'hostile-counts') as $scenario) {
    withFixture($scenario, function ($port) use ($scenario) {
        $process = proc_open(array(PHP_BINARY, __FILE__, '--render', (string) $port),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0 && $errors === '', 'Viewer failed: ' . $errors);
        if ($scenario === 'timeout') {
            check(strpos($html, 'No Ventrilo: Timed out') !== false && strpos($html, 'Ventrilo Overview') === false, 'Viewer timeout was not rendered');
        } else {
            check(strpos($html, 'Ventrilo Overview') !== false, 'Viewer overview missing');
            $slots = $scenario === 'success' ? '3/24' : ($scenario === 'hostile-counts' ? '0/0' : '0/24');
            check(strpos($html, $slots) !== false, 'Server-list slots incorrect');
            if ($scenario === 'hostile-counts') {
                check(strpos($html, '<img src=x') === false && strpos($html, '<svg') === false
                    && strpos($html, '<iframe') === false && strpos($html, 'alert(document.domain)') === false,
                    'Hostile counts emitted markup in viewer or server list');
            }
            if ($scenario === 'success') {
                check(strpos($html, 'Squad &amp; Friends') !== false && strpos($html, 'Zoë, Scout') !== false, 'Channel/client rendering missing');
                check(strpos($html, '&lt;b&gt;ready&lt;/b&gt;') !== false && strpos($html, '<b>ready</b>') === false, 'Server markup must render as text');
            }
        }
    }, 2);
    echo "PASS viewer and server list $scenario\n";
}
echo "All Ventrilo regressions passed.\n";
