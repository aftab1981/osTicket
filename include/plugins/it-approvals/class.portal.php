<?php
/**
 * Client-portal pages for approvers and requesters.
 *
 *   GET  /ajax.php/itapprovals          inbox
 *   GET  /ajax.php/itapprovals/r/{id}   request detail + timeline
 *   POST /ajax.php/itapprovals/r/{id}   approve|reject|return|resubmit|cancel
 *
 * Every POST re-checks authorisation server-side; CSRF is enforced by
 * osTicket's client.inc.php for all client POSTs.
 */
class ITApprovalsPortal {

    private $engine;

    function __construct(ITApprovalsEngine $engine) {
        $this->engine = $engine;
    }

    function registerUrls($dispatcher) {
        $dispatcher->append(url('^/itapprovals', patterns('',
            url_get('^/?$', array($this, 'inbox')),
            url_get('^/r/(?P<id>\d+)/?$', array($this, 'view')),
            url_post('^/r/(?P<id>\d+)/?$', array($this, 'act'))
        )));
    }

    /**
     * Add an "Approvals" item (with a pending count) to the portal menu.
     * osTicket 1.18 has no hook for client navigation, so a scoped output
     * buffer inserts it into <ul id="nav"> on client-portal pages only.
     */
    function enableNavLink() {
        if (PHP_SAPI === 'cli')
            return;
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (preg_match('#/(scp|api|setup|include)/#i', $script))
            return;
        ob_start(array($this, 'injectNavLink'));
    }

    function injectNavLink($html) {
        global $thisclient;
        $html = $this->injectFieldRules($html);
        if (strpos($html, '<ul id="nav"') === false
                || !$thisclient || !$thisclient->isValid() || $thisclient->isGuest())
            return $html;

        try {
            $count = $this->countAwaiting($thisclient);
        }
        catch (Throwable $t) {
            $count = 0;
        }
        $active = strpos($_SERVER['REQUEST_URI'] ?? '', '/itapprovals') !== false;
        $item = sprintf('<li><a class="%s approvals" href="%s">%s%s</a></li>',
            $active ? 'active' : '',
            Format::htmlchars($this->engine->getPortalUrl()),
            'Approvals',
            $count ? sprintf(' <span class="itapp-badge">%d</span>', $count) : '');

        return preg_replace_callback('#(<ul id="nav"[^>]*>)(.*?)(</ul>)#s',
            function($m) use ($item, $active) {
                $links = $active
                    ? preg_replace('#class="active #', 'class="', $m[2])
                    : $m[2];
                return $m[1] . $links . $item . "\n" . $m[3];
            }, $html, 1);
    }

    /**
     * Conditional fields in the browser: show/hide dependent fields and mark
     * them required while visible. osTicket names inputs with a per-session
     * hash of (form id, field name), so the map is computed here. The server
     * re-checks on submission (ITApprovalsEngine::getMissingFields).
     */
    private function injectFieldRules($html) {
        if (stripos($html, '</body>') === false || strpos($html, '<form') === false
                || !session_id())
            return $html;
        try {
            $rules = array();
            foreach (ITApp_FieldRule::objects() as $R) {
                $rules[] = array(
                    'f' => self::fieldName($R->form_id, $R->field_name),
                    'd' => self::fieldName($R->form_id, $R->depends_on),
                    'v' => $R->getValues(),
                    'r' => $R->isRequired(),
                );
            }
        }
        catch (Throwable $t) {
            return $html;
        }
        if (!$rules)
            return $html;

        $script = '<script>(function(){var R=' . json_encode($rules) . ';'
          . 'function el(n){return document.querySelector(\'[name="\'+n+\'"],[name="\'+n+\'[]"]\');}'
          . 'function val(n){var e=el(n);if(!e)return[];if(e.tagName=="SELECT")return[].slice.call(e.selectedOptions).map(function(o){return o.value;});'
          . 'if(e.type=="checkbox"||e.type=="radio"){return[].slice.call(document.querySelectorAll(\'[name^="\'+n+\'"]:checked\')).map(function(o){return o.value;});}'
          . 'return e.value?[e.value]:[];}'
          . 'function apply(){R.forEach(function(r){var t=el(r.f);if(!t)return;var row=t.closest("tr")||t.parentNode;'
          . 'var show=val(r.d).some(function(v){return r.v.indexOf(v)>=0;});row.style.display=show?"":"none";'
          . 'var l=document.querySelector(\'label[for="\'+r.f+\'"]\');if(r.r&&l&&!l.querySelector(".itapp-req")){var s=document.createElement("span");s.className="error itapp-req";s.textContent=" *";(l.querySelector("span")||l).appendChild(s);}'
          . 'row.setAttribute("data-itapp-required",show&&r.r?"1":"");});}'
          . 'document.addEventListener("change",apply,true);'
          . 'document.addEventListener("submit",function(e){var bad=[];R.forEach(function(r){var t=el(r.f);if(!t||!r.r||t.tagName=="TEXTAREA")return;'
          . 'var row=t.closest("tr")||t.parentNode;if(row.getAttribute("data-itapp-required")&&!val(r.f).filter(function(v){return v!=="";}).length){bad.push(t);}});'
          . 'if(bad.length){e.preventDefault();bad[0].focus();alert("Please complete the highlighted required fields.");bad.forEach(function(t){t.style.borderColor="#d92d20";});}},true);'
          . 'new MutationObserver(function(){apply();}).observe(document.body,{childList:true,subtree:true});apply();})();</script>';
        $pos = strripos($html, '</body>');
        return substr_replace($html, $script, $pos, 0);
    }

