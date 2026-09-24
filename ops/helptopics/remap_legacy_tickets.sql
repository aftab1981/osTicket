-- =============================================================================
-- DRY RUN: suggest a new sub-category for historical tickets (requirement §8.5)
--
-- READ-ONLY. Nothing below changes data except the UPDATE at the very end,
-- which is commented out and must NOT be run without explicit approval.
--
-- Scope: tickets whose current topic is a (Legacy) topic, is disabled, or is
-- missing (topic_id = 0). Vendor/system mail (Vendor Notifications) is excluded.
-- Suggestions come from subject keywords (§4); first matching rule wins.
-- Table prefix assumed "ost_" (search/replace if TABLE_PREFIX differs).
-- Requires seed_helptopics.php to have been applied (new topic names must exist).
-- =============================================================================

DROP TEMPORARY TABLE IF EXISTS ht_remap_rules;
CREATE TEMPORARY TABLE ht_remap_rules (
    prio INT NOT NULL,              -- lower = checked first
    pattern VARCHAR(100) NOT NULL,  -- REGEXP on the lower-cased subject
    parent VARCHAR(128) NOT NULL,
    child VARCHAR(128) NOT NULL
);
INSERT INTO ht_remap_rules (prio, pattern, parent, child) VALUES
 (10, 'phish|suspicious (email|mail|link)|spam',                 'Security', 'Report Phishing / Suspicious Email'),
 (11, 'security|policy|awareness|malware|virus',                 'Security', 'Security Concern / Policy / Awareness'),
 (20, 'onboard|new joiner|new starter|new hire|joining',         'Onboarding / Offboarding', 'Onboarding – New Joiner'),
 (21, 'offboard|leaver|resign|last working day|exit',            'Onboarding / Offboarding', 'Offboarding – Leaver'),
 (22, 'experience letter|employment letter|salary certificate|noc letter', 'Onboarding / Offboarding', 'Employee Letters / Records'),
 (30, 'threatlocker|threat locker|unblock',                      'Software', 'ThreatLocker Unblock / Approval'),
 (31, 'activat|licen[cs]e key|product key',                      'Software', 'Windows / Office Activation & License'),
 (32, 'extension|add-on|addon',                                  'Software', 'Browser Extension Request'),
 (33, 'install|software|setup of|visual studio|sql server management', 'Software', 'Software Installation Request'),
 (40, 'password|locked|lock out|lockout|expired|reset',          'Accounts & Access', 'Password Reset / Expired / Locked Account'),
 (41, 'rdp|remote desktop|remote access|server access|vm access','Accounts & Access', 'Server / VM Remote Access (RDP)'),
 (42, 'create (a )?user|user creation|new user on',              'Accounts & Access', 'User Creation on Server / VM'),
 (43, 'uat|d365 access|environment access|security role',        'Accounts & Access', 'D365 / UAT Environment Access'),
 (44, 'shared folder|distribution list|group|dl ',               'Accounts & Access', 'Group / Shared Folder / Distribution List'),
 (96, 'access to|access for|saas|application access',            'Accounts & Access', 'Application / SaaS Access'),
 (50, 'down|not responding|unreachable|outage|crash',            'Servers & Environments', 'Server / VM Down or Not Responding'),
 (51, 'new vm|new server|provision|new environment',             'Servers & Environments', 'New VM / Server / Environment Request'),
 (52, 'ssl|certificate|dns|domain|website',                      'Servers & Environments', 'Domain / SSL / DNS / Website'),
 (53, 'database|backup|restore|storage|disk expan',              'Servers & Environments', 'Database / Storage / Backup'),
 (54, '(^| )git( |$)|gitlab|repo|deploy|pipeline|devops',     'Servers & Environments', 'Git Repo / Deployment / DevOps'),
 (60, 'vpn',                                                     'Network & Connectivity', 'VPN Setup / Not Working'),
 (61, 'firewall|port|whitelist|allowlist|ip address',            'Network & Connectivity', 'Port / Firewall / IP Whitelisting'),
 (62, 'internet|wi-?fi|wifi|network',                            'Network & Connectivity', 'Internet / Wi-Fi Issue'),
 (70, 'charger|mouse|keyboard|headset|monitor|battery|adapter',  'Hardware', 'Accessories (charger, mouse, headset, monitor)'),
 (71, 'slow|disk space|memory|ram|hang',                         'Hardware', 'Slow System / Disk Space / Memory'),
 (72, 'replace|new laptop|laptop request',                       'Hardware', 'Laptop Replacement / New Laptop'),
 (73, 'laptop|screen|boot|hardware',                             'Hardware', 'Laptop Fault (screen, boot, Wi-Fi, keyboard)'),
 (80, 'teams',                                                   'Email & Collaboration', 'Microsoft Teams Issue'),
 (81, 'shared mailbox|new email|email account|mailbox',          'Email & Collaboration', 'New Email Account / Shared Mailbox'),
 (82, 'sharepoint|onedrive',                                     'Email & Collaboration', 'SharePoint / OneDrive'),
 (83, 'outlook|email|e-mail|mail',                               'Email & Collaboration', 'Email / Outlook Issue'),
 (90, 'quote|quotation|purchase|renewal|procure|invoice',        'Procurement', 'Quote / Purchase / Renewal Request'),
 (95, 'd365|dynamics|mtm|hcms|youtrack',                         'Business Applications', 'D365 / MTM / HCMS / YouTrack Issue'),
 (97, 'power|electric|aircon|facilit',                           'Other', 'Facilities / Power (office)');

