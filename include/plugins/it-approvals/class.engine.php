<?php
/**
 * Approval workflow engine.
 *
 *   submit ─► pending(L1) ─approve─► pending(L2) … ─approve(last)─► approved
 *                 │  ▲                                                 │
 *                 │  └─ resubmit ◄─ returned                           ▼
 *                 ├─ return ───────► returned                  fulfilment dept
 *                 ├─ reject ───────► rejected                  (stock osTicket)
 *                 └─ cancel ───────► cancelled
 *
 * See docs/STRATEGY.md §5 for the full rules.
 */
class ITApprovalsEngine {

    const POSTER = 'IT Approvals';

    private $config;
    private $graph;

    // Topic ids flagged in ticket.create.validated, consumed in
    // ticket.created (same request)
    private $held = array();

    function __construct(ITApprovalsConfig $config) {
        $this->config = $config;
        $this->graph = new ITApprovalsGraph($config);
    }

    function getGraph() {
        return $this->graph;
    }

    function getConfig() {
        return $this->config;
    }

    /* ================================================================
     * Signal handlers
     * ================================================================ */

    /**
     * ticket.create.validated — $vars is passed by reference. Re-route
     * tickets for approval-enabled topics into the hold department with
     * the Pending Approval status and no auto-assignment, so no agent is
     * alerted until the request is approved.
     */
    function onTicketValidated($object, &$vars) {
        global $thisstaff;

        $topicId = (int) ($vars['topicId'] ?? 0);
        if (!$topicId || !$this->getActiveMatrix($topicId))
            return;
        if ($thisstaff && !$this->config->getBool('apply_to_staff'))
            return;
        if (!($holdDept = $this->config->getInt('hold_dept'))
                || !($pending = $this->config->getInt('pending_status')))
            return;

        $vars['deptId'] = $holdDept;
        $vars['statusId'] = $pending;
        // isset() + falsy suppresses help-topic / filter auto-assignment
        $vars['staffId'] = 0;
        $vars['teamId'] = 0;
        unset($vars['assignId']);

        $this->held[] = $topicId;
    }

    /**
     * ticket.created — start the workflow for tickets held above.
     */
    function onTicketCreated($ticket) {
        if (!$ticket instanceof Ticket
                || !in_array($ticket->getTopicId(), $this->held))
            return;
        $this->held = array_diff($this->held, array($ticket->getTopicId()));

        try {
            $this->start($ticket);
        }
        catch (Throwable $t) {
            $this->logError('Unable to start approval for ticket #'
                . $ticket->getNumber(), $t->getMessage());
        }
    }

    /**
     * threadentry.created — a requester reply on a returned request
     * resubmits it.
     */
    function onThreadEntry($entry) {
        if (!$this->config->getBool('resubmit_on_reply')
                || !$entry instanceof ThreadEntry
                || $entry->getType() != 'M'
                || !($thread = $entry->getThread())
                || $thread->getObjectType() != ObjectModel::OBJECT_TYPE_TICKET
                || !($request = ITApp_Request::forTicket($thread->getObjectId()))
                || $request->getState() != ITApp_Request::STATE_RETURNED
                || $entry->getUserId() != $request->user_id)
            return;

        $errors = array();
        $this->resubmit($request, $entry->getUser(),
            'Resubmitted by replying to the ticket.', $errors, false);
    }

    /**
     * cron — send reminders for steps pending longer than the interval.
     */
    function onCron() {
        $hours = (int) $this->config->get('reminder_hours', 24);
        if ($hours <= 0)
            return;

        $cutoff = SqlFunction::NOW()->minus(SqlInterval::HOUR($hours));
        $steps = ITApp_Step::objects()
            ->filter(array('decision' => ITApp_Step::PENDING))
            ->filter(Q::any(array(
                Q::all(array('reminded__isnull' => true, 'created__lt' => $cutoff)),
                'reminded__lt' => $cutoff,
            )))
            ->limit(50);

        foreach ($steps as $step) {
            $request = ITApp_Request::lookup($step->request_id);
            if ($request && $request->getState() == ITApp_Request::STATE_PENDING)
                $this->notifyApprovers($request, $step, true);
            $step->reminded = SqlFunction::NOW();
            $step->save();
        }
    }

