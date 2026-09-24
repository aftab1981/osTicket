<?php
/**
 * Rebuild Help Topics as Category → Sub-category (requirement §3–§7).
 *
 *   php ops/helptopics/seed_helptopics.php [options]
 *
 *   (no option)                 DRY RUN: does everything inside a transaction, prints the
 *                               plan, then ROLLS BACK. Nothing is changed.
 *   --apply                     Do it for real (single transaction; any error → rollback).
 *   --map="IT Support=Support"  Use an existing department for a required one (repeatable).
 *   --create-depts              Create required departments that don't exist (needs approval!).
 *   --create-vendor-dept        Create "Vendor Notifications" (non-public) if missing (needs approval!).
 *   --skip-filters              Don't create the vendor/system mail filters (§6).
 *   --micron21-domain=x.com.au  Also match this exact Micron21 sender domain.
 *
 * Idempotent: objects are found by name; re-runs only add what is missing and
 * re-assert the managed properties (parent, department, priority, public,
 * active, order). Every change is written to <prefix>helptopics_seed_log so
 * rollback_helptopics.sql can undo it. Nothing is ever deleted here.
 */
require __DIR__ . '/lib.php';

/* ---- options ---------------------------------------------------------- */
$opt = array('apply' => false, 'map' => array(), 'create-depts' => false,
    'create-vendor-dept' => false, 'skip-filters' => false, 'micron21-domain' => '');
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--map=(.+?)=(.+)$/', $a, $m))
        $opt['map'][strtolower(trim($m[1]))] = trim($m[2]);
    elseif (preg_match('/^--micron21-domain=(.+)$/', $a, $m))
        $opt['micron21-domain'] = strtolower(trim($m[1]));
    elseif (preg_match('/^--(apply|create-depts|create-vendor-dept|skip-filters)$/', $a, $m))
        $opt[$m[1]] = true;
    else
        die("Unknown option: $a\n");
}
$APPLY = $opt['apply'];
$P = TABLE_PREFIX;
$RUN = date('YmdHis');
$plan = array();
function plan($what, $detail) { global $plan; $plan[] = array($what, $detail); printf("  %-9s %s\n", $what, $detail); }
function q1($sql) { $r = db_query($sql); return $r ? db_fetch_array($r) : null; }

echo ($APPLY ? "APPLY" : "DRY RUN (no changes will be kept)") . " — osTicket " . THIS_VERSION . ", prefix $P\n\n";
if (!ht_version_ok())
    die("osTicket " . MAJOR_VERSION . " is older than 1.10: nested topics/dynamic forms unsupported. Stop.\n");

/* ---- departments (ask before creating) ------------------------------- */
$deptIds = array();
$toCreate = array();
$missing = array();
foreach (ht_required_depts() as $name) {
    $use = $opt['map'][strtolower($name)] ?? $name;
    if ($id = Dept::getIdByName($use))
        $deptIds[$name] = $id;
    elseif ($opt['create-depts'] && !isset($opt['map'][strtolower($name)]))
        $toCreate[] = $name;
    else
        $missing[] = $use == $name ? $name : "$name (mapped to \"$use\", not found)";
}
$vendorDept = Dept::getIdByName(HT_VENDOR_DEPT);
$wantFilters = !$opt['skip-filters'];
if ($wantFilters && !$vendorDept && !$opt['create-vendor-dept'])
    $missing[] = HT_VENDOR_DEPT . ' (for the vendor mail filters; or use --skip-filters)';
if ($missing) {
    echo "Departments needed but not available — nothing has been changed:\n";
    foreach ($missing as $m)
        echo "  - $m\n";
    echo "\nExisting departments:\n";
    foreach (Dept::getDepartments() as $id => $n)
        echo "  $id  $n\n";
    echo "\nGet approval, then re-run with --map=\"Required=Existing\" and/or --create-depts / --create-vendor-dept.\n";
    exit(2);
}

