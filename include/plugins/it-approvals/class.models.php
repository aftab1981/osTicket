<?php
/**
 * ORM models for the IT Approvals plugin. All queries go through the
 * osTicket ORM, which escapes/binds every value.
 */

class ITApp_Matrix extends VerySimpleModel {
    static $meta = array(
        'table' => TABLE_PREFIX . 'itapp_matrix',
        'pk' => array('topic_id'),
    );

    const MAX_LEVELS = 5;

    static $level_types = array(
        'manager'   => 'Line manager (Entra ID)',
        'manager2'  => "Manager's manager (Entra ID)",
        'approvers' => 'Named approver(s) — any one',
    );

    static function forTopic($topic_id) {
        return static::lookup(array('topic_id' => (int) $topic_id));
    }

    function isEnabled() {
        return (bool) $this->enabled;
    }

    function getLevels() {
        $levels = JsonDataParser::parse($this->levels ?: '[]');
        return is_array($levels) ? $levels : array();
    }

    function setLevels(array $levels) {
        $this->levels = JsonDataEncoder::encode(array_values($levels));
    }
}

class ITApp_Request extends VerySimpleModel {
    static $meta = array(
        'table' => TABLE_PREFIX . 'itapp_request',
        'pk' => array('id'),
        'ordering' => array('-created'),
    );

    const STATE_PENDING   = 'pending';
    const STATE_RETURNED  = 'returned';
    const STATE_APPROVED  = 'approved';
    const STATE_REJECTED  = 'rejected';
    const STATE_CANCELLED = 'cancelled';

    static function forTicket($ticket_id) {
        return static::lookup(array('ticket_id' => (int) $ticket_id));
    }

    function getId() { return $this->id; }
    function getState() { return $this->state; }
    function isOpen() {
        return in_array($this->state,
            array(self::STATE_PENDING, self::STATE_RETURNED));
    }

    function getLevels() {
        $levels = JsonDataParser::parse($this->levels ?: '[]');
        return is_array($levels) ? $levels : array();
    }

    function getLevel($n) {
        $levels = $this->getLevels();
        return $levels[$n - 1] ?? null;
    }

    function getNumLevels() {
        return count($this->getLevels());
    }

    function getTicket() {
        return Ticket::lookup($this->ticket_id);
    }

    function getSteps() {
        return ITApp_Step::objects()
            ->filter(array('request_id' => $this->id))
            ->order_by('id');
    }

    function getCurrentStep() {
        return ITApp_Step::objects()
            ->filter(array(
                'request_id' => $this->id,
                'decision' => ITApp_Step::PENDING,
            ))
            ->order_by('-id')
            ->first();
    }

    function isRequester($email) {
        return $email && !strcasecmp($email, $this->requester_email);
    }

    function getStateLabel() {
        switch ($this->state) {
        case self::STATE_PENDING:   return 'Pending approval';
        case self::STATE_RETURNED:  return 'Returned for information';
        case self::STATE_APPROVED:  return 'Approved';
        case self::STATE_REJECTED:  return 'Rejected';
        case self::STATE_CANCELLED: return 'Cancelled';
        }
        return ucfirst($this->state);
    }

    function touch() {
        $this->updated = SqlFunction::NOW();
    }
}

class ITApp_Step extends VerySimpleModel {
    static $meta = array(
        'table' => TABLE_PREFIX . 'itapp_step',
        'pk' => array('id'),
        'ordering' => array('id'),
    );

    const PENDING   = 'pending';
    const APPROVED  = 'approved';
    const REJECTED  = 'rejected';
    const RETURNED  = 'returned';
    const SKIPPED   = 'skipped';
    const CANCELLED = 'cancelled';

    function getId() { return $this->id; }
    function isPending() { return $this->decision == self::PENDING; }

    function getApprovers() {
        return ITApp_StepApprover::objects()
            ->filter(array('step_id' => $this->id));
    }

    function getApproverEmails() {
        $emails = array();
        foreach ($this->getApprovers() as $A)
            $emails[] = strtolower($A->email);
        return $emails;
    }

    function getApproverNames() {
        $names = array();
        foreach ($this->getApprovers() as $A)
            $names[] = $A->name ?: $A->email;
        return $names;
    }

    function canBeDecidedBy(array $emails) {
        if (!$this->isPending())
            return false;
        $emails = array_map('strtolower', $emails);
        return (bool) array_intersect($emails, $this->getApproverEmails());
    }

    function getDecisionLabel() {
        switch ($this->decision) {
        case self::PENDING:   return 'Awaiting decision';
        case self::APPROVED:  return 'Approved';
        case self::REJECTED:  return 'Rejected';
        case self::RETURNED:  return 'Returned for information';
        case self::SKIPPED:   return 'Skipped';
        case self::CANCELLED: return 'Cancelled';
        }
        return ucfirst($this->decision);
    }
}

class ITApp_StepApprover extends VerySimpleModel {
    static $meta = array(
        'table' => TABLE_PREFIX . 'itapp_step_approver',
        'pk' => array('step_id', 'email'),
    );
}

class ITApp_FieldRule extends VerySimpleModel {
    static $meta = array(
        'table' => TABLE_PREFIX . 'itapp_field_rule',
        'pk' => array('id'),
        'ordering' => array('form_id', 'id'),
    );

    function getValues() {
        $v = JsonDataParser::parse($this->match_values ?: '[]');
        return is_array($v) ? array_map('strval', $v) : array();
    }

    function isRequired() {
        return (bool) $this->required;
    }

    /** Does a set of answers (name => list of values) make this field visible? */
    function isVisible(array $answers) {
        return (bool) array_intersect($answers[$this->depends_on] ?? array(), $this->getValues());
    }
}

class ITApp_ManagerCache extends VerySimpleModel {
    static $meta = array(
        'table' => TABLE_PREFIX . 'itapp_mgr_cache',
        'pk' => array('email'),
    );
}