    /* ================================================================
     * Workflow
     * ================================================================ */

    function getActiveMatrix($topicId) {
        $M = ITApp_Matrix::forTopic($topicId);
        return ($M && $M->isEnabled() && $M->getLevels()) ? $M : null;
    }

    function start(Ticket $ticket) {
        if (ITApp_Request::forTicket($ticket->getId()))
            return false;
        if (!($matrix = $this->getActiveMatrix($ticket->getTopicId())))
            return false;

        // The status may have been dropped by a permission check during
        // agent-side creation; enforce it.
        $pending = $this->config->getInt('pending_status');
        if ($pending && $ticket->getStatusId() != $pending)
            $ticket->setStatusId($pending);

        $owner = $ticket->getOwner();
        $request = new ITApp_Request(array(
            'ticket_id' => $ticket->getId(),
            'topic_id' => $ticket->getTopicId(),
            'user_id' => $ticket->getUserId(),
            'requester_email' => strtolower((string) $owner->getEmail()),
            'levels' => $matrix->levels,
            'fulfil_dept_id' => (int) $matrix->fulfil_dept_id,
            'current_level' => 0,
            'cycle' => 1,
            'state' => ITApp_Request::STATE_PENDING,
            'created' => SqlFunction::NOW(),
            'updated' => SqlFunction::NOW(),
        ));
        if (!$request->save())
            throw new Exception('Unable to save approval request');

        $this->postNote($ticket, 'Approval workflow started',
            sprintf('This request requires up to %d approval level(s) before it is released to IT.',
                $request->getNumLevels()));

        if ($missing = $this->getMissingFields($ticket))
            $this->returnIncomplete($request, $missing);
        else
            $this->activateLevel($request, 1);
        return $request;
    }

    /* Answers & conditional fields ------------------------------------ */

    /**
     * Ticket form answers keyed by field name:
     *   array('values' => name => list of values/keys, 'labels' => name => label)
     */
    function getTicketAnswers(Ticket $ticket) {
        $values = $labels = $forms = array();
        foreach (DynamicFormEntry::forTicket($ticket->getId(), true) as $entry) {
            $forms[] = $entry->form_id;
            foreach ($entry->getAnswers() as $a) {
                $field = $a->getField();
                if (!($name = $field->get('name')))
                    continue;
                // Emptiness from the stored value: date fields return 0 for "no date"
                $raw = $a->get('value');
                $v = $a->getValue();
                if ($raw === null || trim(Format::striptags((string) $raw)) === '')
                    $list = array();
                elseif (is_array($v))
                    $list = array_map('strval', array_keys($v));
                elseif ($v === null || $v === false)
                    $list = array();
                else
                    $list = array((string) $v);
                $values[$name] = $list;
                $labels[$name] = $field->get('label');
            }
        }
        return array('values' => $values, 'labels' => $labels, 'forms' => $forms);
    }

    /** Labels of conditional fields that are visible and required but empty */
    function getMissingFields(Ticket $ticket) {
        $answers = $this->getTicketAnswers($ticket);
        if (!$answers['forms'])
            return array();
        $missing = array();
        foreach (ITApp_FieldRule::objects()->filter(array(
                'form_id__in' => $answers['forms'], 'required' => 1)) as $rule) {
            if ($rule->isVisible($answers['values'])
                    && empty($answers['values'][$rule->field_name]))
                $missing[] = $answers['labels'][$rule->field_name] ?? $rule->field_name;
        }
        return $missing;
    }

    /** Send an incomplete request straight back to the requester */
    private function returnIncomplete(ITApp_Request $request, array $missing) {
        $ticket = $request->getTicket();
        $comments = 'Please complete: ' . implode(', ', $missing)
            . '. Edit the request on the portal, then reply or resubmit.';
        $step = new ITApp_Step(array(
            'request_id' => $request->id, 'level' => 0, 'cycle' => $request->cycle,
            'level_name' => 'Completeness check', 'decision' => ITApp_Step::RETURNED,
            'decided_by_name' => self::POSTER, 'comments' => $comments,
            'created' => SqlFunction::NOW(), 'decided' => SqlFunction::NOW(),
        ));
        $step->save();
        $request->state = ITApp_Request::STATE_RETURNED;
        $request->touch();
        $request->save();
        $this->setTicketStatus($ticket, 'returned_status');
        $this->postNote($ticket, 'Returned automatically — information missing', $comments);
        $this->notifyRequester($request, 'returned', $step, $comments, null);
    }

