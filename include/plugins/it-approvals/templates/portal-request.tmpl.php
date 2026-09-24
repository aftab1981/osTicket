<?php
// Vars: $request, $ticket, $current, $steps, $answers, $message, $canDecide,
//       $canResubmit, $canCancel, $isRequester, $action, $base, $flash
include __DIR__ . '/portal-style.inc.php';
$h = function($s) { return Format::htmlchars((string) $s); };
$topic = $ticket->getTopic();
$levels = (int) $request->getNumLevels();
$state = $request->getState();
?>
<div class="itapp">
<a class="back" href="<?php echo $h($base); ?>">&larr; IT Approvals</a>

<?php if (!empty($flash['msg'])) { ?>
    <p class="flash-ok"><?php echo $h($flash['msg']); ?></p>
<?php } elseif (!empty($flash['err'])) { ?>
    <p class="flash-err"><?php echo $h($flash['err']); ?></p>
<?php } ?>

<h1><?php echo $h($ticket->getSubject()); ?></h1>
<p class="sub">#<?php echo $h($ticket->getNumber()); ?>
    &middot; <?php echo $h($topic ? $topic->getFullName() : ''); ?>
    &middot; <span class="badge b-<?php echo $h($request->state); ?>"><?php echo $h($request->getStateLabel()); ?></span></p>

<div class="grid">
<div>
<div class="panel stage">
<?php if ($state == ITApp_Request::STATE_PENDING && $current) { ?>
    <b>Where is it now?</b>
    Level <?php echo (int) $current->level; ?> of <?php echo $levels; ?> — <?php echo $h($current->level_name); ?>,
    waiting for <b><?php echo $h(implode(', ', $current->getApproverNames())); ?></b>
    since <?php echo $h(Format::datetime($current->created)); ?>.
<?php } elseif ($state == ITApp_Request::STATE_RETURNED) { ?>
    <b>Where is it now?</b> Returned to <?php echo $isRequester ? 'you' : 'the requester'; ?> for more information.
<?php } elseif ($state == ITApp_Request::STATE_APPROVED) { ?>
    <b>Approved</b> Passed to the IT team<?php
        echo $ticket->getDept() ? ' (' . $h($ticket->getDept()->getName()) . ')' : ''; ?>.
    Ticket status: <?php echo $h($ticket->getStatus() ? $ticket->getStatus()->getLocalName() : ''); ?>.
<?php } else { ?>
    <b><?php echo $h($request->getStateLabel()); ?></b> No further action.
<?php } ?>
    <div class="progress" aria-hidden="true"><?php
    for ($i = 1; $i <= $levels; $i++) {
        $cls = '';
        if ($state == ITApp_Request::STATE_APPROVED || $i < (int) $request->current_level)
            $cls = 'done';
        elseif ($i == (int) $request->current_level && $request->isOpen())
            $cls = 'now';
        echo '<span class="' . $cls . '"></span>';
    } ?></div>
</div>

<?php if ($canDecide) { ?>
<h2>Your decision</h2>
<form method="post" action="<?php echo $h($action); ?>" class="panel">
    <?php csrf_token(); ?>
    <label for="itapp-comments"><b>Comments</b> <small>(required to reject or return)</small></label>
    <textarea id="itapp-comments" name="comments" maxlength="2000" placeholder="Add a note for the requester and the IT team"></textarea>
    <div class="actions">
        <button type="submit" name="do" value="approve" class="btn-approve">Approve</button>
        <button type="submit" name="do" value="return" class="btn-return">Return for information</button>
        <button type="submit" name="do" value="reject" class="btn-reject"
            onclick="return confirm('Reject this request? This is final.');">Reject</button>
    </div>
</form>
<?php } ?>

