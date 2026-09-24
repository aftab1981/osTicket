<?php
/**
 * SCP admin pages: approval matrix editor, simulator and recent activity.
 *
 *   GET  /scp/ajax.php/itapprovals/admin              overview
 *   GET  /scp/ajax.php/itapprovals/admin/topic/{id}   edit matrix
 *   POST /scp/ajax.php/itapprovals/admin/topic/{id}   save matrix
 *   GET  /scp/ajax.php/itapprovals/admin/simulate     simulator
 *
 * Admin-only. CSRF is enforced by osTicket's staff.inc.php for all POSTs.
 */
class ITApprovalsAdmin {

    private $engine;

    function __construct(ITApprovalsEngine $engine) {
        $this->engine = $engine;
    }

    static function getUrl($path='') {
        return ROOT_PATH . 'scp/ajax.php/itapprovals/admin' . $path;
    }

    function registerUrls($dispatcher) {
        $dispatcher->append(url('^/itapprovals/admin', patterns('',
            url_get('^/?$', array($this, 'index')),
            url_get('^/topic/(?P<id>\d+)$', array($this, 'edit')),
            url_post('^/topic/(?P<id>\d+)$', array($this, 'save')),
            url_get('^/simulate$', array($this, 'simulate')),
            url_get('^/formpack$', array($this, 'formpack')),
            url_post('^/formpack$', array($this, 'installPack')),
            url_get('^/rules$', array($this, 'rules')),
            url_post('^/rules$', array($this, 'saveRule')),
            url_get('^/reports$', array($this, 'reports'))
        )));
    }

    /* Pages ---------------------------------------------------------- */

    function index() {
        $this->requireAdmin();

        $matrices = array();
        foreach (ITApp_Matrix::objects() as $M)
            $matrices[$M->topic_id] = $M;

        $topics = array();
        foreach (Topic::getHelpTopics(false, true) as $id => $name)
            $topics[] = array('id' => $id, 'name' => $name,
                'matrix' => $matrices[$id] ?? null);

        $recent = array();
        foreach (ITApp_Request::objects()->order_by('-id')->limit(25) as $R)
            $recent[] = array('request' => $R, 'ticket' => $R->getTicket(),
                'step' => $R->getCurrentStep());

        $this->render('admin-index.tmpl.php', array(
            'topics' => $topics,
            'recent' => $recent,
            'health' => $this->healthChecks(),
            'depts' => Dept::getDepartments(),
        ));
    }

    function edit($id, $errors=array(), $posted=null) {
        $this->requireAdmin();
        if (!($topic = Topic::lookup((int) $id)))
            Http::response(404, 'Unknown help topic');

        $matrix = ITApp_Matrix::forTopic($topic->getId());
        if ($posted) {
            $info = $posted;
        }
        else {
            $info = array(
                'enabled' => $matrix ? $matrix->isEnabled() : true,
                'fulfil_dept_id' => $matrix ? $matrix->fulfil_dept_id : 0,
                'levels' => $matrix ? $matrix->getLevels() : array(
                    array('name' => 'Line Manager', 'type' => 'manager', 'approvers' => array()),
                ),
            );
        }

        $this->render('admin-matrix.tmpl.php', array(
            'topic' => $topic,
            'info' => $info,
            'errors' => $errors,
            'depts' => Dept::getDepartments(),
            'fields' => self::topicFields($topic),
            'types' => ITApp_Matrix::$level_types,
            'max' => ITApp_Matrix::MAX_LEVELS,
            'action' => self::getUrl('/topic/' . $topic->getId()),
            'back' => self::getUrl(),
        ));
    }