    // Mirrors FormField::getFormName() for fields of a DynamicForm
    static function fieldName($formId, $name) {
        return substr(md5(session_id() . "-form-field-id-$formId-$name-" . SECRET_SALT), -14);
    }

    private function countAwaiting($me) {
        $emails = $this->engine->getUserEmails($me);
        if (!$emails)
            return 0;
        $pending = array();
        foreach (ITApp_Step::objects()
                ->filter(array('decision' => ITApp_Step::PENDING))
                ->values_flat('id') as $row)
            $pending[] = $row[0];
        if (!$pending)
            return 0;
        return ITApp_StepApprover::objects()
            ->filter(array('step_id__in' => $pending, 'email__in' => $emails))
            ->count();
    }

    /* Pages ---------------------------------------------------------- */

    function inbox() {
        $me = $this->requireLogin();
        $emails = $this->engine->getUserEmails($me);

        // Pending steps are few; find the ones this user may decide
        $pendingIds = array();
        foreach (ITApp_Step::objects()
                ->filter(array('decision' => ITApp_Step::PENDING))
                ->values_flat('id') as $row)
            $pendingIds[] = $row[0];

        $awaiting = array();
        if ($pendingIds && $emails) {
            $mine = array();
            foreach (ITApp_StepApprover::objects()
                    ->filter(array('step_id__in' => $pendingIds,
                        'email__in' => $emails))
                    ->values_flat('step_id') as $row)
                $mine[] = $row[0];
            if ($mine) {
                foreach (ITApp_Step::objects()
                        ->filter(array('id__in' => array_unique($mine)))
                        ->order_by('created') as $S) {
                    $R = ITApp_Request::lookup($S->request_id);
                    if ($R && $R->getState() == ITApp_Request::STATE_PENDING
                            && !$R->isRequester((string) $me->getEmail()))
                        $awaiting[] = array('step' => $S, 'request' => $R,
                            'ticket' => $R->getTicket());
                }
            }
        }

        $decided = array();
        if ($emails) {
            foreach (ITApp_Step::objects()
                    ->filter(array('decided_by_email__in' => $emails))
                    ->order_by('-decided')
                    ->limit(25) as $S) {
                if ($R = ITApp_Request::lookup($S->request_id))
                    $decided[] = array('step' => $S, 'request' => $R,
                        'ticket' => $R->getTicket());
            }
        }

        $requests = array();
        foreach (ITApp_Request::objects()
                ->filter(array('user_id' => $me->getId()))
                ->order_by('-id')
                ->limit(25) as $R) {
            $requests[] = array('request' => $R, 'ticket' => $R->getTicket(),
                'step' => $R->getCurrentStep());
        }

        $this->render('portal-inbox.tmpl.php', array(
            'awaiting' => $awaiting,
            'decided' => $decided,
            'requests' => $requests,
            'base' => $this->engine->getPortalUrl(),
        ));
    }

    function view($id) {
        $me = $this->requireLogin();
        list($request, $ticket) = $this->loadAuthorised($id, $me);

        $emails = $this->engine->getUserEmails($me);
        $current = $request->getCurrentStep();
        $isRequester = $request->user_id == $me->getId();

        $this->render('portal-request.tmpl.php', array(
            'request' => $request,
            'ticket' => $ticket,
            'current' => $current,
            'steps' => $request->getSteps(),
            'answers' => $this->getAnswers($ticket),
            'message' => $this->getFirstMessage($ticket),
            'canDecide' => $current && !$isRequester
                && $request->getState() == ITApp_Request::STATE_PENDING
                && $current->canBeDecidedBy($emails),
            'canResubmit' => $isRequester
                && $request->getState() == ITApp_Request::STATE_RETURNED,
            'canCancel' => $isRequester && $request->isOpen(),
            'isRequester' => $isRequester,
            'action' => $this->engine->getPortalUrl($request),
            'base' => $this->engine->getPortalUrl(),
            'flash' => $this->popFlash(),
        ));
    }