<?php if ($canResubmit) { ?>
<h2>Resubmit</h2>
<form method="post" action="<?php echo $h($action); ?>" class="panel">
    <?php csrf_token(); ?>
    <p>Answer the approver's question below, or reply on the
        <a href="<?php echo $h(ROOT_PATH . 'tickets.php?id=' . $ticket->getId()); ?>">ticket</a>
        (you can attach files there). Replying resubmits the request automatically.</p>
    <textarea name="comments" maxlength="2000" placeholder="Information for the approver"></textarea>
    <div class="actions">
        <button type="submit" name="do" value="resubmit" class="btn-approve">Resubmit for approval</button>
    </div>
</form>
<?php } ?>

<h2>Request details</h2>
<div class="table-wrap">
<table class="list answers">
    <tr><th>Requester</th><td><?php echo $h($ticket->getOwner()->getName()); ?>
        <br><small><?php echo $h($ticket->getOwner()->getEmail()); ?></small></td></tr>
    <tr><th>Submitted</th><td><?php echo $h(Format::datetime($request->created)); ?></td></tr>
<?php foreach ($answers as $section) { ?>
    <tr class="section"><th colspan="2"><?php echo $h($section['title']); ?></th></tr>
    <?php foreach ($section['rows'] as $row) { ?>
        <tr><th><?php echo $h($row[0]); ?></th><td><?php echo $row[1]; /* rendered by osTicket field display() */ ?></td></tr>
    <?php } ?>
<?php } ?>
</table>
</div>
<?php if ($message) { ?>
    <div class="panel thread-body"><?php echo $message; /* sanitized by ThreadEntryBody */ ?></div>
<?php } ?>
</div>

<div>
<h2 style="margin-top:0">Timeline</h2>
<ol class="timeline">
    <li class="approved"><b>Submitted</b> by <?php echo $h($ticket->getOwner()->getName()); ?>
        <div class="when"><?php echo $h(Format::datetime($request->created)); ?></div></li>
<?php
$lastCycle = 1;
foreach ($steps as $S) {
    if ($S->cycle != $lastCycle) {
        $lastCycle = $S->cycle; ?>
    <li class="pending"><b>Resubmitted</b> by the requester (round <?php echo (int) $S->cycle; ?>)
        <div class="when"><?php echo $h(Format::datetime($S->created)); ?></div></li>
<?php } ?>
    <li class="<?php echo $h($S->decision); ?>">
        <b>Level <?php echo (int) $S->level; ?> — <?php echo $h($S->level_name); ?></b>
        <span class="badge b-<?php echo $h($S->decision); ?>"><?php echo $h($S->getDecisionLabel()); ?></span>
        <div><?php if ($S->decided_by_name) { ?>by <?php echo $h($S->decided_by_name); ?><?php }
              elseif ($S->isPending()) { ?>with <?php echo $h(implode(', ', $S->getApproverNames())); ?><?php } ?></div>
        <div class="when"><?php echo $h(Format::datetime($S->decided ?: $S->created)); ?></div>
        <?php if ($S->comments) { ?><div class="comment"><?php echo $h($S->comments); ?></div><?php } ?>
    </li>
<?php } ?>
<?php if ($state == ITApp_Request::STATE_APPROVED) { ?>
    <li class="approved"><b>Released to IT</b> for fulfilment
        <div class="when"><?php echo $h(Format::datetime($request->closed)); ?></div></li>
<?php } elseif ($state == ITApp_Request::STATE_CANCELLED) { ?>
    <li class="rejected"><b>Cancelled</b> by the requester
        <div class="when"><?php echo $h(Format::datetime($request->closed)); ?></div></li>
<?php } ?>
</ol>

<?php if ($canCancel) { ?>
<form method="post" action="<?php echo $h($action); ?>" style="margin-top:12px">
    <?php csrf_token(); ?>
    <input type="hidden" name="comments" value="">
    <button type="submit" name="do" value="cancel" class="btn-plain"
        onclick="return confirm('Withdraw this request?');">Withdraw request</button>
</form>
<?php } ?>
</div>
</div>
</div>