    /**
     * Activate level $n (or finalise when past the last level). Levels that
     * resolve only to people who already approved are skipped when the
     * skip_duplicates option is on.
     */
    private function activateLevel(ITApp_Request $request, $n) {
        $ticket = $request->getTicket();

        $answers = null;
        while ($n <= $request->getNumLevels()) {
            $level = $request->getLevel($n);

            // Conditional level, e.g. Information Security only for vendors
            if (!empty($level['when'])) {
                $answers = $answers ?? $this->getTicketAnswers($ticket);
                if (!array_intersect($answers['values'][$level['when']] ?? array(),
                        array_map('strval', $level['in'] ?? array()))) {
                    $step = new ITApp_Step(array(
                        'request_id' => $request->id, 'level' => $n,
                        'cycle' => $request->cycle,
                        'level_name' => mb_substr($level['name'] ?: "Level $n", 0, 128),
                        'decision' => ITApp_Step::SKIPPED,
                        'note' => 'Not required for this request',
                        'created' => SqlFunction::NOW(), 'decided' => SqlFunction::NOW(),
                    ));
                    $step->save();
                    $this->postNote($ticket, sprintf('Level %d (%s) not required', $n,
                        $step->level_name), sprintf('Applies only when "%s" is %s.',
                        $answers['labels'][$level['when']] ?? $level['when'],
                        implode(' / ', $level['in'] ?? array())));
                    $n++;
                    continue;
                }
            }

            list($approvers, $notes) = $this->resolveLevel($request, $level);

            $step = new ITApp_Step(array(
                'request_id' => $request->id,
                'level' => $n,
                'cycle' => $request->cycle,
                'level_name' => mb_substr($level['name'] ?: "Level $n", 0, 128),
                'decision' => ITApp_Step::PENDING,
                'note' => mb_substr(implode('; ', $notes), 0, 255),
                'created' => SqlFunction::NOW(),
            ));

            $alreadyApproved = $this->getApprovedEmails($request);
            $emails = array_keys($approvers);
            if ($this->config->getBool('skip_duplicates')
                    && $emails && !array_diff($emails, $alreadyApproved)) {
                $step->decision = ITApp_Step::SKIPPED;
                $step->decided = SqlFunction::NOW();
                $step->note = mb_substr(trim($step->note
                    . '; Approver already approved an earlier level', '; '), 0, 255);
                $step->save();
                $this->saveApprovers($step, $approvers);
                $this->postNote($ticket, sprintf('Level %d (%s) skipped', $n,
                    $step->level_name), 'The approver already approved an earlier level.');
                $n++;
                continue;
            }

            $step->save();
            $this->saveApprovers($step, $approvers);

            $request->current_level = $n;
            $request->state = ITApp_Request::STATE_PENDING;
            $request->touch();
            $request->save();

            $this->setTicketStatus($ticket, 'pending_status');
            $this->postNote($ticket,
                sprintf('Awaiting level %d approval: %s', $n, $step->level_name),
                sprintf("Approver(s): %s%s",
                    implode(', ', $this->formatApprovers($approvers)),
                    $notes ? "\nRouting notes: " . implode('; ', $notes) : ''));
            $this->notifyApprovers($request, $step);
            $this->notifyRequester($request, 'pending', $step);
            return $step;
        }

        return $this->finalizeApproved($request);
    }

    /**
     * Resolve the approvers for one matrix level.
     *
     * Returns array(array(email => name), array(notes)).
     */
    function resolveLevel(ITApp_Request $request, array $level, $useCache=true) {
        return $this->resolveFor($request->requester_email, $level, $useCache);
    }

