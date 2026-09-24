<style>
/* Uses the Portal Theme variables when that plugin is active, with fallbacks */
.itapp { --ia-accent: var(--pt-accent, #1f5fbf); --ia-soft: var(--pt-accent-soft, #e9eff9);
  --ia-border: var(--pt-border, #e2e7ef); --ia-muted: var(--pt-muted, #5f6b7d);
  --ia-text: var(--pt-text, #1d2433); --ia-radius: var(--pt-radius-sm, 8px); color: var(--ia-text); }
.itapp h1 { font-size: 26px; margin: 0 0 4px; color: var(--ia-text); }
.itapp .sub { color: var(--ia-muted); margin: 0 0 20px; }
.itapp .back { display: inline-block; margin-bottom: 12px; font-size: 14px; }
.itapp h2 { font-size: 17px; margin: 28px 0 10px; color: var(--ia-text); display: flex; align-items: center; gap: 8px; }
.itapp h2 .count { background: var(--ia-soft); color: var(--ia-accent); border-radius: 999px; padding: 1px 9px; font-size: 13px; }
.itapp .table-wrap { border: 1px solid var(--ia-border); border-radius: var(--ia-radius); overflow-x: auto; }
.itapp table.list { width: 100%; border-collapse: collapse; background: #fff; }
.itapp table.list th, .itapp table.list td { text-align: left; padding: 11px 12px; border-bottom: 1px solid var(--ia-border); vertical-align: top; }
.itapp table.list tr:last-child td { border-bottom: 0; }
.itapp table.list th { background: #f8fafc; color: var(--ia-muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.itapp table.list tbody tr:hover td { background: #f8fafc; }
.itapp table.list small { color: var(--ia-muted); }
.itapp .empty { color: var(--ia-muted); padding: 18px; text-align: center; border: 1px dashed var(--ia-border); border-radius: var(--ia-radius); background: #fbfcfe; }
.itapp .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.itapp .b-pending   { background: #fff4d6; color: #8a5a00; }
.itapp .b-returned  { background: #ffe9dc; color: #9a3d06; }
.itapp .b-approved  { background: #dcf5e4; color: #11683a; }
.itapp .b-rejected  { background: #fde4e3; color: #b42318; }
.itapp .b-cancelled, .itapp .b-skipped { background: #eef1f5; color: #4b5563; }
.itapp .panel { border: 1px solid var(--ia-border); border-radius: var(--ia-radius); padding: 16px 18px; margin: 14px 0; background: #fbfcfe; }
.itapp .panel.stage { border-left: 4px solid var(--ia-accent); background: var(--ia-soft); font-size: 15px; }
.itapp .panel.stage b:first-child { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--ia-accent); margin-bottom: 4px; }
.itapp .progress { display: flex; gap: 6px; margin: 12px 0 2px; }
.itapp .progress span { flex: 1; height: 6px; border-radius: 999px; background: var(--ia-border); }
.itapp .progress span.done { background: #12a150; }
.itapp .progress span.now { background: var(--ia-accent); }
.itapp ol.timeline { list-style: none; padding: 0 0 0 4px; margin: 0; border-left: 2px solid var(--ia-border); }
.itapp ol.timeline li { position: relative; padding: 0 0 16px 20px; }
.itapp ol.timeline li:before { content: ''; position: absolute; left: -8px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #c3cad6; box-shadow: 0 0 0 3px #fff; }
.itapp ol.timeline li.approved:before { background: #12a150; }
.itapp ol.timeline li.rejected:before { background: #d92d20; }
.itapp ol.timeline li.returned:before { background: #e46a14; }
.itapp ol.timeline li.pending:before { background: var(--ia-accent); }
.itapp ol.timeline .when { color: var(--ia-muted); font-size: 13px; }
.itapp ol.timeline .comment { margin: 6px 0 0; padding: 8px 12px; background: #f5f7fa; border-radius: var(--ia-radius); white-space: pre-wrap; }
.itapp textarea { width: 100%; box-sizing: border-box; min-height: 80px; }
.itapp .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.itapp .actions button { margin: 0; }
.itapp .btn-approve { box-shadow: 0 8px 18px -10px #12a150 !important; background: #12a150 !important; border-color: #12a150 !important; color: #fff !important; }
.itapp .btn-approve:hover { background: #0e8543 !important; }
.itapp .btn-reject  { background: #fff !important; color: #b42318 !important; border: 1px solid #f1a7a1 !important; }
.itapp .btn-reject:hover { background: #fef3f2 !important; }
.itapp .btn-return  { background: #fff !important; color: var(--ia-text) !important; border: 1px solid var(--pt-border-strong, #cfd7e3) !important; }
.itapp .btn-return:hover { background: #f8fafc !important; }
.itapp .btn-return, .itapp .btn-reject, .itapp .btn-plain { box-shadow: none !important; }
.itapp .btn-plain { background: #fff !important; color: var(--ia-muted) !important; border: 1px solid var(--pt-border-strong, #cfd7e3) !important; font-weight: 500 !important; }
.itapp .flash-ok  { background: #ecfdf3; border: 1px solid #a6e9c1; color: #05603a; padding: 10px 14px; border-radius: var(--ia-radius); }
.itapp .flash-err { background: #fef3f2; border: 1px solid #fecdca; color: #b42318; padding: 10px 14px; border-radius: var(--ia-radius); }
.itapp .grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(260px, 1fr); gap: 28px; align-items: start; }
.itapp table.list.answers th { width: 38%; color: var(--ia-muted); font-weight: 500; text-transform: none; letter-spacing: 0; font-size: 14px; background: #fff; }
.itapp table.list.answers tr.section th { background: #f8fafc; color: var(--ia-text); font-weight: 600; }
@media (max-width: 820px) { .itapp .grid { grid-template-columns: 1fr; } }
</style>
