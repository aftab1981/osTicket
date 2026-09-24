<?php
// Vars: $topic, $info, $errors, $depts, $types, $max, $action, $back
$h = function($s) { return Format::htmlchars((string) $s); };
$levels = $info['levels'];
$tab = '';
include __DIR__ . '/admin-tabs.inc.php';
?>
<div class="itapp-admin">
<h3>Approval matrix — <?php echo $h($topic->getFullName()); ?></h3>
<p><a href="<?php echo $h($back); ?>">&larr; All request types</a></p>

<form method="post" action="<?php echo $h($action); ?>">
<?php csrf_token(); ?>
<table class="form_table settings_table" width="100%" cellspacing="0" cellpadding="4">
<tbody>
<tr>
    <td width="200">Workflow</td>
    <td><label><input type="checkbox" name="enabled" value="1" <?php
        echo $info['enabled'] ? 'checked' : ''; ?>> Require approval for tickets on this Help Topic</label></td>
</tr>
<tr>
    <td>Fulfilment department</td>
    <td><select name="fulfil_dept_id">
        <option value="0">— Use the Help Topic's department —</option>
        <?php foreach ($depts as $id => $name) { ?>
        <option value="<?php echo (int) $id; ?>" <?php
            echo $id == $info['fulfil_dept_id'] ? 'selected' : ''; ?>><?php echo $h($name); ?></option>
        <?php } ?>
    </select>
    <span class="error"><?php echo $h($errors['fulfil_dept_id'] ?? ''); ?></span>
    <br><em>Where the ticket goes after the last approval. Help Topic auto-assignment and SLA then apply.</em></td>
</tr>
</tbody>
</table>

<h3>Levels <small>(evaluated in order; leave <i>Type</i> empty to remove a level)</small></h3>
<table class="list" width="100%" cellspacing="0" cellpadding="4">
<thead><tr><th width="30">#</th><th width="20%">Level name</th><th width="20%">Type</th>
    <th>Approver emails <small>(Named approvers only — any one may decide)</small></th>
    <th width="24%">Only when <small>(optional)</small></th></tr></thead>
<tbody>
<?php for ($i = 0; $i < $max; $i++) {
    $L = $levels[$i] ?? array('name' => '', 'type' => '', 'approvers' => array()); ?>
<tr>
    <td><?php echo $i + 1; ?></td>
    <td><input type="text" name="levels[<?php echo $i; ?>][name]" maxlength="128" style="width:95%"
        value="<?php echo $h($L['name']); ?>" placeholder="e.g. Head of IT"></td>
    <td><select name="levels[<?php echo $i; ?>][type]">
        <option value="">—</option>
        <?php foreach ($types as $k => $label) { ?>
        <option value="<?php echo $h($k); ?>" <?php echo $L['type'] == $k ? 'selected' : ''; ?>><?php
            echo $h($label); ?></option>
        <?php } ?>
    </select></td>
    <td><textarea name="levels[<?php echo $i; ?>][approvers]" rows="2" style="width:95%"
        placeholder="it.head@company.com, deputy@company.com"><?php
        echo $h(implode(', ', $L['approvers'] ?? array())); ?></textarea>
        <div class="error"><?php echo $h($errors["level$i"] ?? ''); ?></div></td>
    <td><select name="levels[<?php echo $i; ?>][when]" style="max-width:100%">
        <option value="">— always —</option>
        <?php foreach ($fields as $fname => $flabel) { ?>
        <option value="<?php echo $h($fname); ?>" <?php echo ($L['when'] ?? '') == $fname ? 'selected' : ''; ?>><?php echo $h($flabel); ?></option>
        <?php } ?>
    </select>
    <input type="text" name="levels[<?php echo $i; ?>][in]" style="width:95%;margin-top:4px" placeholder="value(s), e.g. vendor, guest"
        value="<?php echo $h(implode(', ', $L['in'] ?? array())); ?>"></td>
</tr>
<?php } ?>
</tbody>
</table>

<p><em>"Only when": the level applies only if the chosen field has one of the listed values (use the choice <b>key</b>, e.g. <code>vendor</code>); otherwise it is recorded as <i>not required</i> and skipped.</em></p>
<p><em>Line manager types are resolved from Entra ID when the level becomes active. The requester is
never an approver of their own request; empty levels route to the fallback approver. Changes apply to
new requests only — requests already in progress keep the chain they started with.</em></p>

<p class="centered">
    <input type="submit" class="button" value="Save matrix">
    <a class="button" href="<?php echo $h($back); ?>">Cancel</a>
</p>
</form>
</div>