    function save($id) {
        $this->requireAdmin();
        if (!($topic = Topic::lookup((int) $id)))
            Http::response(404, 'Unknown help topic');

        $errors = array();
        $levels = array();
        $rows = $_POST['levels'] ?? array();
        for ($i = 0; $i < ITApp_Matrix::MAX_LEVELS; $i++) {
            $row = $rows[$i] ?? array();
            $type = $row['type'] ?? '';
            $name = trim(Format::striptags((string) ($row['name'] ?? '')));
            if (!$type)
                continue;
            if (!isset(ITApp_Matrix::$level_types[$type])) {
                $errors["level$i"] = 'Invalid type';
                continue;
            }
            $approvers = array();
            if ($type == 'approvers') {
                foreach (preg_split('/[\s,;]+/', (string) ($row['approvers'] ?? ''),
                        -1, PREG_SPLIT_NO_EMPTY) as $email) {
                    $email = strtolower($email);
                    if (!Validator::is_email($email))
                        $errors["level$i"] = sprintf('Invalid email: %s', Format::htmlchars($email));
                    else
                        $approvers[] = $email;
                }
                if (!$approvers && !isset($errors["level$i"]))
                    $errors["level$i"] = 'Enter at least one approver email';
            }
            $levels[] = array(
                'name' => mb_substr($name ?: ITApp_Matrix::$level_types[$type], 0, 128),
                'type' => $type,
                'approvers' => array_values(array_unique($approvers)),
                // Optional condition: level applies only when a field has one of these values
                'when' => preg_replace('/[^a-z0-9_]/i', '', (string) ($row['when'] ?? '')),
                'in' => array_values(array_filter(array_map('trim',
                    explode(',', (string) ($row['in'] ?? ''))), 'strlen')),
            );
            if ($levels[count($levels) - 1]['when'] && !$levels[count($levels) - 1]['in'])
                $errors["level$i"] = 'Enter the value(s) that make this level apply';
        }

        $enabled = !empty($_POST['enabled']);
        $fulfil = (int) ($_POST['fulfil_dept_id'] ?? 0);
        if ($fulfil && !Dept::lookup($fulfil))
            $errors['fulfil_dept_id'] = 'Unknown department';
        if ($enabled && !$levels)
            $errors['err'] = 'Add at least one approval level, or disable the workflow for this topic';
        if ($fulfil && $fulfil == $this->engine->getConfig()->getInt('hold_dept'))
            $errors['fulfil_dept_id'] = 'The fulfilment department cannot be the approval hold department';

        if ($errors) {
            $errors['err'] = $errors['err'] ?? 'Please correct the errors below';
            return $this->edit($id, $errors, array(
                'enabled' => $enabled,
                'fulfil_dept_id' => $fulfil,
                'levels' => $levels ?: array(),
            ));
        }

        $matrix = ITApp_Matrix::forTopic($topic->getId())
            ?: new ITApp_Matrix(array('topic_id' => $topic->getId()));
        $matrix->enabled = $enabled ? 1 : 0;
        $matrix->fulfil_dept_id = $fulfil;
        $matrix->setLevels($levels);
        $matrix->updated = SqlFunction::NOW();
        $matrix->save();

        Http::redirect(self::getUrl());
    }

    /**
     * Resolve the chain for a (topic, requester) pair exactly as the engine
     * would, using live Graph lookups. Read-only.
     */
    function simulate() {
        $this->requireAdmin();

        $topicId = (int) ($_GET['topic'] ?? 0);
        $email = strtolower(trim((string) ($_GET['email'] ?? '')));
        $result = null;
        $error = '';

        if ($topicId || $email) {
            $matrix = ITApp_Matrix::forTopic($topicId);
            if (!$matrix || !$matrix->getLevels())
                $error = 'This help topic has no approval matrix';
            elseif (!Validator::is_email($email))
                $error = 'Enter a valid requester email';
            else {
                $result = array();
                $approved = array();
                $skip = $this->engine->getConfig()->getBool('skip_duplicates');
                foreach ($matrix->getLevels() as $i => $level) {
                    list($approvers, $notes) = $this->engine->resolveFor($email, $level, false);
                    if (!empty($level['when']))
                        $notes[] = sprintf('Conditional: applies only when "%s" is %s',
                            $level['when'], implode(' / ', $level['in'] ?? array()));
                    $emails = array_keys($approvers);
                    $skipped = $skip && $emails && !array_diff($emails, $approved);
                    if ($skipped)
                        $notes[] = 'Would be skipped: approver already approved an earlier level';
                    else
                        // Simulate "first listed approver approves"
                        $approved[] = reset($emails);
                    $result[] = array(
                        'n' => $i + 1,
                        'level' => $level,
                        'approvers' => $approvers,
                        'notes' => $notes,
                        'skipped' => $skipped,
                    );
                }
                if (!$matrix->isEnabled())
                    $error = 'Note: the workflow is currently disabled for this topic';
            }
        }

        $topics = array();
        foreach (ITApp_Matrix::objects() as $M) {
            if ($T = Topic::lookup($M->topic_id))
                $topics[$M->topic_id] = $T->getFullName();
        }

        $this->render('admin-simulate.tmpl.php', array(
            'topics' => $topics,
            'topicId' => $topicId,
            'email' => $email,
            'result' => $result,
            'error' => $error,
            'user' => $email ? User::lookupByEmail($email) : null,
            'back' => self::getUrl(),
            'action' => self::getUrl('/simulate'),
        ));
    }

