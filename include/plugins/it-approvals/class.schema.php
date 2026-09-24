<?php
/**
 * Database schema for the IT Approvals plugin.
 *
 * Tables are created idempotently (CREATE TABLE IF NOT EXISTS) on plugin
 * enable and on config save. Bump SCHEMA_VERSION and add ALTERs to
 * ::upgrade() when the schema changes.
 */
class ITApprovalsSchema {

    const SCHEMA_VERSION = 1;

    static function tables() {
        $P = TABLE_PREFIX;
        return array(
            // One approval matrix per Help Topic. `levels` is a JSON list of
            // {name, type: manager|manager2|approvers, approvers: [email]}
            "{$P}itapp_matrix" => "(
                `topic_id` int(11) unsigned NOT NULL,
                `enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
                `fulfil_dept_id` int(11) unsigned NOT NULL DEFAULT 0,
                `levels` text NOT NULL,
                `updated` datetime NOT NULL,
                PRIMARY KEY (`topic_id`)
            )",
            // One row per ticket that entered the approval workflow.
            // `levels` is a snapshot of the matrix at submission time.
            "{$P}itapp_request" => "(
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `ticket_id` int(11) unsigned NOT NULL,
                `topic_id` int(11) unsigned NOT NULL DEFAULT 0,
                `user_id` int(11) unsigned NOT NULL DEFAULT 0,
                `requester_email` varchar(255) NOT NULL DEFAULT '',
                `levels` text NOT NULL,
                `fulfil_dept_id` int(11) unsigned NOT NULL DEFAULT 0,
                `current_level` tinyint(3) unsigned NOT NULL DEFAULT 0,
                `cycle` smallint(5) unsigned NOT NULL DEFAULT 1,
                `state` varchar(16) NOT NULL DEFAULT 'pending',
                `created` datetime NOT NULL,
                `updated` datetime NOT NULL,
                `closed` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ticket_id` (`ticket_id`),
                KEY `state` (`state`),
                KEY `user_id` (`user_id`)
            )",
            // One row per level activation (a returned + resubmitted level
            // gets a new row with the next cycle number)
            "{$P}itapp_step" => "(
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `request_id` int(11) unsigned NOT NULL,
                `level` tinyint(3) unsigned NOT NULL,
                `cycle` smallint(5) unsigned NOT NULL DEFAULT 1,
                `level_name` varchar(128) NOT NULL DEFAULT '',
                `decision` varchar(16) NOT NULL DEFAULT 'pending',
                `note` varchar(255) NOT NULL DEFAULT '',
                `decided_by_email` varchar(255) DEFAULT NULL,
                `decided_by_name` varchar(255) DEFAULT NULL,
                `comments` text,
                `created` datetime NOT NULL,
                `decided` datetime DEFAULT NULL,
                `reminded` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `request_id` (`request_id`),
                KEY `decision` (`decision`)
            )",
            // Who may act on a step (any one of them)
            "{$P}itapp_step_approver" => "(
                `step_id` int(11) unsigned NOT NULL,
                `email` varchar(255) NOT NULL,
                `name` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`step_id`, `email`),
                KEY `email` (`email`)
            )",
            // Conditional fields: show `field_name` only when `depends_on`
            // has one of `match_values` (JSON); `required` while visible
            "{$P}itapp_field_rule" => "(
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `form_id` int(11) unsigned NOT NULL,
                `field_name` varchar(64) NOT NULL,
                `depends_on` varchar(64) NOT NULL,
                `match_values` text NOT NULL,
                `required` tinyint(1) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `form_field` (`form_id`, `field_name`)
            )",
            // Entra ID manager lookups
            "{$P}itapp_mgr_cache" => "(
                `email` varchar(255) NOT NULL,
                `manager_email` varchar(255) DEFAULT NULL,
                `manager_name` varchar(255) DEFAULT NULL,
                `fetched` datetime NOT NULL,
                PRIMARY KEY (`email`)
            )",
        );
    }

    static function ensure() {
        static $done = false;
        if ($done)
            return true;

        foreach (self::tables() as $table => $ddl) {
            $sql = "CREATE TABLE IF NOT EXISTS `$table` $ddl"
                . " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            if (!db_query($sql))
                return false;
        }
        return $done = true;
    }
}
