<?php
// Tab bar for the IT Approvals admin pages. Set $tab before including.
$tabs = array(
    '' => 'Approval matrix',
    '/formpack' => 'Form pack',
    '/rules' => 'Conditional fields',
    '/reports' => 'Reports',
    '/simulate' => 'Simulator',
);
?>
<style>
.itapp-tabs { display: flex; gap: 4px; border-bottom: 1px solid #ddd; margin: 4px 0 18px; flex-wrap: wrap; }
.itapp-tabs a { padding: 8px 14px; border: 1px solid transparent; border-bottom: 0; border-radius: 4px 4px 0 0; color: #444; }
.itapp-tabs a.active { border-color: #ddd; background: #fff; margin-bottom: -1px; font-weight: bold; color: #000; }
.itapp-tabs a:hover { background: #f5f5f5; }
.itapp-admin .pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; }
.itapp-admin .pill.ok { background: #dcf5e4; color: #11683a; }
.itapp-admin .pill.warn { background: #fff4d6; color: #8a5a00; }
.itapp-admin .pill.off { background: #eee; color: #666; }
.itapp-admin table.list td, .itapp-admin table.list th { vertical-align: top; }
.itapp-admin .muted { color: #777; }
.itapp-admin .kpis { display: flex; gap: 12px; flex-wrap: wrap; margin: 8px 0 18px; }
.itapp-admin .kpi { border: 1px solid #ddd; border-radius: 6px; padding: 10px 16px; min-width: 130px; background: #fafafa; }
.itapp-admin .kpi b { display: block; font-size: 22px; }
.itapp-admin .bar { height: 8px; background: #e6e9ef; border-radius: 4px; overflow: hidden; min-width: 80px; }
.itapp-admin .bar span { display: block; height: 100%; background: #2d6cdf; }
</style>
<h2>IT Approvals</h2>
<div class="itapp-tabs">
<?php foreach ($tabs as $path => $label) { ?>
    <a class="<?php echo $tab === $path ? 'active' : ''; ?>"
       href="<?php echo Format::htmlchars(ITApprovalsAdmin::getUrl($path)); ?>"><?php echo $label; ?></a>
<?php } ?>
</div>