    /* Form pack --------------------------------------------------------- */

    function formpack($msg='', $errors=array()) {
        $this->requireAdmin();
        $this->render('admin-formpack.tmpl.php', array(
            'status' => ITApprovalsFormPack::status(),
            'depts' => Dept::getDepartments(),
            'holdDept' => $this->engine->getConfig()->getInt('hold_dept'),
            'fallback' => $this->engine->getConfig()->get('fallback_approver'),
            'msg' => $msg,
            'errors' => $errors,
            'action' => self::getUrl('/formpack'),
        ));
    }

    function installPack() {
        $this->requireAdmin();
        $key = (string) ($_POST['form'] ?? '');
        $dept = (int) ($_POST['dept_id'] ?? 0);
        $errors = array();
        if ($dept && $dept == $this->engine->getConfig()->getInt('hold_dept'))
            $errors['err'] = 'The fulfilment department cannot be the approval hold department';
        elseif (ITApprovalsFormPack::install($key, $dept,
                $this->engine->getConfig()->get('fallback_approver'), $errors)) {
            $defs = ITApprovalsFormPack::definitions();
            return $this->formpack(sprintf('Installed "%s". Review its approvers in the matrix before go-live.',
                Format::htmlchars($defs[$key]['title'])));
        }
        return $this->formpack('', $errors);
    }

    /* Conditional-field rules -------------------------------------------- */

    function rules($msg='', $errors=array()) {
        $this->requireAdmin();
        $forms = array();
        foreach (DynamicForm::objects()->filter(array('type' => 'G')) as $F) {
            $fields = array();
            foreach ($F->getDynamicFields() as $field)
                if ($field->get('name'))
                    $fields[$field->get('name')] = $field->get('label');
            $forms[$F->id] = array('title' => $F->getTitle(), 'fields' => $fields);
        }
        $this->render('admin-rules.tmpl.php', array(
            'rules' => ITApp_FieldRule::objects(),
            'forms' => $forms,
            'msg' => $msg,
            'errors' => $errors,
            'action' => self::getUrl('/rules'),
        ));
    }

    function saveRule() {
        $this->requireAdmin();
        if (($id = (int) ($_POST['delete'] ?? 0))
                && ($rule = ITApp_FieldRule::lookup($id))) {
            $rule->delete();
            return $this->rules('Rule deleted.');
        }

        $formId = (int) ($_POST['form_id'] ?? 0);
        $field = (string) ($_POST['field_name'] ?? '');
        $depends = (string) ($_POST['depends_on'] ?? '');
        $values = array_values(array_filter(array_map('trim',
            explode(',', (string) ($_POST['values'] ?? ''))), 'strlen'));
        $form = DynamicForm::lookup($formId);
        $names = array();
        if ($form)
            foreach ($form->getDynamicFields() as $f)
                $names[$f->get('name')] = true;

        $errors = array();
        if (!$form)
            $errors['err'] = 'Select a form';
        elseif (!isset($names[$field]) || !isset($names[$depends]))
            $errors['err'] = 'Both fields must belong to the selected form';
        elseif ($field == $depends)
            $errors['err'] = 'A field cannot depend on itself';
        elseif (!$values)
            $errors['err'] = 'Enter at least one value (the choice key, e.g. "temp")';
        if ($errors)
            return $this->rules('', $errors);

        $rule = ITApp_FieldRule::objects()->filter(array(
            'form_id' => $formId, 'field_name' => $field))->first()
            ?: new ITApp_FieldRule(array('form_id' => $formId, 'field_name' => $field));
        $rule->depends_on = $depends;
        $rule->match_values = JsonDataEncoder::encode($values);
        $rule->required = !empty($_POST['required']) ? 1 : 0;
        $rule->save();
        return $this->rules('Rule saved.');
    }

