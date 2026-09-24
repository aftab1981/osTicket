<?php
// Vars: $report, $topics, $levels, $approvers, $overdue, $hours, $action
$h = function($s) { return Format::htmlchars((string) $s); };
$d = function($m) { return ITApprovalsReports::duration($m); };
$tab = '/reports';
include __DIR__ . '/admin-tabs.inc.php';

$tot = array('submitted' => 0, 'approved' => 0, 'rejected' => 0, 'open_now' => 0);
foreach ($topics as $t)
    foreach ($tot as $k => $v)
        $tot[$k] += (int) $t[$k];
$maxAvg = 1;
foreach ($levels as $l)
    $maxAvg = max($maxAvg, (float) $l['avg_min']);
?>
<div class="itapp-admin">
<form method="get" action="<?php echo $h($action); ?>" style="display:flex;gap:10px;align-items:center;margin-bottom:6px">
    <label>Period
    <select name="days" onchange="this.form.submit()">
        <?php foreach (ITApprovalsReports::$periods as $days => $label) { ?>
        <option value="<?php echo $days; ?>" <?php echo $days == $report->getDays() ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
        <?php } ?>
    </select></label>
    <a class="button" href="<?php echo $h($action . '?days=' . $report->getDays() . '&export=csv'); ?>">Export levels (CSV)</a>
</form>

<div class="kpis">
    <div class="kpi"><b><?php echo $tot['submitted']; ?></b>Requests submitted</div>
    <div class="kpi"><b><?php echo $tot['approved']; ?></b>Approved</div>
    <div class="kpi"><b><?php echo $tot['rejected']; ?></b>Rejected</div>
    <div class="kpi"><b><?php echo $tot['open_now']; ?></b>Still open</div>
    <div class="kpi"><b><?php echo count($overdue); ?></b>Waiting &gt; <?php echo $hours; ?> h</div>
</div>

<h3>By request type</h3>
<table class="list" width="100%" cellspacing="0" cellpadding="5">
<thead><tr><th>Request type</th><th>Submitted</th><th>Approved</th><th>Rejected</th><th>Cancelled</th><th>Open</th><th>Returned at least once</th><th>Approval rate</th><th>Avg time to approve</th></tr></thead>
<tbody>
<?php if (!$topics) { ?><tr><td colspan="9"><i>No requests in this period.</i></td></tr><?php } ?>
<?php foreach ($topics as $t) { ?>
<tr>
    <td><?php echo $h($t['topic']); ?></td>
    <td><?php echo (int) $t['submitted']; ?></td>
    <td><?php echo (int) $t['approved']; ?></td>
    <td><?php echo (int) $t['rejected']; ?></td>
    <td><?php echo (int) $t['cancelled']; ?></td>
    <td><?php echo (int) $t['open_now']; ?></td>
    <td><?php echo (int) $t['returned_once']; ?></td>
    <td><?php echo $t['approval_rate'] === null ? '—' : $t['approval_rate'] . '%'; ?></td>
    <td><?php echo $d($t['avg_approve_min']); ?></td>
</tr>
<?php } ?>
</tbody>
</table>

<h3 style="margin-top:22px">By approval level <span class="muted" style="font-weight:normal">— where requests wait</span></h3>
<table class="list" width="100%" cellspacing="0" cellpadding="5">
<thead><tr><th>Request type</th><th>Level</th><th>Decisions</th><th>Approved / Rejected / Returned</th><th>Skipped</th><th>Pending now</th><th width="18%">Avg decision time</th><th>Slowest</th><th>Oldest pending</th></tr></thead>
<tbody>
<?php if (!$levels) { ?><tr><td colspan="9"><i>No approval activity in this period.</i></td></tr><?php } ?>
<?php foreach ($levels as $l) { ?>
<tr>
    <td><?php echo $h($l['topic']); ?></td>
    <td>L<?php echo (int) $l['level']; ?> — <?php echo $h($l['level_name']); ?></td>
    <td><?php echo (int) $l['decisions']; ?></td>
    <td><?php echo (int) $l['approved']; ?> / <?php echo (int) $l['rejected']; ?> / <?php echo (int) $l['returned']; ?></td>
    <td><?php echo (int) $l['skipped']; ?></td>
    <td><?php echo (int) $l['pending_now'] ? '<b>' . (int) $l['pending_now'] . '</b>' : '0'; ?></td>
    <td><div style="display:flex;align-items:center;gap:8px"><div class="bar" style="flex:1"><span style="width:<?php
        echo round(100 * (float) $l['avg_min'] / $maxAvg); ?>%"></span></div><?php echo $d($l['avg_min']); ?></div></td>
    <td><?php echo $d($l['max_min']); ?></td>
    <td><?php echo $d($l['oldest_pending_min']); ?></td>
</tr>
<?php } ?>
</tbody>
</table>

<h3 style="margin-top:22px">By approver</h3>
<table class="list" width="100%" cellspacing="0" cellpadding="5">
<thead><tr><th>Approver</th><th>Decisions</th><th>Approved / Rejected / Returned</th><th>Avg response</th><th>Pending now</th><th>Oldest pending</th></tr></thead>
<tbody>
<?php if (!$approvers) { ?><tr><td colspan="6"><i>No approver activity.</i></td></tr><?php } ?>
<?php foreach ($approvers as $a) { ?>
<tr>
    <td><?php echo $h($a['name'] ?: $a['email']); ?><br><span class="muted"><?php echo $h($a['email']); ?></span></td>
    <td><?php echo (int) $a['decisions']; ?></td>
    <td><?php echo (int) $a['approved']; ?> / <?php echo (int) $a['rejected']; ?> / <?php echo (int) $a['returned']; ?></td>
    <td><?php echo $d($a['avg_min']); ?></td>
    <td><?php echo (int) $a['pending_now'] ? '<b>' . (int) $a['pending_now'] . '</b>' : '0'; ?></td>
    <td><?php echo $d($a['oldest_min']); ?></td>
</tr>
<?php } ?>
</tbody>
</table>

<h3 style="margin-top:22px">Waiting longer than <?php echo $hours; ?> hours</h3>
<table class="list" width="100%" cellspacing="0" cellpadding="5">
<thead><tr><th>Ticket</th><th>Requester</th><th>Stage</th><th>With</th><th>Waiting</th></tr></thead>
<tbody>
<?php if (!$overdue) { ?><tr><td colspan="5"><i>Nothing overdue.</i></td></tr><?php } ?>
<?php foreach ($overdue as $o) { ?>
<tr>
    <td><a href="<?php echo $h(ROOT_PATH . 'scp/tickets.php?id=' . $o['ticket_id']); ?>">#<?php echo $h($o['number']); ?></a> <?php echo $h($o['subject']); ?></td>
    <td><?php echo $h($o['requester_email']); ?></td>
    <td>L<?php echo (int) $o['level']; ?> — <?php echo $h($o['level_name']); ?></td>
    <td><?php echo $h($o['approvers']); ?></td>
    <td><b><?php echo $d($o['age_min']); ?></b></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
