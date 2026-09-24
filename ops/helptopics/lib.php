<?php
/**
 * Shared bootstrap + data definitions for the Help Topic rebuild.
 * Run the scripts from the osTicket root, e.g.
 *   php ops/helptopics/seed_helptopics.php            (dry run)
 * DB credentials come from include/ost-config.php at runtime.
 */

if (PHP_SAPI !== 'cli')
    die("CLI only\n");

// osTicket bootstrap — must run at file scope (its includes set globals)
$HT_ROOT = realpath(__DIR__ . '/../..');
chdir($HT_ROOT);
$_SERVER['SCRIPT_NAME'] = basename($_SERVER['argv'][0] ?? 'helptopics.php');
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require_once $HT_ROOT . '/bootstrap.php';
Bootstrap::loadConfig();
Bootstrap::defineTables(TABLE_PREFIX);
Bootstrap::i18n_prep();
Bootstrap::loadCode();
Bootstrap::connect();
if (!($ost = osTicket::start()) || !($cfg = $ost->getConfig()))
    die("Unable to load osTicket configuration\n");

function ht_version_ok() {
    // Nested topics + dynamic forms need 1.10+
    return version_compare(MAJOR_VERSION, '1.10', '>=');
}

/* ---------------------------------------------------------------------
 * Field helpers: [type, label, name, required, configuration]
 * Types are osTicket's: text, memo (textarea), choices, datetime, files
 * ------------------------------------------------------------------- */
function F_text($label, $name, $req=false, $cfg=array())  { return array('text', $label, $name, $req, $cfg + array('size' => 40, 'length' => 255)); }
function F_memo($label, $name, $req=false, $cfg=array())  { return array('memo', $label, $name, $req, $cfg + array('rows' => 4, 'cols' => 40, 'html' => false)); }
function F_date($label, $name, $req=false)                 { return array('datetime', $label, $name, $req, array('time' => false, 'future' => false)); }
function F_file($label, $name, $req=false)                 { return array('files', $label, $name, $req, array('size' => 10485760, 'max' => 5)); }
function F_choice($label, $name, $req, array $choices, $multi=false) {
    $lines = array();
    foreach ($choices as $k => $v)
        $lines[] = "$k:$v";
    return array('choices', $label, $name, $req, array('choices' => implode("\n", $lines),
        'multiselect' => $multi, 'prompt' => $multi ? '' : 'Select'));
}
function F_yesno($label, $name, $req=true) { return F_choice($label, $name, $req, array('yes' => 'Yes', 'no' => 'No')); }

function ht_envs() { return array('dev' => 'Dev', 'uat' => 'UAT', 'staging' => 'Staging', 'prod' => 'Prod'); }

/* ---------------------------------------------------------------------
 * Shared forms (§5) — built once, attached to many topics
 * ------------------------------------------------------------------- */
function ht_shared_forms() {
    $access = array('email' => 'Email', 'teams' => 'Teams', 'd365' => 'D365',
        'server' => 'Server / VM', 'vpn' => 'VPN', 'threatlocker' => 'ThreatLocker');
    return array(
        'Server Details' => array(
            F_text('Server IP / Hostname', 'server_host', true),
            F_choice('Environment', 'environment', false, ht_envs()),
        ),
        'Asset Details' => array(
            F_text('Asset tag', 'asset_tag', false),
        ),
        'Justification' => array(
            F_memo('Business justification', 'justification', true),
        ),
        'Manager Approval' => array(
            F_yesno('Manager approval obtained', 'manager_approval', true),
            F_text('Manager name', 'manager_name', false),
        ),
        // Common to joiners and leavers; the rest of §4.6 is in each HT form
        'Joiner / Leaver' => array(
            F_text('Full name', 'full_name', true),
            F_choice('Access (grant for joiners, revoke for leavers)', 'access_items', false, $access, true),
        ),
    );
}

/* ---------------------------------------------------------------------
 * Taxonomy (§4). Each child: [name, priority, shared forms[], own fields[]]
 * Own fields go in a form named "HT – <child name>".
 * "Description (R)" topics use the standard, required Issue Details field.
 * ------------------------------------------------------------------- */