    /* Reports ------------------------------------------------------------ */

    function reports() {
        $this->requireAdmin();
        $report = new ITApprovalsReports((int) ($_GET['days'] ?? 30));
        if (($_GET['export'] ?? '') == 'csv') {
            Http::download(sprintf('it-approvals-levels-%dd-%s.csv', $report->getDays(), date('Ymd')),
                'text/csv', $report->csv());
            exit;
        }
        $hours = max(1, (int) $this->engine->getConfig()->get('reminder_hours', 24));
        $this->render('admin-reports.tmpl.php', array(
            'report' => $report,
            'topics' => $report->byTopic(),
            'levels' => $report->byLevel(),
            'approvers' => $report->byApprover(),
            'overdue' => $report->overdue($hours),
            'hours' => $hours,
            'action' => self::getUrl('/reports'),
        ));
    }

    /* Helpers -------------------------------------------------------- */

    /** Named fields of a Help Topic's forms: name => "Label (form)" */
    static function topicFields(Topic $topic) {
        $out = array();
        foreach ($topic->getForms() as $form)
            foreach ($form->getDynamicFields() as $f)
                if (($name = $f->get('name')) && !in_array($name, array('subject', 'message', 'priority')))
                    $out[$name] = $f->get('label');
        return $out;
    }

    private function healthChecks() {
        $config = $this->engine->getConfig();
        $checks = array();
        foreach (array(
                'pending_status' => array('Pending Approval status', 'open'),
                'returned_status' => array('Information Required status', 'open'),
                'rejected_status' => array('Rejected status', 'closed'),
                ) as $key => $info) {
            list($label, $state) = $info;
            $S = TicketStatus::lookup($config->getInt($key));
            $checks[] = array($label, $S && $S->getState() == $state,
                $S ? $S->getName() : 'Not configured');
        }
        $D = Dept::lookup($config->getInt('hold_dept'));
        $checks[] = array('Approval hold department', (bool) $D,
            $D ? $D->getName() : 'Not configured');
        $graph = $this->engine->getGraph();
        $checks[] = array('Microsoft Graph credentials', $graph->isConfigured(),
            $graph->isConfigured() ? 'Configured — verify with the simulator' : 'Not configured');
        $fb = $config->get('fallback_approver');
        $checks[] = array('Fallback approver', (bool) $fb, $fb ?: 'Not configured');
        return $checks;
    }

    private function requireAdmin() {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isAdmin()) {
            Http::response(403, 'Access denied');
            exit;
        }
    }

    private function render($template, array $vars) {
        // Variables used by the staff header/footer templates
        global $cfg, $ost, $thisstaff, $nav;
        if (!defined('ADMINPAGE'))
            define('ADMINPAGE', true);
        $nav = new AdminNav($thisstaff);
        $nav->setTabActive('apps');
        $ost->setPageTitle('IT Approvals');

        $errors = array();
        $msg = $warn = '';
        if (isset($vars['errors']))
            $errors = $vars['errors'];
        if (!empty($vars['msg']))
            $msg = $vars['msg'];

        require STAFFINC_DIR . 'header.inc.php';
        // Extract after the header: its templates set their own locals
        // ($info, $lang, $name ...) which would clobber ours
        extract($vars);
        include __DIR__ . '/templates/' . $template;
        require STAFFINC_DIR . 'footer.inc.php';
    }
}