    function resolveFor($requesterEmail, array $level, $useCache=true) {
        $requesterEmail = strtolower($requesterEmail);
        $approvers = array();
        $notes = array();

        switch ($level['type'] ?? '') {
        case 'manager':
            if ($m = $this->graph->getManager($requesterEmail, $useCache))
                $approvers[$m['email']] = $m['name'];
            else
                $notes[] = 'Line manager: ' . ($this->graph->getLastError() ?: 'not found');
            break;

        case 'manager2':
            $m = $this->graph->getManager($requesterEmail, $useCache);
            if (!$m) {
                $notes[] = 'Line manager: ' . ($this->graph->getLastError() ?: 'not found');
            }
            elseif ($m2 = $this->graph->getManager($m['email'], $useCache)) {
                $approvers[$m2['email']] = $m2['name'];
            }
            else {
                $notes[] = sprintf("Manager's manager (%s): %s", $m['email'],
                    $this->graph->getLastError() ?: 'not found');
            }
            break;

        case 'approvers':
            foreach ($level['approvers'] ?? array() as $email) {
                $email = strtolower(trim($email));
                if (!Validator::is_email($email))
                    continue;
                $U = User::lookupByEmail($email);
                $approvers[$email] = $U ? (string) $U->getName() : $email;
            }
            if (!$approvers)
                $notes[] = 'No valid approver emails configured';
            break;

        default:
            $notes[] = 'Unknown level type';
        }

        // Self-approval guard
        if (isset($approvers[$requesterEmail])) {
            unset($approvers[$requesterEmail]);
            $notes[] = 'Requester removed from approvers (self-approval not allowed)';
        }

        if (!$approvers) {
            $fallback = strtolower(trim($this->config->get('fallback_approver')));
            if ($fallback) {
                $U = User::lookupByEmail($fallback);
                $approvers[$fallback] = $U ? (string) $U->getName() : $fallback;
                $notes[] = 'Routed to fallback approver';
                if ($fallback == $requesterEmail)
                    $notes[] = 'WARNING: fallback approver is the requester';
            }
        }

        return array($approvers, $notes);
    }

    /**
     * Apply an approver decision. $actor is the signed-in portal user.
     */
    function decide(ITApp_Request $request, $actor, $decision, $comments, &$errors) {
        $comments = trim((string) $comments);
        if (!in_array($decision, array(ITApp_Step::APPROVED,
                ITApp_Step::REJECTED, ITApp_Step::RETURNED))) {
            $errors['err'] = 'Invalid decision';
            return false;
        }
        if ($decision != ITApp_Step::APPROVED && !$comments) {
            $errors['comments'] = 'A comment is required to reject or return a request';
            return false;
        }
        if ($request->getState() != ITApp_Request::STATE_PENDING
                || !($step = $request->getCurrentStep())) {
            $errors['err'] = 'This request is not awaiting approval';
            return false;
        }
        $emails = $this->getUserEmails($actor);
        if (!$step->canBeDecidedBy($emails)
                || $request->isRequester((string) $actor->getEmail())) {
            $errors['err'] = 'You are not an approver for the current stage of this request';
            return false;
        }

        // Atomic claim of the step — guards against two approvers (or a
        // double submit) deciding at the same time
        $claim = array(
            'decision' => $decision,
            'decided_by_email' => strtolower((string) $actor->getEmail()),
            'decided_by_name' => mb_substr((string) $actor->getName(), 0, 255),
            'comments' => $comments,
        );
        $updated = ITApp_Step::objects()
            ->filter(array('id' => $step->id, 'decision' => ITApp_Step::PENDING))
            ->update($claim + array('decided' => SqlFunction::NOW()));
        if (!$updated) {
            $errors['err'] = 'This stage was already decided by someone else';
            return false;
        }
        // The bulk update bypasses the ORM identity map; keep the cached
        // instance in sync for the rest of this request
        foreach ($claim as $k => $v)
            $step->ht[$k] = $v;
        $step->ht['decided'] = date('Y-m-d H:i:s');

        $ticket = $request->getTicket();
        $who = sprintf('%s <%s>', $actor->getName(), $actor->getEmail());
        $verb = array(
            ITApp_Step::APPROVED => 'approved',
            ITApp_Step::REJECTED => 'rejected',
            ITApp_Step::RETURNED => 'returned for information',
        )[$decision];
        $this->postNote($ticket,
            sprintf('Level %d (%s) %s by %s', $step->level, $step->level_name,
                $verb, $actor->getName()),
            sprintf("%s %s this request.%s", $who, $verb,
                $comments ? "\n\nComments: " . $comments : ''));

        switch ($decision) {
        case ITApp_Step::APPROVED:
            $this->activateLevel($request, $step->level + 1);
            break;

        case ITApp_Step::RETURNED:
            $request->state = ITApp_Request::STATE_RETURNED;
            $request->touch();
            $request->save();
            $this->setTicketStatus($ticket, 'returned_status');
            $this->notifyRequester($request, 'returned', $step, $comments, $actor);
            break;

        case ITApp_Step::REJECTED:
            $request->state = ITApp_Request::STATE_REJECTED;
            $request->closed = SqlFunction::NOW();
            $request->touch();
            $request->save();
            $this->setTicketStatus($ticket, 'rejected_status');
            $this->notifyRequester($request, 'rejected', $step, $comments, $actor);
            break;
        }
        return true;
    }