function ht_taxonomy() {
    $envs = ht_envs();
    return array(
        array('Accounts & Access', 'IT Support', array(
            array('Server / VM Remote Access (RDP)', 'High', array('Server Details', 'Justification'), array(
                F_text('Access level needed', 'access_level'))),
            array('Password Reset / Expired / Locked Account', 'High', array(), array(
                F_choice('System', 'system', true, array('ad' => 'Windows / AD', 'server' => 'Server',
                    'm365' => 'Email / M365', 'd365' => 'D365', 'vpn' => 'VPN', 'other' => 'Other')),
                F_text('Username', 'username', true))),
            array('User Creation on Server / VM', 'Normal', array('Server Details', 'Manager Approval'), array(
                F_text('User full name', 'new_user_name', true),
                F_text('Role / access level', 'role_access', true))),
            array('Application / SaaS Access', 'Normal', array('Justification'), array(
                F_text('Application name', 'app_name', true),
                F_text('Access level', 'access_level'))),
            array('Group / Shared Folder / Distribution List', 'Normal', array(), array(
                F_text('Group or folder name', 'group_name', true),
                F_choice('Action', 'group_action', true, array('add' => 'Add', 'remove' => 'Remove')))),
            array('D365 / UAT Environment Access', 'Normal', array(), array(
                F_choice('Environment', 'd365_env', true, $envs),
                F_text('Security role needed', 'security_role'))),
        )),
        array('Servers & Environments', 'Infrastructure', array(
            array('Server / VM Down or Not Responding', 'Emergency', array('Server Details'), array(
                F_memo('Error message', 'error_message', false, array('placeholder' => 'Paste the error; attach a screenshot below')))),
            array('New VM / Server / Environment Request', 'Normal', array(), array(
                F_memo('Purpose', 'purpose', true),
                F_choice('Environment', 'environment', true, $envs),
                F_text('vCPU / RAM / Disk', 'sizing'),
                F_text('Operating system', 'os'),
                F_date('Required-by date', 'required_by', true))),
            array('Domain / SSL / DNS / Website', 'High', array(), array(
                F_text('Domain / URL', 'domain_url', true),
                F_choice('Request type', 'dns_request_type', true, array('new' => 'New record',
                    'renewal' => 'Renewal', 'error' => 'Error', 'other' => 'Other')))),
            array('Database / Storage / Backup', 'High', array('Server Details'), array(
                F_text('Database name', 'db_name'),
                F_choice('Request type', 'storage_request', true, array('restore' => 'Restore',
                    'backup' => 'Backup', 'expand' => 'Expand storage', 'remove' => 'Remove')))),
            array('Git Repo / Deployment / DevOps', 'Normal', array(), array(
                F_text('Repo / project name', 'repo_name', true),
                F_choice('Target environment', 'target_env', true, $envs))),
        )),
        array('Software', 'IT Support', array(
            array('Software Installation Request', 'Normal', array('Justification', 'Asset Details'), array(
                F_text('Software name & version', 'software_name', true))),
            array('ThreatLocker Unblock / Approval', 'High', array(), array(
                F_text('Application / file name', 'blocked_item', true),
                F_memo('Path of the blocked item', 'block_path', true, array('placeholder' => 'Paste the path, or attach a screenshot of the block below')))),
            array('Windows / Office Activation & License', 'Normal', array('Asset Details'), array(
                F_text('Product', 'product', true))),
            array('Browser Extension Request', 'Low', array('Justification'), array(
                F_text('Extension name / link', 'extension', true))),
        )),
        array('Hardware', 'IT Support', array(
            array('Laptop Fault (screen, boot, Wi-Fi, keyboard)', 'High', array('Asset Details'), array(
                F_memo('Fault description', 'fault_description', true),
                F_file('Photo', 'fault_photo'))),
            array('Accessories (charger, mouse, headset, monitor)', 'Normal', array(), array(
                F_choice('Item', 'accessory', true, array('charger' => 'Charger', 'mouse' => 'Mouse',
                    'keyboard' => 'Keyboard', 'headset' => 'Headset', 'monitor' => 'Monitor',
                    'battery' => 'Battery', 'other' => 'Other')),
                F_choice('Reason', 'accessory_reason', true, array('new' => 'New', 'faulty' => 'Faulty', 'lost' => 'Lost')))),
            array('Slow System / Disk Space / Memory', 'Normal', array('Asset Details'), array(
                F_memo('Symptoms', 'symptoms'))),
            array('Laptop Replacement / New Laptop', 'Normal', array('Asset Details', 'Manager Approval'), array(
                F_memo('Reason', 'replacement_reason', true))),
        )),
        array('Network & Connectivity', 'Infrastructure', array(
            array('VPN Setup / Not Working', 'High', array(), array(
                F_choice('Request type', 'vpn_request', true, array('new' => 'New setup', 'notconnecting' => 'Not connecting')),
                F_text('Operating system', 'os'),
                F_memo('Error message', 'error_message'))),
            array('Port / Firewall / IP Whitelisting', 'Normal', array('Justification'), array(
                F_text('Source IP', 'source_ip', true),
                F_text('Destination IP', 'destination_ip', true),
                F_text('Port / protocol', 'port_protocol', true))),
            array('Internet / Wi-Fi Issue', 'Normal', array(), array(
                F_choice('Location', 'location', true, array('office' => 'Office', 'home' => 'Home')))),
        )),
        array('Onboarding / Offboarding', 'IT Support', array(
            array('Onboarding – New Joiner', 'High', array('Joiner / Leaver'), array(
                F_text('Designation', 'designation', true),
                F_text('Department', 'joiner_department', true),
                F_date('Start date', 'start_date', true),
                F_yesno('Laptop required', 'laptop_required', true))),
            array('Offboarding – Leaver', 'High', array('Joiner / Leaver'), array(
                F_date('Last working day', 'last_day', true),
                F_yesno('Laptop return', 'laptop_return', true),
                F_text('Forward mailbox to', 'forward_mailbox'))),
            array('Employee Letters / Records', 'Low', array(), array(
                F_text('Request type', 'letter_type', true))),
        )),
        array('Email & Collaboration', 'IT Support', array(
            array('Email / Outlook Issue', 'Normal', array(), array()),
            array('Microsoft Teams Issue', 'Normal', array(), array()),
            array('New Email Account / Shared Mailbox', 'Normal', array(), array(
                F_text('Mailbox name', 'mailbox_name', true),
                F_text('Owner / users', 'mailbox_owners', true))),
            array('SharePoint / OneDrive', 'Normal', array(), array(
                F_text('Site / folder URL', 'site_url'))),
        )),
        array('Procurement', 'IT Management', array(
            array('Quote / Purchase / Renewal Request', 'Normal', array(), array(
                F_text('Item / service', 'item_service', true),
                F_text('Vendor', 'vendor'),
                F_text('Quantity', 'quantity'),
                F_text('Budget / cost centre', 'cost_centre'),
                F_date('Required-by date', 'required_by'))),
        )),
        array('Business Applications', 'Applications', array(
            array('D365 / MTM / HCMS / YouTrack Issue', 'High', array(), array(
                F_choice('Application', 'application', true, array('d365' => 'D365', 'mtm' => 'MTM',
                    'hcms' => 'HCMS', 'youtrack' => 'YouTrack', 'other' => 'Other')),
                F_choice('Environment', 'app_environment', false, $envs),
                F_memo('Steps to reproduce', 'steps', true))),
        )),
        array('Security', 'Cyber Security', array(
            array('Report Phishing / Suspicious Email', 'High', array(), array(
                F_file('Attach the suspicious email', 'phish_email', true),
                F_yesno('Did you click a link or enter credentials?', 'clicked', true))),
            array('Security Concern / Policy / Awareness', 'Normal', array(), array()),
        )),
        array('Other', 'IT Support', array(
            array('General Enquiry', 'Normal', array(), array()),
            array('Facilities / Power (office)', 'Low', array(), array(
                F_text('Location', 'facility_location', true))),
        )),
    );
}

