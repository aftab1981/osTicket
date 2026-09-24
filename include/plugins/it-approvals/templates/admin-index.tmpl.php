<?php
// Vars: $topics, $recent, $health, $depts
$h = function($s) { return Format::htmlchars((string) $s); };
$tab = '';
include __DIR__ . '/admin-tabs.inc.php';
?>
<div class="itapp-admin">
<p>Approval routing per Help Topic. Tickets for enabled topics wait in the approval hold
department until every level approves, then are released to the fulfilment department.
<a href="<?php echo $h(ITApprovalsAdmin::getUrl('/simulate')); ?>"><b>Open the simulator</b></a>
to check the chain for a real employee.</p>

<table class="list" width="100%" cellspacing="0" cellpadding="4">
<caption style="text-align:left"><b>Configuration health</b>
    — plugin settings are under Admin Panel &rsaquo; Manage &rsaquo; Plugins &rsaquo; IT Approvals</caption>
<?php foreach ($health as $check) { list($label, $ok, $detail) = $check; ?>
<tr>
    <td width="30%"><?php echo $h($label); ?></td>
    <td width="20" style="color:<?php echo $ok ? '#107c10' : '#a4262c'; ?>;font-weight:bold">
        <?php echo $ok ? '&#10003;' : '&#10007;'; ?></td>
    <td><?php echo $h($detail); ?></td>
</tr>
<?php } ?>
</table>

<h3 style="margin-top:24px">Approval matrix</h3>
<table class="list" width="100%" cellspacing="0" cellpadding="4">
<thead><tr>
    <th>Help Topic</th><th>Workflow</th><th>Levels</th><th>Fulfilment department</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($topics as $T) {
    $M = $T['matrix']; ?>
<tr>
    <td><?php echo $h($T['name']); ?></td>
    <td><?php if (!$M) echo '<span style="color:#888">Not configured</span>';
        elseif ($M->isEnabled() && $M->getLevels()) echo '<b style="color:#107c10">Enabled</b>';
        else echo '<span style="color:#888">Disabled</span>'; ?></td>
    <td><?php
        if ($M) {
            $names = array();
            foreach ($M->getLevels() as $i => $L)
                $names[] = ($i + 1) . '. ' . $L['name'];
            echo $h(implode('  →  ', $names));
        } ?></td>
    <td><?php echo $M && $M->fulfil_dept_id
        ? $h($depts[$M->fulfil_dept_id] ?? '#' . $M->fulfil_dept_id)
        : ($M ? '<span style="color:#888">Help Topic department</span>' : ''); ?></td>
    <td><a class="button" href="<?php echo $h(ITApprovalsAdmin::getUrl('/topic/' . $T['id'])); ?>">
        <?php echo $M ? 'Edit' : 'Configure'; ?></a></td>
</tr>
<?php } ?>
</tbody>
</table>

<h3 style="margin-top:24px">Recent requests</h3>
<table class="list" width="100%" cellspacing="0" cellpadding="4">
<thead><tr><th>Ticket</th><th>Requester</th><th>State</th><th>Current stage</th><th>Updated</th></tr></thead>
<tbody>
<?php if (!$recent) { ?>
<tr><td colspan="5"><i>No requests yet.</i></td></tr>
<?php }
foreach ($recent as $row) {
    $R = $row['request']; $T = $row['ticket']; $S = $row['step']; ?>
<tr>
    <td><?php if ($T) { ?><a href="<?php echo $h(ROOT_PATH . 'scp/tickets.php?id=' . $T->getId()); ?>">#<?php
        echo $h($T->getNumber()); ?></a> <?php echo $h($T->getSubject()); } ?></td>
    <td><?php echo $h($R->requester_email); ?></td>
    <td><?php echo $h($R->getStateLabel()); ?></td>
    <td><?php echo $S ? $h(sprintf('L%d %s — %s', $S->level, $S->level_name,
        implode(', ', $S->getApproverNames()))) : ''; ?></td>
    <td><?php echo $h(Format::datetime($R->updated)); ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
