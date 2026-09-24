<?php
// Vars: $status, $depts, $holdDept, $fallback, $action
$h = function($s) { return Format::htmlchars((string) $s); };
$tab = '/formpack';
include __DIR__ . '/admin-tabs.inc.php';
?>
<div class="itapp-admin">
<p>Ready-made IT request forms. Installing one creates the <b>custom form</b>, a public
<b>Help Topic</b>, its <b>conditional-field rules</b> and a starting <b>approval matrix</b>.
Existing forms and topics are kept, so installing twice is safe. Named approver levels start
with the fallback approver (<b><?php echo $h($fallback ?: 'not set'); ?></b>) — replace them
in the matrix before go-live.</p>

<table class="list" width="100%" cellspacing="0" cellpadding="6">
<thead><tr><th width="22%">Form</th><th>Fields &amp; routing</th><th width="12%">Status</th><th width="28%">Install</th></tr></thead>
<tbody>
<?php foreach ($status as $key => $s) {
    $def = $s['def']; ?>
<tr>
    <td><b><?php echo $h($def['title']); ?></b><br><span class="muted"><?php echo $h($def['instructions']); ?></span></td>
    <td>
        <?php echo count($def['fields']); ?> fields,
        <?php echo count($def['rules']); ?> conditional<br>
        <span class="muted">Approvals:</span>
        <?php
        $names = array();
        foreach ($def['levels'] as $L)
            $names[] = $L['name'] . (!empty($L['when']) ? ' <i>(if ' . $h($L['when']) . ' = ' . $h(implode('/', $L['in'])) . ')</i>' : '');
        echo implode(' &rarr; ', array_map(function($n) { return $n; }, $names)); ?>
    </td>
    <td><?php if ($s['installed']) { ?>
            <span class="pill ok">Installed</span>
            <?php if ($s['matrix']) { ?><br><a href="<?php echo $h(ITApprovalsAdmin::getUrl('/topic/' . $s['topic_id'])); ?>">Edit approvers</a><?php } ?>
        <?php } else { ?>
            <span class="pill off">Not installed</span>
        <?php } ?></td>
    <td>
        <form method="post" action="<?php echo $h($action); ?>">
            <?php csrf_token(); ?>
            <input type="hidden" name="form" value="<?php echo $h($key); ?>">
            <select name="dept_id" required>
                <option value="">— Fulfilment department —</option>
                <?php foreach ($depts as $id => $name) {
                    if ($id == $holdDept) continue; ?>
                <option value="<?php echo (int) $id; ?>"><?php echo $h($name); ?></option>
                <?php } ?>
            </select>
            <input type="submit" class="button" value="<?php echo $s['installed'] ? 'Repair / complete' : 'Install'; ?>">
        </form>
    </td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
