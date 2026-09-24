<?php

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class ITApprovalsConfig extends PluginConfig {

    // Subkey used to encrypt the Graph client secret at rest
    const SECRET_KEY = 'itapprovals-graph';

    function getOptions() {
        $statuses = array('' => '— ' . __('Select') . ' —');
        foreach (TicketStatus::objects()->order_by('sort') as $S)
            $statuses[$S->getId()] = sprintf('%s (%s)', $S->getName(), $S->getState());

        $depts = array('' => '— ' . __('Select') . ' —');
        foreach (Dept::getDepartments() as $id => $name)
            $depts[$id] = $name;

        return array(
            'wf' => new SectionBreakField(array(
                'label' => 'Workflow statuses & hold department',
                'hint' => 'Create these statuses under Admin > Manage > Lists > Ticket Statuses first. See docs/STRATEGY.md section 9.',
            )),
            'pending_status' => new ChoiceField(array(
                'label' => 'Pending Approval status',
                'required' => true,
                'choices' => $statuses,
                'hint' => 'State must be "open".',
            )),
            'returned_status' => new ChoiceField(array(
                'label' => 'Information Required status',
                'required' => true,
                'choices' => $statuses,
                'hint' => 'State must be "open". Used when an approver returns the request.',
            )),
            'rejected_status' => new ChoiceField(array(
                'label' => 'Rejected status',
                'required' => true,
                'choices' => $statuses,
                'hint' => 'State must be "closed".',
            )),
            'approved_status' => new ChoiceField(array(
                'label' => 'Approved (fulfilment) status',
                'required' => false,
                'choices' => $statuses,
                'hint' => 'Leave empty to use the system default ticket status (normally "Open").',
            )),
            'hold_dept' => new ChoiceField(array(
                'label' => 'Approval hold department',
                'required' => true,
                'choices' => $depts,
                'hint' => 'Tickets wait here during approval. Disable alerts on this department and keep membership minimal.',
            )),

            'graph' => new SectionBreakField(array(
                'label' => 'Microsoft Graph (line manager lookup)',
                'hint' => 'Separate Entra app registration with Microsoft Graph Application permission User.Read.All (admin consented).',
            )),
            'graph_tenant' => new TextboxField(array(
                'label' => 'Tenant ID',
                'configuration' => array('size' => 40, 'length' => 64),
            )),
            'graph_client_id' => new TextboxField(array(
                'label' => 'Client (application) ID',
                'configuration' => array('size' => 40, 'length' => 64),
            )),
            'graph_secret' => new PasswordField(array(
                'label' => 'Client secret',
                'hint' => 'Stored encrypted. Leave blank to keep the current secret.',
                'configuration' => array('size' => 40, 'length' => 255,
                    'key' => self::SECRET_KEY),
            )),
            'mgr_cache_hours' => new TextboxField(array(
                'label' => 'Manager cache (hours)',
                'default' => 24,
                'configuration' => array('size' => 5, 'length' => 5),
                'validator' => 'number',
            )),

            'rules' => new SectionBreakField(array(
                'label' => 'Routing rules & notifications',
            )),
            'fallback_approver' => new TextboxField(array(
                'label' => 'Fallback approver email',
                'required' => true,
                'validator' => 'email',
                'configuration' => array('size' => 40, 'length' => 255),
                'hint' => 'Used when a level resolves to nobody (no manager in Entra, Graph error, or only the requester).',
            )),
            'skip_duplicates' => new BooleanField(array(
                'label' => 'Skip duplicate approvers',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Auto-skip a level whose approvers already approved an earlier level of the same request'),
            )),
            'resubmit_on_reply' => new BooleanField(array(
                'label' => 'Resubmit on requester reply',
                'default' => true,
                'configuration' => array(
                    'desc' => 'When a returned request gets a reply from the requester, send it back to the approver automatically'),
            )),
            'reminder_hours' => new TextboxField(array(
                'label' => 'Reminder interval (hours)',
                'default' => 24,
                'configuration' => array('size' => 5, 'length' => 5),
                'validator' => 'number',
                'hint' => '0 disables reminders. Sent by the osTicket cron.',
            )),
            'apply_to_staff' => new BooleanField(array(
                'label' => 'Agent-created tickets',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Also require approval when an agent opens the ticket on behalf of a user'),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        foreach (array('pending_status' => 'open', 'returned_status' => 'open',
                'rejected_status' => 'closed', 'approved_status' => 'open')
                as $key => $state) {
            $id = self::choiceKey($config[$key] ?? null);
            if (!$id)
                continue;
            $S = TicketStatus::lookup($id);
            if (!$S || $S->getState() != $state)
                $errors[$key] = sprintf('Status must have state "%s"', $state);
        }
        if (!$errors)
            ITApprovalsSchema::ensure();
        return !$errors;
    }

    /* Accessors ------------------------------------------------------ */

    // ChoiceField values are persisted as {"key":"label"} JSON
    static function choiceKey($value) {
        if (is_array($value)) {
            reset($value);
            return key($value);
        }
        if (is_string($value) && $value && $value[0] == '{') {
            $parsed = JsonDataParser::parse($value);
            if (is_array($parsed)) {
                reset($parsed);
                return key($parsed);
            }
        }
        return $value;
    }

    function getInt($key) {
        return (int) self::choiceKey($this->get($key));
    }

    function getBool($key) {
        return (bool) $this->get($key);
    }

    // PluginConfig decrypts PasswordField values (to_php) when it loads
    function getGraphSecret() {
        return (string) ($this->get('graph_secret') ?: '');
    }
}
