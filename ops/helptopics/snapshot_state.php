<?php
/**
 * Read-only snapshot of help topics, forms, filters, departments and
 * related settings → Markdown (§2 step 4). Changes nothing.
 *
 *   php ops/helptopics/snapshot_state.php [output.md]   (default: ops/helptopics/current_state.md)
 */
require __DIR__ . '/lib.php';


$out = $argv[1] ?? __DIR__ . '/current_state.md';
$P = TABLE_PREFIX;
function rows($sql) { $r = array(); if ($q = db_query($sql)) while ($x = db_fetch_array($q)) $r[] = $x; return $r; }
function md($s) { return str_replace(array('|', "\n", "\r"), array('\|', ' ', ''), (string) $s); }

$L = array();
$L[] = '# osTicket current state — ' . date('Y-m-d H:i T');
$L[] = '';
$L[] = sprintf('- Host: `%s` · osTicket `%s` (MAJOR_VERSION %s) · table prefix `%s` · DB `%s`',
    php_uname('n'), THIS_VERSION, MAJOR_VERSION, $P, DBNAME);
$L[] = sprintf('- Nested topics + dynamic forms supported (1.10+): **%s**', ht_version_ok() ? 'yes' : 'NO');
$tickets = rows("SELECT COUNT(*) c, SUM(s.state='open') o FROM {$P}ticket t JOIN {$P}ticket_status s ON s.id=t.status_id")[0];
$L[] = sprintf('- Tickets: %d (%d open)', $tickets['c'], $tickets['o']);
$L[] = sprintf('- Help topic sort mode: `%s` (a = alphabetical, m = manual) · default help topic id: `%s`',
    $cfg->getTopicSortMode(), (int) $cfg->getDefaultTopicId());
$tf = TicketForm::objects()->one();
$L[] = sprintf('- Ticket Details form instructions: "%s"', md($tf->get('instructions')));
$L[] = '';

$L[] = '## Departments';
$L[] = '';
$L[] = '| id | name | public | tickets |';
$L[] = '|---|---|---|---|';
foreach (rows("SELECT d.id, d.name, d.ispublic, (SELECT COUNT(*) FROM {$P}ticket t WHERE t.dept_id=d.id) n FROM {$P}department d ORDER BY d.name") as $d)
    $L[] = sprintf('| %d | %s | %s | %d |', $d['id'], md($d['name']), $d['ispublic'] ? 'yes' : 'no', $d['n']);
$L[] = '';
$have = array_map('strtolower', array_column(rows("SELECT name FROM {$P}department"), 'name'));
$L[] = 'Departments required by the new taxonomy:';
foreach (array_merge(ht_required_depts(), array(HT_VENDOR_DEPT)) as $d)
    $L[] = sprintf('- %s — %s', $d, in_array(strtolower($d), $have) ? 'exists' : '**missing** (needs approval to create or a mapping)');
$L[] = '';

$L[] = '## Priorities';
$L[] = '';
$L[] = implode(' · ', array_map(function($p) { return "{$p['priority_id']} = {$p['priority_desc']}"; },
    rows("SELECT priority_id, priority_desc FROM {$P}ticket_priority ORDER BY priority_urgency DESC")));
$L[] = '';

$L[] = '## Help topics';
$L[] = '';
$L[] = '| id | parent | full name | status | public | dept | priority | sort | forms | tickets |';
$L[] = '|---|---|---|---|---|---|---|---|---|---|';
$names = Topic::getHelpTopics(false, true);
foreach (rows("SELECT t.*, d.name dept, p.priority_desc prio,
        (SELECT COUNT(*) FROM {$P}ticket k WHERE k.topic_id=t.topic_id) n,
        (SELECT GROUP_CONCAT(f.title ORDER BY hf.sort SEPARATOR ', ') FROM {$P}help_topic_form hf JOIN {$P}form f ON f.id=hf.form_id WHERE hf.topic_id=t.topic_id) forms
        FROM {$P}help_topic t LEFT JOIN {$P}department d ON d.id=t.dept_id
        LEFT JOIN {$P}ticket_priority p ON p.priority_id=t.priority_id ORDER BY t.sort, t.topic_id") as $t)
    $L[] = sprintf('| %d | %d | %s | %s | %s | %s | %s | %d | %s | %d |', $t['topic_id'], $t['topic_pid'],
        md($names[$t['topic_id']] ?? $t['topic']), ht_topic_status($t['flags']), $t['ispublic'] ? 'yes' : 'no',
        md($t['dept'] ?: '(default)'), md($t['prio'] ?: '(default)'), $t['sort'], md($t['forms']), $t['n']);
$L[] = '';

$L[] = '## Forms';
$L[] = '';
$L[] = '| id | title | type | fields | used by topics |';
$L[] = '|---|---|---|---|---|';
foreach (rows("SELECT f.id, f.title, f.type, (SELECT COUNT(*) FROM {$P}form_field x WHERE x.form_id=f.id) nf,
        (SELECT COUNT(*) FROM {$P}help_topic_form h WHERE h.form_id=f.id) nt FROM {$P}form f ORDER BY f.id") as $f)
    $L[] = sprintf('| %d | %s | %s | %d | %d |', $f['id'], md($f['title']), $f['type'], $f['nf'], $f['nt']);
$L[] = '';

$L[] = '## Ticket filters';
$L[] = '';
$L[] = '| id | name | active | order | target | stop | rules | actions |';
$L[] = '|---|---|---|---|---|---|---|---|';
foreach (rows("SELECT * FROM {$P}filter ORDER BY execorder, id") as $f) {
    $rules = array_map(function($r) { return "{$r['what']} {$r['how']} `{$r['val']}`"; },
        rows("SELECT what, how, val FROM {$P}filter_rule WHERE filter_id=" . (int) $f['id']));
    $acts = array_map(function($a) { return $a['type'] . ($a['configuration'] && $a['configuration'] != '[]' ? ' ' . $a['configuration'] : ''); },
        rows("SELECT type, configuration FROM {$P}filter_action WHERE filter_id=" . (int) $f['id'] . " ORDER BY sort"));
    $L[] = sprintf('| %d | %s | %s | %d | %s | %s | %s | %s |', $f['id'], md($f['name']), $f['isactive'] ? 'yes' : 'no',
        $f['execorder'], $f['target'], $f['stop_onmatch'] ? 'yes' : 'no', md(implode(($f['match_all_rules'] ? ' AND ' : ' OR '), $rules)), md(implode('; ', $acts)));
}
$L[] = '';

$L[] = '## Vendor senders seen in tickets (for §6 filters)';
$L[] = '';
$L[] = '| sender domain | example name | tickets |';
$L[] = '|---|---|---|';
foreach (rows("SELECT SUBSTRING_INDEX(e.address, '@', -1) dom, MAX(u.name) nm, COUNT(DISTINCT t.ticket_id) n
        FROM {$P}ticket t JOIN {$P}user u ON u.id=t.user_id JOIN {$P}user_email e ON e.id=u.default_email_id
        WHERE e.address LIKE '%micron21%' OR u.name LIKE '%Micron21%' OR u.name LIKE '%Microsoft Dynamics%'
           OR e.address LIKE '%microsoft%' OR e.address LIKE '%dynamics%'
        GROUP BY dom ORDER BY n DESC LIMIT 20") as $v)
    $L[] = sprintf('| %s | %s | %d |', md($v['dom']), md($v['nm']), $v['n']);
$L[] = '';

file_put_contents($out, implode("\n", $L) . "\n");
echo "Snapshot written to $out\n";
