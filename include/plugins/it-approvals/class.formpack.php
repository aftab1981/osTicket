<?php
/**
 * Form pack: ready-made IT request forms (Help Topic + custom form +
 * conditional-field rules + default approval matrix) installable from the
 * admin page, so every environment gets identical forms.
 *
 * Field types are osTicket's: text, memo, choices, datetime.
 * Rules: show `field` only when `when` has one of `in`; `required` makes it
 * mandatory while visible (checked in the browser and again on submission).
 * Level `when`/`in`: the approval level applies only when the answer matches.
 */
class ITApprovalsFormPack {

    static function definitions() {
        $dur = array('choices', 'Duration', 'duration', true,
            array('choices' => "perm:Permanent\ntemp:Temporary"));
        $end = array('datetime', 'End date', 'end_date', false,
            array('time' => false, 'hint' => 'Last day the access is needed'));
        $endRule = array('field' => 'end_date', 'when' => 'duration', 'in' => array('temp'), 'required' => true);

        return array(
        'internet' => array(
            'title' => 'Internet / Web Access Request',
            'instructions' => 'Request access to websites or internet categories blocked by default.',
            'fields' => array(
                array('choices', 'Access type', 'access_type', true, array('choices' =>
                    "std:Standard browsing\nsites:Specific website(s)\nsocial:Social media\nstream:Streaming / Media\ncloud:Cloud storage / File sharing\nfull:Unrestricted")),
                array('memo', 'Website URL(s)', 'urls', false, array('hint' => 'One per line')),
                array('memo', 'Business justification', 'justification', true, array()),
                $dur, $end,
                array('text', 'Device / Computer name', 'device', false, array()),
            ),
            'rules' => array(
                array('field' => 'urls', 'when' => 'access_type', 'in' => array('sites'), 'required' => true),
                $endRule,
            ),
            'levels' => array(
                array('name' => 'Line Manager', 'type' => 'manager'),
                array('name' => 'Head of IT', 'type' => 'approvers'),
            ),
        ),
        'vpn' => array(
            'title' => 'VPN Access Request',
            'instructions' => 'Remote access for staff, vendors and temporary guests.',
            'fields' => array(
                array('choices', 'Access category', 'access_category', true, array('choices' =>
                    "staff:Staff remote access\nvendor:Vendor / third party\nguest:Temporary guest / contractor")),
                array('choices', 'Device type', 'device_type', true, array('choices' =>
                    "company:Company laptop\nbyod:Personal device (BYOD)\nvendor:Vendor-managed device")),
                array('memo', 'Systems / resources required', 'resources', true, array()),
                array('memo', 'Business justification', 'justification', true, array()),
                $dur, $end,
                array('text', 'Vendor / company name', 'vendor_company', false, array()),
                array('text', 'Vendor contact name', 'vendor_contact', false, array()),
                array('text', 'Vendor contact email', 'vendor_email', false, array('validator' => 'email')),
                array('text', 'Vendor contact phone', 'vendor_phone', false, array()),
                array('text', 'Sponsor (staff member responsible)', 'sponsor', false, array()),
                array('choices', 'NDA / access agreement signed?', 'nda', false, array('choices' =>
                    "yes:Yes — signed\nno:Not yet")),
            ),
            'rules' => array(
                $endRule,
                array('field' => 'vendor_company', 'when' => 'access_category', 'in' => array('vendor', 'guest'), 'required' => true),
                array('field' => 'vendor_contact', 'when' => 'access_category', 'in' => array('vendor', 'guest'), 'required' => true),
                array('field' => 'vendor_email', 'when' => 'access_category', 'in' => array('vendor', 'guest'), 'required' => true),
                array('field' => 'vendor_phone', 'when' => 'access_category', 'in' => array('vendor', 'guest'), 'required' => false),
                array('field' => 'sponsor', 'when' => 'access_category', 'in' => array('vendor', 'guest'), 'required' => true),
                array('field' => 'nda', 'when' => 'access_category', 'in' => array('vendor', 'guest'), 'required' => true),
            ),
            'levels' => array(
                array('name' => 'Line Manager', 'type' => 'manager'),
                array('name' => 'Head of IT', 'type' => 'approvers'),
                array('name' => 'Information Security', 'type' => 'approvers',
                    'when' => 'access_category', 'in' => array('vendor', 'guest')),
            ),
        ),
        'sap' => array(
            'title' => 'SAP Change Request',
            'instructions' => 'Configuration, report, development, master-data or authorisation changes in SAP.',
            'fields' => array(
                array('choices', 'SAP module', 'sap_module', true, array('choices' =>
                    "FI:Finance (FI)\nCO:Controlling (CO)\nMM:Materials Management (MM)\nSD:Sales & Distribution (SD)\nPP:Production Planning (PP)\nPM:Plant Maintenance (PM)\nHCM:Human Capital (HCM)\nBW:Reporting / BW\nBASIS:Basis / Technical\nOTHER:Other")),
                array('choices', 'Change type', 'change_type', true, array('choices' =>
                    "config:Configuration change\nreport:New / changed report\ndev:Enhancement / development\ndata:Master data change\nauth:Roles & authorisations")),
                array('text', 'Business process', 'business_process', true, array()),
                array('memo', 'Current issue', 'current_issue', true, array()),
                array('memo', 'Proposed change', 'proposed_change', true, array()),
                array('memo', 'Expected benefit', 'expected_benefit', true, array()),
                array('choices', 'Business priority', 'business_priority', true, array('choices' =>
                    "low:Low\nmedium:Medium\nhigh:High\ncritical:Critical — business stopped")),
                array('text', 'Roles / users affected', 'auth_roles', false, array()),
                array('datetime', 'Target go-live date', 'go_live', false, array('time' => false)),
                array('memo', 'Other modules / teams affected', 'impact', false, array()),
            ),
            'rules' => array(
                array('field' => 'auth_roles', 'when' => 'change_type', 'in' => array('auth'), 'required' => true),
            ),
            'levels' => array(
                array('name' => 'Line Manager', 'type' => 'manager'),
                array('name' => 'Process Owner', 'type' => 'approvers'),
                array('name' => 'Head of IT', 'type' => 'approvers',
                    'when' => 'business_priority', 'in' => array('high', 'critical')),
            ),
        ),
        'email-network' => array(
            'title' => 'Email / Network Request',
            'instructions' => 'Mailboxes, distribution lists, shared folders, network ports, Wi-Fi and firewall rules.',
            'fields' => array(
                array('choices', 'Request type', 'request_type', true, array('choices' =>
                    "mailbox:New mailbox\nshared:Shared mailbox access\ndl:Distribution list change\nfolder:Shared folder access\nnetwork:Network port / Wi-Fi access\nfirewall:Firewall / port rule")),
                array('text', 'Mailbox / list / folder / resource', 'target', true, array()),
                array('text', 'User(s) the request is for', 'users', true, array()),
                array('choices', 'Access level', 'access_level', false, array('choices' =>
                    "read:Read only\nreadwrite:Read / write\nfull:Full access / owner")),
                array('memo', 'Location / port / Wi-Fi details', 'location', false, array()),
                array('memo', 'Source, destination, port and protocol', 'firewall_details', false, array()),
                array('memo', 'Business justification', 'justification', true, array()),
                $dur, $end,
            ),
            'rules' => array(
                array('field' => 'access_level', 'when' => 'request_type', 'in' => array('shared', 'folder', 'dl'), 'required' => true),
                array('field' => 'location', 'when' => 'request_type', 'in' => array('network'), 'required' => true),
                array('field' => 'firewall_details', 'when' => 'request_type', 'in' => array('firewall'), 'required' => true),
                $endRule,
            ),
            'levels' => array(
                array('name' => 'Line Manager', 'type' => 'manager'),
                array('name' => 'Head of IT', 'type' => 'approvers'),
                array('name' => 'Information Security', 'type' => 'approvers',
                    'when' => 'request_type', 'in' => array('firewall')),
            ),
        ),
        );
    }

