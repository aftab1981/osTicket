<?php
// Vars: $awaiting, $decided, $requests, $base
include __DIR__ . '/portal-style.inc.php';
$h = function($s) { return Format::htmlchars((string) $s); };
$link = function($R) use ($base) { return $base . '/r/' . $R->id; };
?>
<div class="itapp">
<h1>IT Approvals</h1>
<p class="sub">Requests waiting for your decision, and the status of requests you submitted.</p>

<h2>Awaiting my approval <span class="count"><?php echo count($awaiting); ?></span></h2>
<?php if (!$awaiting) { ?>
    <div class="empty">Nothing is waiting for your approval.</div>
<?php } else { ?>
<div class="table-wrap"><table class="list">
    <tr><th>Ticket</th><th>Request</th><th>Requester</th><th>Stage</th><th>Waiting since</th></tr>
<?php foreach ($awaiting as $row) {
    $R = $row['request']; $S = $row['step']; $T = $row['ticket']; ?>
    <tr>
        <td><a href="<?php echo $h($link($R)); ?>">#<?php echo $h($T->getNumber()); ?></a></td>
        <td><a href="<?php echo $h($link($R)); ?>"><?php echo $h($T->getSubject()); ?></a><br>
            <small><?php echo $h($T->getTopic() ? $T->getTopic()->getFullName() : ''); ?></small></td>
        <td><?php echo $h($T->getOwner()->getName()); ?></td>
        <td>Level <?php echo (int) $S->level; ?> — <?php echo $h($S->level_name); ?></td>
        <td><?php echo $h(Format::datetime($S->created)); ?></td>
    </tr>
<?php } ?>
</table></div>
<?php } ?>

<h2>My requests</h2>
<?php if (!$requests) { ?>
    <div class="empty">You have not submitted any requests that need approval.</div>
<?php } else { ?>
<div class="table-wrap"><table class="list">
    <tr><th>Ticket</th><th>Request</th><th>Status</th><th>Now with</th><th>Submitted</th></tr>
<?php foreach ($requests as $row) {
    $R = $row['request']; $T = $row['ticket']; $S = $row['step']; ?>
    <tr>
        <td><a href="<?php echo $h($link($R)); ?>">#<?php echo $h($T ? $T->getNumber() : ''); ?></a></td>
        <td><?php echo $h($T ? $T->getSubject() : ''); ?></td>
        <td><span class="badge b-<?php echo $h($R->state); ?>"><?php echo $h($R->getStateLabel()); ?></span></td>
        <td><?php
            if ($R->getState() == ITApp_Request::STATE_PENDING && $S)
                echo $h(implode(', ', $S->getApproverNames())) . '<br><small>' . $h($S->level_name) . '</small>';
            elseif ($R->getState() == ITApp_Request::STATE_RETURNED)
                echo 'You — information requested';
            elseif ($R->getState() == ITApp_Request::STATE_APPROVED)
                echo 'IT team' . ($T && $T->getDept() ? ' (' . $h($T->getDept()->getName()) . ')' : '');
            else
                echo '—';
        ?></td>
        <td><?php echo $h(Format::datetime($R->created)); ?></td>
    </tr>
<?php } ?>
</table></div>
<?php } ?>

<h2>Recently decided by me</h2>
<?php if (!$decided) { ?>
    <div class="empty">No decisions yet.</div>
<?php } else { ?>
<div class="table-wrap"><table class="list">
    <tr><th>Ticket</th><th>Request</th><th>Requester</th><th>My decision</th><th>When</th></tr>
<?php foreach ($decided as $row) {
    $R = $row['request']; $S = $row['step']; $T = $row['ticket']; ?>
    <tr>
        <td><a href="<?php echo $h($link($R)); ?>">#<?php echo $h($T ? $T->getNumber() : ''); ?></a></td>
        <td><?php echo $h($T ? $T->getSubject() : ''); ?></td>
        <td><?php echo $h($T ? $T->getOwner()->getName() : ''); ?></td>
        <td><span class="badge b-<?php echo $h($S->decision); ?>"><?php echo $h($S->getDecisionLabel()); ?></span></td>
        <td><?php echo $h(Format::datetime($S->decided)); ?></td>
    </tr>
<?php } ?>
</table></div>
<?php } ?>
</div>
