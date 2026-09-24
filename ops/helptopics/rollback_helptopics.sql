-- =============================================================================
-- Rollback for ops/helptopics/seed_helptopics.php
--
-- Undoes everything recorded in ost_helptopics_seed_log:
--   * legacy topics get their original name / status / order back
--   * adopted topics get their original name / status / order / dept / priority back
--   * topics, forms and fields the seed created are DELETED only if no ticket
--     uses them; otherwise they are just disabled so ticket history stays intact
--   * seed-created filters are deleted; the "Internal domains only" order restored
--   * settings (topic sort mode, default topic, Ticket Details instructions) restored
--   * seed-created departments are deleted only if nothing references them
--
-- Table prefix assumed "ost_". If TABLE_PREFIX in include/ost-config.php
-- differs, search/replace "ost_" before running.
--
-- Usage:   mysql -u <user> -p <osticket_db> < ops/helptopics/rollback_helptopics.sql
-- Preview first (read-only):
--   SELECT object_type, action, COUNT(*) FROM ost_helptopics_seed_log GROUP BY 1, 2;
-- =============================================================================

START TRANSACTION;

-- 1. Legacy topics: original name, status flags and order
UPDATE ost_help_topic t
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'topic' AND l.action = 'legacy' AND l.object_id = t.topic_id
   SET t.topic = l.old_name, t.flags = l.old_flags, t.sort = l.old_sort;

-- 2. Adopted topics (existed before the seed): name/flags/sort + dept, priority, public
UPDATE ost_help_topic t
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'topic' AND l.action = 'adopted' AND l.object_id = t.topic_id
   SET t.topic = l.old_name, t.flags = l.old_flags, t.sort = l.old_sort,
       t.dept_id     = SUBSTRING_INDEX(l.old_value, ',', 1),
       t.priority_id = SUBSTRING_INDEX(SUBSTRING_INDEX(l.old_value, ',', 2), ',', -1),
       t.ispublic    = SUBSTRING_INDEX(l.old_value, ',', -1);

-- 3. Created topics that tickets already use: keep, but disable (clear FLAG_ACTIVE = 2)
UPDATE ost_help_topic t
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'topic' AND l.action = 'created' AND l.object_id = t.topic_id
   SET t.flags = t.flags & ~2
 WHERE EXISTS (SELECT 1 FROM ost_ticket k WHERE k.topic_id = t.topic_id);

-- 4. Created topics with no tickets: remove their form links, then the topics.
--    Children first; a parent is removed only when none of its children remain.
DELETE hf FROM ost_help_topic_form hf
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'topic' AND l.action = 'created' AND l.object_id = hf.topic_id
 WHERE NOT EXISTS (SELECT 1 FROM ost_ticket k WHERE k.topic_id = hf.topic_id);

DELETE t FROM ost_help_topic t
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'topic' AND l.action = 'created' AND l.object_id = t.topic_id
 WHERE t.topic_pid <> 0
   AND NOT EXISTS (SELECT 1 FROM ost_ticket k WHERE k.topic_id = t.topic_id);

DELETE t FROM ost_help_topic t
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'topic' AND l.action = 'created' AND l.object_id = t.topic_id
 WHERE t.topic_pid = 0
   AND NOT EXISTS (SELECT 1 FROM ost_ticket k WHERE k.topic_id = t.topic_id)
   AND NOT EXISTS (SELECT 1 FROM (SELECT topic_pid FROM ost_help_topic) c WHERE c.topic_pid = t.topic_id);

-- 5. Created fields with no stored answers, then created forms that are now empty and unused
DELETE ff FROM ost_form_field ff
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'field' AND l.action = 'created' AND l.object_id = ff.id
 WHERE NOT EXISTS (SELECT 1 FROM ost_form_entry_values v WHERE v.field_id = ff.id);

DELETE hf FROM ost_help_topic_form hf
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'form' AND l.action = 'created' AND l.object_id = hf.form_id
 WHERE NOT EXISTS (SELECT 1 FROM ost_form_entry e WHERE e.form_id = hf.form_id);

DELETE f FROM ost_form f
  JOIN ost_helptopics_seed_log l
    ON l.object_type = 'form' AND l.action = 'created' AND l.object_id = f.id
 WHERE NOT EXISTS (SELECT 1 FROM ost_form_entry e WHERE e.form_id = f.id)
   AND NOT EXISTS (SELECT 1 FROM ost_form_field ff WHERE ff.form_id = f.id);

-- 6. Created filters (rules, actions, filter)
DELETE a FROM ost_filter_action a
  JOIN ost_helptopics_seed_log l ON l.object_type = 'filter' AND l.action = 'created' AND l.object_id = a.filter_id;
DELETE r FROM ost_filter_rule r
  JOIN ost_helptopics_seed_log l ON l.object_type = 'filter' AND l.action = 'created' AND l.object_id = r.filter_id;
DELETE f FROM ost_filter f
  JOIN ost_helptopics_seed_log l ON l.object_type = 'filter' AND l.action = 'created' AND l.object_id = f.id;

-- 7. "Internal domains only" filter execution order
UPDATE ost_filter f
  JOIN ost_helptopics_seed_log l ON l.object_type = 'filter_order' AND l.object_id = f.id
   SET f.execorder = l.old_value;

-- 8. Settings (help_topic_sort_mode, default_help_topic)
UPDATE ost_config c
  JOIN ost_helptopics_seed_log l ON l.object_type = 'setting' AND l.old_name = c.`key`
   SET c.value = l.old_value
 WHERE c.namespace = 'core';

-- 9. Ticket Details form instructions (HR notice)
UPDATE ost_form f
  JOIN ost_helptopics_seed_log l ON l.object_type = 'form_instructions' AND l.object_id = f.id
   SET f.instructions = l.old_value;

-- 10. Created departments nothing refers to any more
DELETE d FROM ost_department d
  JOIN ost_helptopics_seed_log l ON l.object_type = 'dept' AND l.action = 'created' AND l.object_id = d.id
 WHERE NOT EXISTS (SELECT 1 FROM ost_ticket k WHERE k.dept_id = d.id)
   AND NOT EXISTS (SELECT 1 FROM ost_help_topic t WHERE t.dept_id = d.id)
   AND NOT EXISTS (SELECT 1 FROM ost_staff s WHERE s.dept_id = d.id)
   AND NOT EXISTS (SELECT 1 FROM ost_staff_dept_access a WHERE a.dept_id = d.id);

-- 11. Rollback done: clear the log (the empty table is harmless and can be dropped later)
DELETE FROM ost_helptopics_seed_log;

COMMIT;

-- Check afterwards:
--   SELECT topic_id, topic_pid, topic, flags, sort FROM ost_help_topic ORDER BY sort;