    function act($id) {
        $me = $this->requireLogin();
        list($request) = $this->loadAuthorised($id, $me);

        $comments = Format::striptags((string) ($_POST['comments'] ?? ''));
        $errors = array();
        $ok = false;
        switch ($_POST['do'] ?? '') {
        case 'approve':
            $ok = $this->engine->decide($request, $me, ITApp_Step::APPROVED, $comments, $errors);
            $msg = 'You approved this request.';
            break;
        case 'reject':
            $ok = $this->engine->decide($request, $me, ITApp_Step::REJECTED, $comments, $errors);
            $msg = 'You rejected this request.';
            break;
        case 'return':
            $ok = $this->engine->decide($request, $me, ITApp_Step::RETURNED, $comments, $errors);
            $msg = 'The request was returned to the requester.';
            break;
        case 'resubmit':
            $ok = $this->engine->resubmit($request, $me, $comments, $errors);
            $msg = 'Your request was resubmitted for approval.';
            break;
        case 'cancel':
            $ok = $this->engine->cancel($request, $me, $comments, $errors);
            $msg = 'Your request was cancelled.';
            break;
        default:
            $errors['err'] = 'Unknown action';
        }

        $this->setFlash($ok
            ? array('msg' => $msg)
            : array('err' => $errors['comments'] ?? $errors['err'] ?? 'Unable to complete the action'));
        Http::redirect($this->engine->getPortalUrl($request));
    }

    /* Helpers -------------------------------------------------------- */

    private function requireLogin() {
        global $thisclient;
        if (!$thisclient || !$thisclient->isValid() || $thisclient->isGuest()) {
            $_SESSION['_client']['auth']['dest'] =
                '/' . ltrim($_SERVER['REQUEST_URI'], '/');
            Http::redirect(ROOT_PATH . 'login.php');
            exit;
        }
        return $thisclient;
    }

    /**
     * A request is visible to its requester and to anyone who is (or was)
     * an approver on any of its steps. Everyone else gets "not found".
     */
    private function loadAuthorised($id, $me) {
        $request = ITApp_Request::lookup((int) $id);
        $ticket = $request ? $request->getTicket() : null;
        if ($request && $ticket) {
            if ($request->user_id == $me->getId())
                return array($request, $ticket);

            $stepIds = array();
            foreach (ITApp_Step::objects()
                    ->filter(array('request_id' => $request->id))
                    ->values_flat('id') as $row)
                $stepIds[] = $row[0];
            if ($stepIds && ITApp_StepApprover::objects()->filter(array(
                    'step_id__in' => $stepIds,
                    'email__in' => $this->engine->getUserEmails($me),
                ))->count())
                return array($request, $ticket);
        }
        Http::response(404, 'Request not found');
        exit;
    }

    private function getAnswers(Ticket $ticket) {
        $sections = array();
        foreach (DynamicFormEntry::forTicket($ticket->getId()) as $form) {
            $rows = array();
            $answers = $form->getAnswers()->exclude(Q::any(array(
                'field__flags__hasbit' => DynamicFormField::FLAG_EXT_STORED,
                'field__name__in' => array('subject', 'priority'),
                Q::not(array('field__flags__hasbit' => DynamicFormField::FLAG_CLIENT_VIEW)),
            )));
            foreach ($answers as $a) {
                if ($v = $a->display())
                    $rows[] = array($a->getField()->get('label'), $v);
            }
            if ($rows)
                $sections[] = array('title' => $form->getTitle(), 'rows' => $rows);
        }
        return $sections;
    }

    private function getFirstMessage(Ticket $ticket) {
        $entry = $ticket->getThread()->getEntries()
            ->filter(array('type' => 'M'))
            ->order_by('id')
            ->first();
        return $entry ? $entry->getBody()->display('html') : '';
    }

    private function setFlash(array $flash) {
        $_SESSION['itapprovals_flash'] = $flash;
    }

    private function popFlash() {
        $flash = $_SESSION['itapprovals_flash'] ?? array();
        unset($_SESSION['itapprovals_flash']);
        return $flash;
    }

    private function render($template, array $vars) {
        // Variables used by the client header/footer templates
        global $cfg, $ost, $thisclient, $nav;
        $errors = array();
        $msg = $warn = '';
        if (isset($vars['errors']))
            $errors = $vars['errors'];

        require CLIENTINC_DIR . 'header.inc.php';
        // Extract after the header: its templates set their own locals
        // ($info, $lang, $name ...) which would clobber ours
        extract($vars);
        include __DIR__ . '/templates/' . $template;
        require CLIENTINC_DIR . 'footer.inc.php';
    }
}
