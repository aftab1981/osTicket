<?php
/**
 * Approval reporting: volumes, outcomes and turnaround per request type,
 * per approval level and per approver. Read-only aggregate SQL; the only
 * interpolated values are integers.
 */
class ITApprovalsReports {

    static $periods = array(7 => 'Last 7 days', 30 => 'Last 30 days',
        90 => 'Last 90 days', 365 => 'Last 12 months');

    private $days;

    function __construct($days) {
        $this->days = isset(self::$periods[(int) $days]) ? (int) $days : 30;
    }

    function getDays() { return $this->days; }

    private function rows($sql) {
        $out = array();
        if ($res = db_query($sql))
            while ($row = db_fetch_array($res))
                $out[] = $row;
        return $out;
    }

    private function since() {
        return sprintf('NOW() - INTERVAL %d DAY', $this->days);
    }

    /** One row per Help Topic */
    function byTopic() {
        $P = TABLE_PREFIX;
        $rows = $this->rows("SELECT r.topic_id,
                COUNT(*) AS submitted,
                SUM(r.state = 'approved') AS approved,
                SUM(r.state = 'rejected') AS rejected,
                SUM(r.state = 'cancelled') AS cancelled,
                SUM(r.state IN ('pending', 'returned')) AS open_now,
                SUM(r.cycle > 1 OR EXISTS (SELECT 1 FROM {$P}itapp_step s
                    WHERE s.request_id = r.id AND s.decision = 'returned')) AS returned_once,
                AVG(CASE WHEN r.state = 'approved'
                    THEN TIMESTAMPDIFF(MINUTE, r.created, r.closed) END) AS avg_approve_min
            FROM {$P}itapp_request r
            WHERE r.created >= {$this->since()}
            GROUP BY r.topic_id ORDER BY submitted DESC");
        foreach ($rows as &$r) {
            $T = Topic::lookup($r['topic_id']);
            $r['topic'] = $T ? $T->getFullName() : ('#' . $r['topic_id']);
            $decided = $r['approved'] + $r['rejected'];
            $r['approval_rate'] = $decided ? round(100 * $r['approved'] / $decided) : null;
        }
        return $rows;
    }

    /** One row per (topic, level) — where requests wait */
    function byLevel() {
        $P = TABLE_PREFIX;
        $rows = $this->rows("SELECT r.topic_id, s.level, s.level_name,
                SUM(s.decision IN ('approved', 'rejected', 'returned')) AS decisions,
                SUM(s.decision = 'approved') AS approved,
                SUM(s.decision = 'rejected') AS rejected,
                SUM(s.decision = 'returned') AS returned,
                SUM(s.decision = 'skipped') AS skipped,
                SUM(s.decision = 'pending') AS pending_now,
                AVG(CASE WHEN s.decision IN ('approved', 'rejected', 'returned')
                    THEN TIMESTAMPDIFF(MINUTE, s.created, s.decided) END) AS avg_min,
                MAX(CASE WHEN s.decision IN ('approved', 'rejected', 'returned')
                    THEN TIMESTAMPDIFF(MINUTE, s.created, s.decided) END) AS max_min,
                MAX(CASE WHEN s.decision = 'pending'
                    THEN TIMESTAMPDIFF(MINUTE, s.created, NOW()) END) AS oldest_pending_min
            FROM {$P}itapp_step s
            JOIN {$P}itapp_request r ON r.id = s.request_id
            WHERE s.created >= {$this->since()} AND s.level > 0
            GROUP BY r.topic_id, s.level, s.level_name
            ORDER BY r.topic_id, s.level");
        foreach ($rows as &$r) {
            $T = Topic::lookup($r['topic_id']);
            $r['topic'] = $T ? $T->getFullName() : ('#' . $r['topic_id']);
        }
        return $rows;
    }

    /** One row per approver who decided or has something pending */
    function byApprover() {
        $P = TABLE_PREFIX;
        $decided = $this->rows("SELECT s.decided_by_email AS email,
                MAX(s.decided_by_name) AS name,
                COUNT(*) AS decisions,
                SUM(s.decision = 'approved') AS approved,
                SUM(s.decision = 'rejected') AS rejected,
                SUM(s.decision = 'returned') AS returned,
                AVG(TIMESTAMPDIFF(MINUTE, s.created, s.decided)) AS avg_min
            FROM {$P}itapp_step s
            WHERE s.decided >= {$this->since()}
              AND s.decision IN ('approved', 'rejected', 'returned')
              AND s.decided_by_email IS NOT NULL AND s.level > 0
            GROUP BY s.decided_by_email");
        $pending = $this->rows("SELECT a.email, MAX(a.name) AS name, COUNT(*) AS pending_now,
                MAX(TIMESTAMPDIFF(MINUTE, s.created, NOW())) AS oldest_min
            FROM {$P}itapp_step s
            JOIN {$P}itapp_step_approver a ON a.step_id = s.id
            JOIN {$P}itapp_request r ON r.id = s.request_id
            WHERE s.decision = 'pending' AND r.state = 'pending'
            GROUP BY a.email");

        $out = array();
        foreach ($decided as $r)
            $out[strtolower($r['email'])] = $r + array('pending_now' => 0, 'oldest_min' => null);
        foreach ($pending as $p) {
            $k = strtolower($p['email']);
            if (!isset($out[$k]))
                $out[$k] = array('email' => $p['email'], 'name' => $p['name'], 'decisions' => 0,
                    'approved' => 0, 'rejected' => 0, 'returned' => 0, 'avg_min' => null);
            $out[$k]['pending_now'] = $p['pending_now'];
            $out[$k]['oldest_min'] = $p['oldest_min'];
        }
        uasort($out, function($a, $b) {
            return ($b['pending_now'] <=> $a['pending_now']) ?: ($b['decisions'] <=> $a['decisions']);
        });
        return array_values($out);
    }

    /** Pending steps older than $hours */
    function overdue($hours) {
        $P = TABLE_PREFIX;
        $rows = $this->rows(sprintf("SELECT r.id AS request_id, r.ticket_id, r.requester_email,
                s.level, s.level_name, s.created,
                TIMESTAMPDIFF(MINUTE, s.created, NOW()) AS age_min,
                (SELECT GROUP_CONCAT(COALESCE(NULLIF(a.name, ''), a.email) SEPARATOR ', ')
                   FROM {$P}itapp_step_approver a WHERE a.step_id = s.id) AS approvers
            FROM {$P}itapp_step s
            JOIN {$P}itapp_request r ON r.id = s.request_id
            WHERE s.decision = 'pending' AND r.state = 'pending'
              AND s.created < NOW() - INTERVAL %d HOUR
            ORDER BY s.created LIMIT 100", max(1, (int) $hours)));
        foreach ($rows as &$r) {
            $T = Ticket::lookup($r['ticket_id']);
            $r['number'] = $T ? $T->getNumber() : '';
            $r['subject'] = $T ? $T->getSubject() : '';
        }
        return $rows;
    }

    static function duration($minutes) {
        if ($minutes === null || $minutes === '')
            return '—';
        $m = (int) round($minutes);
        if ($m < 60)
            return $m . ' min';
        if ($m < 48 * 60)
            return round($m / 60, 1) . ' h';
        return round($m / 1440, 1) . ' d';
    }

    function csv() {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array('Request type', 'Level', 'Level name', 'Decisions', 'Approved',
            'Rejected', 'Returned', 'Skipped', 'Pending now', 'Avg decision (h)', 'Max decision (h)'));
        foreach ($this->byLevel() as $r)
            fputcsv($out, array($r['topic'], $r['level'], $r['level_name'], $r['decisions'],
                $r['approved'], $r['rejected'], $r['returned'], $r['skipped'], $r['pending_now'],
                $r['avg_min'] === null ? '' : round($r['avg_min'] / 60, 2),
                $r['max_min'] === null ? '' : round($r['max_min'] / 60, 2)));
        rewind($out);
        return stream_get_contents($out);
    }
}