    /**
     * Requester sends a returned request back to the level that returned it.
     */
    function resubmit(ITApp_Request $request, $actor, $comments, &$errors, $postNote=true) {
        if ($request->getState() != ITApp_Request::STATE_RETURNED) {
            $errors['err'] = 'Only returned requests can be resubmitted';
            return false;
        }
        if (!$actor || $actor->getId() != $request->user_id) {
            $errors['err'] = 'Only the requester can resubmit this request';
            return false;
        }

        // Conditional fields still incomplete: stay returned
        if ($missing = $this->getMissingFields($request->getTicket())) {
            $errors['err'] = 'Still missing: ' . implode(', ', $missing)
                . '. Edit the request first.';
            return false;
        }

        $request->cycle = $request->cycle + 1;
        $request->state = ITApp_Request::STATE_PENDING;
        $request->touch();
        $request->save();

        if ($postNote)
            $this->postNote($request->getTicket(), 'Resubmitted by requester',
                trim((string) $comments) ?: 'The requester resubmitted the request.');

        $this->activateLevel($request, max(1, $request->current_level));
        return true;
    }

    /**
     * Requester withdraws an open request.
     */
    function cancel(ITApp_Request $request, $actor, $comments, &$errors) {
        if (!$request->isOpen()) {
            $errors['err'] = 'This request can no longer be cancelled';
            return false;
        }
        if (!$actor || $actor->getId() != $request->user_id) {
            $errors['err'] = 'Only the requester can cancel this request';
            return false;
        }

        ITApp_Step::objects()
            ->filter(array('request_id' => $request->id,
                'decision' => ITApp_Step::PENDING))
            ->update(array('decision' => ITApp_Step::CANCELLED,
                'decided' => SqlFunction::NOW()));

        $request->state = ITApp_Request::STATE_CANCELLED;
        $request->closed = SqlFunction::NOW();
        $request->touch();
        $request->save();

        $ticket = $request->getTicket();
        $this->postNote($ticket, 'Cancelled by requester',
            trim((string) $comments) ?: 'The requester withdrew the request.');
        $this->setTicketStatus($ticket, 'rejected_status');
        return true;
    }

    /**
     * All levels approved: release the ticket to the fulfilment department
     * and let stock osTicket take over (assignment, SLA, alerts).
     */
    private function finalizeApproved(ITApp_Request $request) {
        global $cfg;

        $ticket = $request->getTicket();
        $topic = $ticket->getTopic();

        $request->state = ITApp_Request::STATE_APPROVED;
        $request->closed = SqlFunction::NOW();
        $request->touch();
        $request->save();

        $deptId = $request->fulfil_dept_id
            ?: ($topic ? $topic->getDeptId() : 0)
            ?: $cfg->getDefaultDeptId();
        if (($dept = Dept::lookup($deptId)) && $deptId != $ticket->getDeptId()) {
            $ticket->dept_id = $deptId;
            $ticket->save();
            $ticket->logEvent('transferred', array('dept' => $dept->getName()));
        }

        $statusId = $this->config->getInt('approved_status')
            ?: ($topic ? $topic->getStatusId() : 0)
            ?: $cfg->getDefaultTicketStatusId();
        if ($status = TicketStatus::lookup($statusId))
            $ticket->setStatus($status);

        $ticket->selectSLAId($topic ? $topic->getSLAId() : null);
        $ticket->updateEstDueDate();

        $this->postNote($ticket, 'Fully approved — released to IT',
            sprintf('All approval levels completed. Ticket released to %s.',
                $dept ? $dept->getName() : 'the fulfilment department'));

        // Help Topic auto-assignment (suppressed at creation time)
        if ($topic && $topic->getStaffId())
            $ticket->assignToStaff($topic->getStaffId(), false, true, self::POSTER);
        elseif ($topic && $topic->getTeamId())
            $ticket->assignToTeam($topic->getTeamId(), false, true, self::POSTER);

        // Standard New Ticket alert to the fulfilment department
        $ticket->onNewTicket($ticket->getLastMessage(), false, true);

        $this->notifyRequester($request, 'approved');
        return true;
    }

