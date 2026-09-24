<?php
/**
 * Ticket-data integrity check for the osTicket upgrade. Read-only.
 *
 *   php ops/upgrade/ticket_integrity.php snapshot > before.json
 *   ... upgrade ...
 *   php ops/upgrade/ticket_integrity.php verify before.json
 *
 * "snapshot" records, for every ticket-data table, the row count, the highest
 * id, and a hash of every row's full content. "verify" re-hashes exactly the
 * rows that existed at snapshot time and reports any row that is missing or
 * changed. New rows (e.g. the "osTicket Upgraded!" notice) are reported
 * separately and are expected.
 *
 * DB credentials are read from include/ost-config.php at runtime.
 */
if (PHP_SAPI != 'cli') die("CLI only\n");

$root = dirname(__DIR__, 2);
$cfg = "$root/include/ost-config.php";
if (!is_readable($cfg)) die("Cannot read $cfg (run from the osTicket root as the web server user)\n");
define('ROOT_DIR', "$root/"); define('INCLUDE_DIR', "$root/include/"); define('ROOT_PATH', '/');
require $cfg;
if (!defined('DBHOST')) die("ost-config.php has no DB settings\n");

list($host, $port) = array_pad(explode(':', DBHOST, 2), 2, null);
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
if (!@$db->real_connect($host, DBUSER, DBPASS, DBNAME, $port ? (int)$port : null))
    die('DB connect failed: ' . mysqli_connect_error() . "\n");
$db->set_charset('utf8mb4');
$p = TABLE_PREFIX;

// table => column used to bound "rows that existed at snapshot time"
$tables = array(
    'ticket' => 'ticket_id', 'ticket__cdata' => 'ticket_id',
    'thread' => 'id', 'thread_entry' => 'id', 'thread_event' => 'id',
    'thread_collaborator' => 'id', 'thread_referral' => 'id',
    'attachment' => 'id', 'file' => 'id', 'file_chunk' => 'file_id',
    'user' => 'id', 'user_email' => 'id', 'user__cdata' => 'user_id', 'user_account' => 'id',
    'organization' => 'id', 'form_entry' => 'id', 'form_entry_values' => 'entry_id',
    'task' => 'id', 'task__cdata' => 'task_id', 'draft' => 'id',
);

function q($db, $sql) {
    $r = $db->query($sql);
    if (!$r) die("SQL error: {$db->error}\n$sql\n");
    return $r;
}
function exists($db, $t) {
    return q($db, "SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'")->num_rows > 0;
}
function columns($db, $t) {
    $cols = array();
    $r = q($db, "SHOW COLUMNS FROM `$t`");
    while ($c = $r->fetch_assoc()) $cols[] = $c['Field'];
    return $cols;
}
// Order-independent hash of the given rows: count + XOR and SUM of per-row CRC32.
function digest($db, $t, $cols, $key, $max) {
    $expr = 'CONCAT_WS(0x1F,' . implode(',', array_map(function ($c) {
        return "IFNULL(HEX(`$c`),'~')"; }, $cols)) . ')';
    $where = $max === null ? '' : "WHERE `$key` <= " . (int)$max;
    return q($db, "SELECT COUNT(*) n, IFNULL(BIT_XOR(CRC32($expr)),0) x, IFNULL(SUM(CRC32($expr)),0) s,
        MAX(`$key`) m FROM `$t` $where")->fetch_assoc();
}

$mode = isset($argv[1]) ? $argv[1] : '';
if ($mode == 'snapshot') {
    $out = array('taken' => date('c'), 'db' => DBNAME, 'prefix' => $p,
        'schema' => q($db, "SELECT value FROM `{$p}config` WHERE namespace='core' AND `key`='schema_signature'")->fetch_row()[0],
        'tables' => array());
    foreach ($tables as $t => $key) {
        if (!exists($db, $p . $t)) continue;
        $cols = columns($db, $p . $t);
        $d = digest($db, $p . $t, $cols, $key, null);
        $out['tables'][$t] = array('key' => $key, 'max' => $d['m'], 'rows' => (int)$d['n'],
            'xor' => $d['x'], 'sum' => $d['s'], 'cols' => $cols);
    }
    echo json_encode($out, JSON_PRETTY_PRINT), "\n";
    fwrite(STDERR, sprintf("Snapshot of %d tables, %d tickets.\n", count($out['tables']),
        $out['tables']['ticket']['rows']));
    exit(0);
}

if ($mode == 'verify' && isset($argv[2])) {
    $snap = json_decode(file_get_contents($argv[2]), true);
    if (!$snap) die("Cannot read snapshot {$argv[2]}\n");
    $bad = 0;
    printf("%-22s %10s %10s %8s  %s\n", 'table', 'before', 'now', 'new', 'pre-existing rows');
    foreach ($snap['tables'] as $t => $s) {
        if (!exists($db, $p . $t)) { printf("%-22s MISSING TABLE\n", $t); $bad++; continue; }
        $now = columns($db, $p . $t);
        $cols = array_values(array_intersect($s['cols'], $now));
        $lost = array_diff($s['cols'], $now);
        $d = digest($db, $p . $t, $cols, $s['key'], $s['max']);
        $total = q($db, "SELECT COUNT(*) FROM `$p$t`")->fetch_row()[0];
        $ok = !$lost && $d['n'] == $s['rows'] && $d['x'] == $s['xor'] && $d['s'] == $s['sum'];
        if (!$ok) $bad++;
        printf("%-22s %10d %10d %8d  %s\n", $t, $s['rows'], $total, $total - $d['n'],
            $ok ? 'IDENTICAL' : ('CHANGED' . ($lost ? ' (columns dropped: ' . implode(',', $lost) . ')' : '')
                . ($d['n'] != $s['rows'] ? " ({$d['n']} of {$s['rows']} rows present)" : '')));
    }
    echo $bad ? "\nFAILED: $bad table(s) differ. Do not reopen the helpdesk; see the rollback section.\n"
              : "\nPASSED: every ticket-data row that existed before the upgrade is present and unchanged.\n";
    exit($bad ? 1 : 0);
}

die("Usage: php ops/upgrade/ticket_integrity.php snapshot > before.json\n"
  . "       php ops/upgrade/ticket_integrity.php verify before.json\n");