/* ---- log table (temporary during a dry run) ---------------------------- */
$logDDL = "TABLE IF NOT EXISTS `{$P}helptopics_seed_log` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `run_id` varchar(20) NOT NULL,
    `object_type` varchar(24) NOT NULL,
    `object_id` int unsigned NOT NULL DEFAULT 0,
    `action` varchar(16) NOT NULL,
    `old_name` varchar(255) DEFAULT NULL,
    `old_flags` int unsigned DEFAULT NULL,
    `old_sort` int unsigned DEFAULT NULL,
    `old_value` text,
    `created` datetime NOT NULL,
    PRIMARY KEY (`id`), KEY `obj` (`object_type`, `object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";
db_query(($APPLY ? 'CREATE ' : 'CREATE TEMPORARY ') . $logDDL);   // DDL before the transaction

function hlog($type, $id, $action, $oldName=null, $oldFlags=null, $oldSort=null, $oldValue=null) {
    global $P, $RUN;
    db_query("INSERT INTO `{$P}helptopics_seed_log` SET run_id=" . db_input($RUN)
        . ", object_type=" . db_input($type) . ", object_id=" . (int) $id . ", action=" . db_input($action)
        . ", old_name=" . ($oldName === null ? 'NULL' : db_input($oldName))
        . ", old_flags=" . ($oldFlags === null ? 'NULL' : (int) $oldFlags)
        . ", old_sort=" . ($oldSort === null ? 'NULL' : (int) $oldSort)
        . ", old_value=" . ($oldValue === null ? 'NULL' : db_input($oldValue)) . ", created=NOW()");
}
function managed($type, $id) {
    global $P;
    return (bool) q1("SELECT 1 FROM `{$P}helptopics_seed_log` WHERE object_type=" . db_input($type)
        . " AND object_id=" . (int) $id . " AND action IN ('created','adopted') LIMIT 1");
}

// Original name/flags/sort of every topic BEFORE anything changes: creating
// topics makes osTicket renumber the sort order of all topics.
$ORIG = array();
if ($res = db_query("SELECT topic_id, topic, flags, sort FROM {$P}help_topic"))
    while ($r = db_fetch_array($res))
        $ORIG[(int) $r['topic_id']] = $r;

db_query('START TRANSACTION');
try {
    /* ---- create approved departments ------------------------------------ */
    $mkDept = function($name, $public) {
        $d = Dept::create();
        $e = array();
        if (!$d->update(array('name' => $name, 'ispublic' => $public ? 1 : 0, 'status' => 'active',
                'group_membership' => $public ? Dept::ALERTS_DEPT_ONLY : Dept::ALERTS_DISABLED,
                'signature' => '', 'pid' => 0, 'sla_id' => 0, 'manager_id' => 0, 'email_id' => 0,
                'tpl_id' => 0, 'autoresp_email_id' => 0), $e))
            throw new Exception("Unable to create department $name: " . implode('; ', $e));
        hlog('dept', $d->getId(), 'created');
        plan('CREATE', "department \"$name\"" . ($public ? '' : ' (non-public, no alerts)'));
        return $d->getId();
    };
    foreach ($toCreate as $name)
        $deptIds[$name] = $mkDept($name, true);
    if ($wantFilters && !$vendorDept)
        $vendorDept = $mkDept(HT_VENDOR_DEPT, false);

    /* ---- lookups ------------------------------------------------------------ */
    $prio = array();
    foreach (Priority::objects() as $p)
        $prio[strtolower($p->getDesc())] = $p->getId();
    foreach (array('low', 'normal', 'high', 'emergency') as $p)
        if (!isset($prio[$p])) throw new Exception("Priority \"$p\" not found");
    $ticketForm = TicketForm::objects()->one();
    $subjectField = $ticketForm->getField('subject');
    $view = DynamicFormField::FLAG_ENABLED | DynamicFormField::FLAG_CLIENT_VIEW
        | DynamicFormField::FLAG_CLIENT_EDIT | DynamicFormField::FLAG_AGENT_VIEW | DynamicFormField::FLAG_AGENT_EDIT;
    $req = DynamicFormField::FLAG_CLIENT_REQUIRED | DynamicFormField::FLAG_AGENT_REQUIRED;

    /* ---- manual topic order FIRST: in alphabetical mode Topic::update()
     *      re-sorts every topic alphabetically on each save --------------- */
    if (($old = $cfg->getTopicSortMode()) != Topic::SORT_MANUAL) {
        hlog('setting', 0, 'changed', 'help_topic_sort_mode', null, null, (string) $old);
        $cfg->update('help_topic_sort_mode', Topic::SORT_MANUAL);
        plan('SETTING', "help_topic_sort_mode: \"$old\" → \"" . Topic::SORT_MANUAL . "\" (manual order)");
    }

    /* ---- forms ------------------------------------------------------------- */
    $ensureForm = function($title, array $fields) use ($view, $req) {
        $form = DynamicForm::objects()->filter(array('title' => $title))->first();
        $isNew = !$form;
        if ($isNew) {
            $form = DynamicForm::create(array('title' => $title, 'type' => 'G', 'instructions' => '',
                'notes' => 'Managed by ops/helptopics/seed_helptopics.php'));
            if (!$form->save(true))
                throw new Exception("Unable to create form $title");
            hlog('form', $form->id, 'created');
            plan('CREATE', "form \"$title\" (" . count($fields) . " fields)");
        }
        $have = array();
        foreach ($form->getDynamicFields() as $f)
            $have[$f->get('name')] = true;
        foreach ($fields as $i => $f) {
            list($type, $label, $name, $required, $config) = $f;
            if (isset($have[$name]))
                continue;
            $field = DynamicFormField::create(array('form_id' => $form->id, 'type' => $type,
                'label' => $label, 'name' => $name, 'flags' => $view | ($required ? $req : 0),
                'sort' => $i + 1, 'configuration' => $config));
            if (!$field->save())
                throw new Exception("Unable to add field $name to $title");
            hlog('field', $field->get('id'), 'created');
            if (!$isNew)
                plan('ADD', "field \"$label\" to form \"$title\"");
        }
        return (int) $form->id;
    };
    $sharedIds = array();
    foreach (ht_shared_forms() as $title => $fields)
        $sharedIds[$title] = $ensureForm($title, $fields);

    /* ---- topics -------------------------------------------------------------- */
    $sort = 0;
    $touched = array();
    $ensureTopic = function($name, $pid, $deptId, $prioId) use (&$sort, &$touched) {
        $sort++;
        $id = Topic::getIdByName($name, $pid);
        if (!$id) {
            $t = Topic::create();
            $e = array();
            $t->update(array('topic' => $name, 'topic_pid' => $pid, 'dept_id' => $deptId,
                'priority_id' => $prioId, 'ispublic' => 1, 'status' => 'active', 'sla_id' => 0,
                'notes' => 'Managed by ops/helptopics/seed_helptopics.php', 'assign' => 0,
                'number_format' => '', 'sequence_id' => 0), $e);
            if ($e || !$t->getId())
                throw new Exception("Unable to create topic $name: " . implode('; ', $e));
            hlog('topic', $t->getId(), 'created');
            plan('CREATE', 'topic "' . ($pid ? Topic::lookup($pid)->topic . ' / ' : '') . "$name\"");
        }
        else {
            $t = Topic::lookup($id);
            if (!managed('topic', $id)) {
                $o = $GLOBALS['ORIG'][$id] ?? array('topic' => $t->topic, 'flags' => $t->flags, 'sort' => $t->sort);
                hlog('topic', $id, 'adopted', $o['topic'], $o['flags'], $o['sort'],
                    // "dept_id,priority_id,ispublic" (plain CSV: rollback SQL avoids JSON functions)
                    implode(',', array((int) $t->dept_id, (int) $t->priority_id, (int) $t->ispublic)));
                plan('ADOPT', "existing topic \"$name\" (id $id)");
            }
        }
        // Re-assert only the managed properties (keeps later UI tweaks such as SLA/auto-assign)
        $t->topic_pid = $pid;
        $t->dept_id = $deptId;
        $t->priority_id = $prioId;
        $t->ispublic = 1;
        $t->setFlag(Topic::FLAG_ACTIVE, true);
        $t->setFlag(Topic::FLAG_ARCHIVED, false);
        $t->sort = $sort;
        $t->save();
        $touched[$t->getId()] = true;
        return $t;
    };

    foreach (ht_taxonomy() as $cat) {
        list($catName, $deptName, $children) = $cat;
        $deptId = $deptIds[$deptName];
        $parent = $ensureTopic($catName, 0, $deptId, $prio['normal']);
        $parent->updateForms(array('forms' => array($ticketForm->getId())), $e);
        foreach ($children as $child) {
            list($name, $priority, $shared, $own) = $child;
            $t = $ensureTopic($name, $parent->getId(), $deptId, $prio[strtolower($priority)]);
            $forms = array($ticketForm->getId());
            foreach ($shared as $s)
                $forms[] = $sharedIds[$s];
            if ($own)
                $forms[] = $ensureForm(HT_FORM_PREFIX . $name, $own);
            $e = array();
            $t->updateForms(array('forms' => $forms), $e);
        }
    }

    /* ---- legacy topics: rename + disable, never delete ------------------------ */
    $legacySort = 1000;
    foreach (Topic::objects()->order_by('sort') as $t) {
        if (isset($touched[$t->getId()]) || managed('topic', $t->getId()))
            continue;
        $active = ($t->flags & Topic::FLAG_ACTIVE);
        $named = preg_match('/\(Legacy\)$/', $t->topic);
        if (!$active && $named)
            continue;
        $o = $ORIG[$t->getId()] ?? array('topic' => $t->topic, 'flags' => $t->flags, 'sort' => $t->sort);
        hlog('topic', $t->getId(), 'legacy', $o['topic'], $o['flags'], $o['sort']);
        if (!$named)
            $t->topic = mb_substr($t->topic, 0, 118) . ' (Legacy)';
        $t->setFlag(Topic::FLAG_ACTIVE, false);
        $t->setFlag(Topic::FLAG_ARCHIVED, false);
        $t->sort = $legacySort++;
        $t->save();
        plan('LEGACY', "topic id {$t->getId()} → \"{$t->topic}\" (disabled)");
    }

    /* ---- settings (§7) and HR notice (§4.6) ----------------------------------- */
    foreach (array('default_help_topic' => 0) as $k => $v) {
        $old = $cfg->get($k);
        if ((string) $old !== (string) $v) {
            hlog('setting', 0, 'changed', $k, null, null, (string) $old);
            $cfg->update($k, $v);
            plan('SETTING', "$k: \"$old\" → \"$v\"");
        }
    }
    $instr = (string) $ticketForm->get('instructions');
    if (strpos($instr, HT_HR_NOTICE) === false) {
        hlog('form_instructions', $ticketForm->getId(), 'changed', null, null, null, $instr);
        $ticketForm->set('instructions', 'Please describe your issue. ' . HT_HR_NOTICE);
        $ticketForm->save();
        plan('SETTING', 'Ticket Details instructions → "Please describe your issue. ' . HT_HR_NOTICE . '"');
    }

    /* ---- vendor / system mail filters (§6) — raw SQL: Filter::update() needs the admin form layout */
    if ($wantFilters) {
        foreach (ht_filters($opt['micron21-domain']) as $F) {
            if ($row = q1("SELECT id FROM {$P}filter WHERE name=" . db_input($F['name']))) {
                plan('KEEP', "filter \"{$F['name']}\" already exists (id {$row['id']}) — not modified");
                continue;
            }
            db_query("INSERT INTO {$P}filter SET execorder=" . (int) $F['order'] . ", isactive=1, flags=0, status=0,
                match_all_rules=0, stop_onmatch=1, target='Any', email_id=0, name=" . db_input($F['name']) . ",
                notes='Managed by ops/helptopics/seed_helptopics.php', created=NOW(), updated=NOW()");
            $fid = db_insert_id();
            if (!$fid) throw new Exception("Unable to create filter {$F['name']}");
            foreach ($F['rules'] as $r) {
                $what = $r[0] == 'subject' ? 'field.' . $subjectField->get('id') : $r[0];
                db_query("INSERT INTO {$P}filter_rule SET filter_id=$fid, what=" . db_input($what) . ", how=" . db_input($r[1])
                    . ", val=" . db_input($r[2]) . ", isactive=1, notes='', created=NOW(), updated=NOW()");
            }
            $n = 1;
            db_query("INSERT INTO {$P}filter_action SET filter_id=$fid, sort=" . $n++ . ", type='dept', configuration="
                . db_input(json_encode(array('dept_id' => (int) $vendorDept))) . ", updated=NOW()");
            if ($F['priority'])
                db_query("INSERT INTO {$P}filter_action SET filter_id=$fid, sort=" . $n++ . ", type='pri', configuration="
                    . db_input(json_encode(array('priority' => (int) $prio[strtolower($F['priority'])]))) . ", updated=NOW()");
            if ($F['noresp'])
                db_query("INSERT INTO {$P}filter_action SET filter_id=$fid, sort=" . $n++ . ", type='noresp', configuration='[]', updated=NOW()");
            hlog('filter', $fid, 'created');
            plan('CREATE', "filter \"{$F['name']}\" (order {$F['order']}, stop on match) → " . HT_VENDOR_DEPT);
        }
        // Vendor mail must be routed before the Internal Access domain filter rejects it
        if ($row = q1("SELECT id, execorder FROM {$P}filter WHERE name='Internal domains only'")) {
            if ((int) $row['execorder'] < 90) {
                hlog('filter_order', $row['id'], 'changed', null, null, null, (string) $row['execorder']);
                db_query("UPDATE {$P}filter SET execorder=99 WHERE id=" . (int) $row['id']);
                plan('SETTING', 'filter "Internal domains only" execution order ' . $row['execorder'] . ' → 99 (after vendor filters)');
            }
        }
    }

    if ($APPLY) {
        db_query('COMMIT');
        echo "\nCommitted. Run id $RUN. Undo with rollback_helptopics.sql.\n";
    } else {
        db_query('ROLLBACK');
        echo "\nDry run complete: " . count($plan) . " change(s) planned and rolled back. Re-run with --apply.\n";
    }
}
catch (Throwable $e) {
    db_query('ROLLBACK');
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\nAll changes rolled back.\n");
    exit(1);
}