    /* ================================================================
     * Helpers
     * ================================================================ */

    private function saveApprovers(ITApp_Step $step, array $approvers) {
        foreach ($approvers as $email => $name) {
            $A = new ITApp_StepApprover(array(
                'step_id' => $step->id,
                'email' => $email,
                'name' => mb_substr((string) $name, 0, 255),
            ));
            $A->save();
            // Make sure the approver exists as an osTicket user so the SSO
            // plugin can sign them in to the portal
            if (!User::lookupByEmail($email))
                User::fromVars(array('email' => $email, 'name' => $name));
        }
    }

    function getApprovedEmails(ITApp_Request $request) {
        // values_flat() reads raw rows — hydrated models could come from the
        // ORM identity map and miss the bulk update made in decide()
        $emails = array();
        foreach (ITApp_Step::objects()->filter(array(
                'request_id' => $request->id,
                'decision' => ITApp_Step::APPROVED))
                ->values_flat('decided_by_email') as $row)
            $emails[] = strtolower((string) $row[0]);
        return array_unique(array_filter($emails));
    }

    /**
     * All email addresses of an osTicket user (users can have several).
     */
    function getUserEmails($user) {
        $emails = array(strtolower((string) $user->getEmail()));
        if (($U = User::lookup($user->getId())) && $U->emails) {
            foreach ($U->emails as $E)
                $emails[] = strtolower($E->address);
        }
        return array_unique(array_filter($emails));
    }

    private function formatApprovers(array $approvers) {
        $out = array();
        foreach ($approvers as $email => $name)
            $out[] = ($name && $name != $email) ? "$name <$email>" : $email;
        return $out;
    }

    private function setTicketStatus(Ticket $ticket, $configKey) {
        if (!($id = $this->config->getInt($configKey))
                || !($status = TicketStatus::lookup($id))
                || $ticket->getStatusId() == $id)
            return;
        $errors = array();
        // force_close: the ticket may have open tasks/threads
        $ticket->setStatus($status, '', $errors, false, true);
    }

    private function postNote(Ticket $ticket, $title, $body) {
        $errors = array();
        $ticket->postNote(array(
                'title' => $title,
                'note' => new HtmlThreadEntryBody(nl2br(Format::htmlchars($body))),
            ), $errors, self::POSTER, false);
    }

    function getPortalUrl(ITApp_Request $request=null) {
        global $cfg;
        $url = rtrim($cfg->getBaseUrl(), '/') . '/ajax.php/itapprovals';
        return $request ? $url . '/r/' . $request->id : $url;
    }

    private function logError($title, $msg) {
        global $ost;
        if ($ost)
            $ost->logError('IT Approvals: ' . $title, $msg, false);
    }

    /* ================================================================
     * Notifications
     * ================================================================ */

    private function getMailer() {
        global $cfg;
        // Requesters and approvers reply to the service desk address
        return $cfg->getDefaultEmail() ?: $cfg->getAlertEmail();
    }

    private function send($to, $subject, $html) {
        if (!($email = $this->getMailer()))
            return false;
        try {
            return $email->send($to, $subject, $html);
        }
        catch (Throwable $t) {
            $this->logError('Email to ' . $to . ' failed', $t->getMessage());
            return false;
        }
    }

