<?php
// Vars: $topics, $topicId, $email, $result, $error, $user, $back, $action
$h = function($s) { return Format::htmlchars((string) $s); };
$tab = '/simulate';
include __DIR__ . '/admin-tabs.inc.php';
?>
<div class="itapp-admin">
<h3>Approval matrix simulator</h3>
<p>Shows exactly who would approve a request for this employee, using live Microsoft Graph
lookups (the manager cache is bypassed and refreshed). Nothing is created or sent.</p>

<form method="get" action="<?php echo $h($action); ?>">
<table class="form_table" cellspacing="0" cellpadding="4">
<tr>
    <td>Help Topic</td>
    <td><select name="topic">
        <?php foreach ($topics as $id => $name) { ?>
        <option value="<?php echo (int) $id; ?>" <?php echo $id == $topicId ? 'selected' : ''; ?>><?php
            echo $h($name); ?></option>
        <?php } ?>
    </select></td>
</tr>
<tr>
    <td>Requester email</td>
    <td><input type="email" name="email" size="40" value="<?php echo $h($email); ?>"
        placeholder="employee@company.com" required></td>
</tr>
<tr><td></td><td><input type="submit" class="button" value="Simulate"></td></tr>
</table>
</form>

<?php if ($error) { ?>
<div id="msg_warning"><?php echo $h($error); ?></div>
<?php } ?>

<?php if ($result !== null) { ?>
<h3>Result for <?php echo $h($email); ?>
    <small><?php echo $user ? '(osTicket user: ' . $h($user->getName()) . ')'
        : '<span style="color:#a4262c">(no osTicket user with this email yet)</span>'; ?></small></h3>
<table class="list" width="100%" cellspacing="0" cellpadding="4">
<thead><tr><th width="30">#</th><th>Level</th><th>Type</th><th>Resolved approver(s)</th><th>Routing notes</th></tr></thead>
<tbody>
<?php foreach ($result as $r) { ?>
<tr style="<?php echo $r['skipped'] ? 'color:#888' : ''; ?>">
    <td><?php echo (int) $r['n']; ?></td>
    <td><?php echo $h($r['level']['name']); ?></td>
    <td><?php echo $h(ITApp_Matrix::$level_types[$r['level']['type']] ?? $r['level']['type']); ?></td>
    <td><?php
        $out = array();
        foreach ($r['approvers'] as $e => $n)
            $out[] = $h($n && $n != $e ? "$n <$e>" : $e);
        echo $out ? implode('<br>', $out) : '<span style="color:#a4262c">nobody</span>'; ?></td>
    <td><?php echo $h(implode('; ', $r['notes'])); ?></td>
</tr>
<?php } ?>
</tbody>
</table>
<?php } ?>
</div>