DROP TEMPORARY TABLE IF EXISTS ht_remap_candidates;
CREATE TEMPORARY TABLE ht_remap_candidates AS
SELECT t.ticket_id, t.number, t.topic_id AS old_topic_id,
       COALESCE(ot.topic, '(none)') AS old_topic,
       c.subject,
       (SELECT r.prio FROM ht_remap_rules r
         WHERE LOWER(c.subject) REGEXP r.pattern ORDER BY r.prio LIMIT 1) AS rule_prio
  FROM ost_ticket t
  JOIN ost_ticket__cdata c ON c.ticket_id = t.ticket_id
  LEFT JOIN ost_help_topic ot ON ot.topic_id = t.topic_id
  LEFT JOIN ost_department d ON d.id = t.dept_id
 WHERE (t.topic_id = 0 OR ot.topic_id IS NULL OR ot.topic LIKE '%(Legacy)' OR (ot.flags & 2) = 0)
   AND COALESCE(d.name, '') <> 'Vendor Notifications';

DROP TEMPORARY TABLE IF EXISTS ht_remap_suggestions;
CREATE TEMPORARY TABLE ht_remap_suggestions AS
SELECT k.*, r.parent, r.child, nt.topic_id AS new_topic_id
  FROM ht_remap_candidates k
  LEFT JOIN ht_remap_rules r ON r.prio = k.rule_prio
  LEFT JOIN ost_help_topic np ON np.topic = r.parent AND np.topic_pid = 0
  LEFT JOIN ost_help_topic nt ON nt.topic = r.child AND nt.topic_pid = np.topic_id;

-- 1. Summary: how many tickets would move to each new sub-category
SELECT COALESCE(CONCAT(parent, ' / ', child), '(no keyword match — leave as is)') AS suggested_topic,
       new_topic_id, COUNT(*) AS tickets
  FROM ht_remap_suggestions
 GROUP BY suggested_topic, new_topic_id
 ORDER BY tickets DESC;

-- 2. Detail for review (export to CSV and spot-check before approving)
SELECT ticket_id, number, old_topic, subject,
       COALESCE(CONCAT(parent, ' / ', child), '—') AS suggested_topic, new_topic_id
  FROM ht_remap_suggestions
 ORDER BY suggested_topic, ticket_id;

-- 3. APPLY — ONLY AFTER WRITTEN APPROVAL. Take a fresh backup first.
--    Old tickets keep working either way; this only improves reporting.
--
-- START TRANSACTION;
-- UPDATE ost_ticket t
--   JOIN ht_remap_suggestions s ON s.ticket_id = t.ticket_id
--    SET t.topic_id = s.new_topic_id
--  WHERE s.new_topic_id IS NOT NULL;
-- COMMIT;