    private function notifyApprovers(ITApp_Request $request, ITApp_Step $step, $reminder=false) {
        $ticket = $request->getTicket();
        $owner = $ticket->getOwner();
        $subject = sprintf('%sApproval required: #%s %s',
            $reminder ? 'Reminder — ' : '',
            $ticket->getNumber(), $ticket->getSubject());

        foreach ($step->getApprovers() as $A) {
            $html = $this->wrap(sprintf(
                '<p>Dear %s,</p>'
                . '<p><b>%s</b> has submitted an IT request that needs your approval '
                . '(<b>level %d — %s</b>).</p>'
                . '%s'
                . '<p><a href="%s" style="%s">Review the request</a></p>'
                . '<p style="color:#666;font-size:12px">Or open: %s</p>'
                . '<p style="color:#666">Sign in with your company account. '
                . 'Approval, rejection or return is done on the portal page, not by replying to this email.</p>',
                Format::htmlchars($A->name ?: $A->email),
                Format::htmlchars((string) $owner->getName()),
                $step->level, Format::htmlchars($step->level_name),
                $this->summaryHtml($request),
                Format::htmlchars($this->getPortalUrl($request)),
                'display:inline-block;padding:8px 16px;background:#0b5cad;color:#fff;text-decoration:none;border-radius:4px',
                Format::htmlchars($this->getPortalUrl($request))
            ));
            $this->send($A->email, $subject, $html);
        }
    }

    private function notifyRequester(ITApp_Request $request, $event,
            $step=null, $comments='', $actor=null) {
        $ticket = $request->getTicket();
        $owner = $ticket->getOwner();
        $num = $ticket->getNumber();

        switch ($event) {
        case 'pending':
            // Only tell the requester about the first level, and about
            // resubmissions; intermediate levels are visible on the portal
            if ($step->level != 1 && $request->cycle == 1)
                return;
            $subject = sprintf('Request #%s submitted for approval', $num);
            $lead = sprintf('Your request is awaiting approval from <b>%s</b> (%s).',
                Format::htmlchars(implode(', ', $step->getApproverNames())),
                Format::htmlchars($step->level_name));
            break;
        case 'returned':
            $subject = sprintf('Request #%s returned — more information needed', $num);
            $lead = sprintf('<b>%s</b> returned your request and asked for more information:'
                . '<blockquote>%s</blockquote>'
                . 'Reply to the ticket (portal or email) with the requested details, '
                . 'or use <i>Resubmit</i> on the request page.',
                Format::htmlchars($actor ? (string) $actor->getName() : self::POSTER),
                nl2br(Format::htmlchars($comments)));
            break;
        case 'rejected':
            $subject = sprintf('Request #%s rejected', $num);
            $lead = sprintf('<b>%s</b> rejected your request:<blockquote>%s</blockquote>',
                Format::htmlchars($actor ? (string) $actor->getName() : self::POSTER),
                nl2br(Format::htmlchars($comments)));
            break;
        case 'approved':
            $subject = sprintf('Request #%s approved', $num);
            $lead = 'Your request has been fully approved and passed to the IT team for fulfilment.';
            break;
        default:
            return;
        }

        $url = Format::htmlchars($this->getPortalUrl($request));
        $html = $this->wrap(sprintf(
            '<p>Dear %s,</p><p>%s</p>%s<p><a href="%s">Track your request</a><br>'
            . '<span style="color:#666;font-size:12px">%s</span></p>',
            Format::htmlchars((string) $owner->getName()), $lead,
            $this->summaryHtml($request), $url, $url));
        $this->send((string) $owner->getEmail(), $subject, $html);
    }

    private function summaryHtml(ITApp_Request $request) {
        $ticket = $request->getTicket();
        $topic = $ticket->getTopic();
        $rows = array(
            'Ticket' => '#' . $ticket->getNumber(),
            'Request type' => $topic ? $topic->getFullName() : '',
            'Subject' => $ticket->getSubject(),
            'Requester' => sprintf('%s <%s>', $ticket->getOwner()->getName(),
                $ticket->getOwner()->getEmail()),
        );
        // One line per item so the plain-text part stays readable
        $html = '<p style="margin:8px 0">';
        foreach ($rows as $k => $v)
            $html .= sprintf('<span style="color:#666">%s:</span> %s<br>',
                $k, Format::htmlchars((string) $v));
        return $html . '</p>';
    }

    private function wrap($inner) {
        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5">'
            . $inner . '</div>';
    }
}
