<?php
// Vars: $rules, $forms, $action
$h = function($s) { return Format::htmlchars((string) $s); };
$tab = '/rules';
include __DIR__ . '/admin-tabs.inc.php';
?>
<div class="itapp-admin">
<p>osTicket forms cannot show or hide fields on their own. A rule here shows a field only
when another field of the same form has one of the given values, and can make it
<b>required while visible</b>. The portal enforces rules as the user fills the form; on
submission the request is checked again and, if a required field is empty, it is returned to
the requester automatically before any approver sees it.</p>
<p class="muted">Use the choice <b>key</b> as the value (the part before the colon in the
field's choices, e.g. <code>temp</code> for "temp:Temporary").</p>

<table class="list" width="100%" cellspacing="0" cellpadding="6">
<thead><tr><th>Form</th><th>Show field</th><th>When</th><th>Is one of</th><th>Required when shown</th><th></th></tr></thead>
<tbody>
<?php $n = 0; foreach ($rules as $R) { $n++;
    $form = $forms[$R->form_id] ?? null; ?>
<tr>
    <td><?php echo $h($form ? $form['title'] : '#' . $R->form_id); ?></td>
    <td><?php echo $h($form['fields'][$R->field_name] ?? $R->field_name); ?> <span class="muted">(<?php echo $h($R->field_name); ?>)</span></td>
    <td><?php echo $h($form['fields'][$R->depends_on] ?? $R->depends_on); ?></td>
    <td><?php echo $h(implode(', ', $R->getValues())); ?></td>
    <td><?php echo $R->isRequired() ? '<span class="pill ok">Yes</span>' : '<span class="pill off">No</span>'; ?></td>
    <td><form method="post" action="<?php echo $h($action); ?>" onsubmit="return confirm('Delete this rule?');">
        <?php csrf_token(); ?><input type="hidden" name="delete" value="<?php echo (int) $R->id; ?>">
        <input type="submit" class="button" value="Delete"></form></td>
</tr>
<?php } if (!$n) { ?>
<tr><td colspan="6"><i>No rules yet. Installing a form from the Form pack adds its rules.</i></td></tr>
<?php } ?>
</tbody>
</table>

<h3 style="margin-top:24px">Add or update a rule</h3>
<form method="post" action="<?php echo $h($action); ?>">
<?php csrf_token(); ?>
<table class="form_table" cellspacing="0" cellpadding="4">
<tr><td width="160">Form</td><td>
    <select name="form_id" id="itapp-rule-form" required>
        <option value="">— Select —</option>
        <?php foreach ($forms as $id => $f) { ?>
        <option value="<?php echo (int) $id; ?>"><?php echo $h($f['title']); ?></option>
        <?php } ?>
    </select></td></tr>
<tr><td>Show field</td><td><select name="field_name" class="itapp-field" required></select></td></tr>
<tr><td>When field</td><td><select name="depends_on" class="itapp-field" required></select></td></tr>
<tr><td>Is one of</td><td><input type="text" name="values" size="40" placeholder="e.g. vendor, guest" required></td></tr>
<tr><td>Required when shown</td><td><label><input type="checkbox" name="required" value="1" checked> Yes</label></td></tr>
<tr><td></td><td><input type="submit" class="button" value="Save rule"></td></tr>
</table>
</form>
<script>
(function() {
    var forms = <?php echo json_encode(array_map(function($f) { return $f['fields']; }, $forms)); ?>;
    var sel = document.getElementById('itapp-rule-form');
    sel.addEventListener('change', function() {
        var fields = forms[sel.value] || {};
        document.querySelectorAll('.itapp-field').forEach(function(s) {
            s.innerHTML = '';
            Object.keys(fields).forEach(function(k) {
                var o = document.createElement('option');
                o.value = k; o.textContent = fields[k] + ' (' + k + ')';
                s.appendChild(o);
            });
        });
    });
})();
</script>
</div>