    /** Status of each pack form in this installation */
    static function status() {
        $out = array();
        foreach (self::definitions() as $key => $def) {
            $form = DynamicForm::objects()->filter(array('title' => $def['title']))->first();
            $topicId = Topic::getIdByName($def['title']);
            $matrix = $topicId ? ITApp_Matrix::forTopic($topicId) : null;
            $out[$key] = array(
                'def' => $def,
                'form' => $form,
                'topic_id' => $topicId,
                'matrix' => $matrix,
                'installed' => $form && $topicId,
            );
        }
        return $out;
    }

    /**
     * Create (or complete) the form, Help Topic, field rules and matrix for
     * one pack entry. Existing objects are kept — safe to run twice.
     */
    static function install($key, $deptId, $fallbackApprover, &$errors) {
        $defs = self::definitions();
        if (!isset($defs[$key])) {
            $errors['err'] = 'Unknown form';
            return false;
        }
        $def = $defs[$key];
        if (!Dept::lookup($deptId)) {
            $errors['err'] = 'Select the fulfilment department';
            return false;
        }

        // 1. Form + fields
        $form = DynamicForm::objects()->filter(array('title' => $def['title']))->first();
        if (!$form) {
            $form = DynamicForm::create(array('title' => $def['title'], 'type' => 'G',
                'instructions' => $def['instructions'], 'notes' => 'Installed by IT Approvals form pack'));
            if (!$form->save(true)) {
                $errors['err'] = 'Unable to create the form';
                return false;
            }
        }
        $existing = array();
        foreach ($form->getDynamicFields() as $F)
            $existing[$F->get('name')] = true;
        $view = DynamicFormField::FLAG_ENABLED | DynamicFormField::FLAG_CLIENT_VIEW
            | DynamicFormField::FLAG_CLIENT_EDIT | DynamicFormField::FLAG_AGENT_VIEW
            | DynamicFormField::FLAG_AGENT_EDIT;
        $req = DynamicFormField::FLAG_CLIENT_REQUIRED | DynamicFormField::FLAG_AGENT_REQUIRED;
        foreach ($def['fields'] as $i => $f) {
            list($type, $label, $name, $required, $config) = $f;
            if (isset($existing[$name]))
                continue;
            $field = DynamicFormField::create(array('form_id' => $form->id,
                'type' => $type, 'label' => $label, 'name' => $name,
                'flags' => $view | ($required ? $req : 0), 'sort' => $i + 1,
                'configuration' => $config));
            $field->save();
        }

        // 2. Help Topic (Ticket Details form first, then this form)
        $topicId = Topic::getIdByName($def['title']);
        if (!$topicId) {
            $topic = Topic::create();
            $e = array();
            $topic->update(array('topic' => $def['title'], 'dept_id' => $deptId,
                'ispublic' => 1, 'status' => 'active', 'priority_id' => 0, 'sla_id' => 0,
                'topic_pid' => 0, 'notes' => $def['instructions'], 'assign' => 0,
                'number_format' => '', 'sequence_id' => 0), $e);
            if ($e || !$topic->getId()) {
                $errors['err'] = 'Unable to create the Help Topic: ' . implode('; ', $e);
                return false;
            }
            $topicId = $topic->getId();
        }
        // Link the standard Ticket Details form (subject + message) first, then
        // this form. (TicketForm::getInstance() is an entry, not the form.)
        if ($topic = Topic::lookup($topicId)) {
            $e = array();
            $topic->updateForms(array('forms' => array(
                TicketForm::objects()->one()->getId(), $form->id)), $e);
        }

        // 3. Conditional-field rules
        foreach ($def['rules'] as $r) {
            $rule = ITApp_FieldRule::objects()->filter(array(
                'form_id' => $form->id, 'field_name' => $r['field']))->first()
                ?: new ITApp_FieldRule(array('form_id' => $form->id, 'field_name' => $r['field']));
            $rule->depends_on = $r['when'];
            $rule->match_values = JsonDataEncoder::encode(array_values($r['in']));
            $rule->required = $r['required'] ? 1 : 0;
            $rule->save();
        }

        // 4. Approval matrix (named approvers start as the fallback approver)
        if (!ITApp_Matrix::forTopic($topicId)) {
            $levels = array();
            foreach ($def['levels'] as $L) {
                $levels[] = array(
                    'name' => $L['name'], 'type' => $L['type'],
                    'approvers' => $L['type'] == 'approvers' && $fallbackApprover
                        ? array(strtolower($fallbackApprover)) : array(),
                    'when' => $L['when'] ?? '', 'in' => $L['in'] ?? array(),
                );
            }
            $m = new ITApp_Matrix(array('topic_id' => $topicId));
            $m->enabled = 1;
            $m->fulfil_dept_id = (int) $deptId;
            $m->setLevels($levels);
            $m->updated = SqlFunction::NOW();
            $m->save();
        }
        return $topicId;
    }
}