/** Departments the taxonomy needs (§4) + the vendor department (§6) */
function ht_required_depts() {
    $out = array();
    foreach (ht_taxonomy() as $cat)
        $out[$cat[1]] = true;
    return array_keys($out);
}

const HT_VENDOR_DEPT = 'Vendor Notifications';
const HT_FORM_PREFIX = 'HT – ';
const HT_HR_NOTICE = 'Leave and attendance requests go to HR, not IT.';

/** Vendor / system mail filters (§6). Rules: [what, how, value] — "subject" is resolved to its field id */
function ht_filters($micron21Domain='') {
    $micron = array(array('name', 'contains', 'Micron21'), array('email', 'contains', '@micron21.'));
    if ($micron21Domain)
        $micron[] = array('email', 'ends', '@' . ltrim($micron21Domain, '@'));
    return array(
        array('name' => 'MS Dynamics Notices', 'order' => 1, 'priority' => 'Low', 'noresp' => true, 'rules' => array(
            array('name', 'contains', 'Microsoft Dynamics'),
            array('subject', 'contains', 'Servicing operation status change'),
            array('subject', 'contains', 'Database operation status change'),
            array('subject', 'contains', 'A service update is scheduled'))),
        array('name' => 'Micron21', 'order' => 2, 'priority' => null, 'noresp' => false, 'rules' => $micron),
        array('name' => 'System Alerts', 'order' => 3, 'priority' => null, 'noresp' => true, 'rules' => array(
            array('subject', 'contains', 'Stale Ticket Alert'))),
    );
}

function ht_topic_status($flags) {
    if ($flags & Topic::FLAG_ACTIVE) return 'Active';
    if ($flags & Topic::FLAG_ARCHIVED) return 'Archived';
    return 'Disabled';
}
