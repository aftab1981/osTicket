<?php
/**
 * Internal Access Policy — keeps the helpdesk internal.
 *
 * 1. Allowed email domains
 *    - Tickets: a native osTicket ticket filter ("Reject ticket" when the
 *      sender's email does not match the allowed domains) is created and kept
 *      in sync with the setting. Filters run for web, email and API tickets
 *      and every rejection is written to the system log.
 *    - Portal sign-in (any method, including SSO auto-registration): a user
 *      from another domain is signed out immediately; an account created in
 *      that same sign-in is removed.
 * 2. Microsoft Entra ID only (portal users)
 *    - Password sign-in, self-registration and password reset are refused
 *      server-side; the sign-in page shows only the external (SSO) button.
 *    - Completing an SSO registration (account.php do=import) stays allowed.
 * Agents (staff panel) are not affected.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class InternalAccessConfig extends PluginConfig {

    function getOptions() {
        return array(
            'domains' => new TextareaField(array(
                'label' => 'Allowed email domains',
                'required' => true,
                'default' => "dhrp.com.au\narmf.com\narmup.com",
                'configuration' => array('rows' => 4, 'cols' => 40, 'html' => false),
                'hint' => 'One per line. Only these domains can open tickets or sign in to the portal.',
            )),
            'sso_only' => new BooleanField(array(
                'label' => 'Microsoft sign-in only',
                'default' => true,
                'configuration' => array('desc' =>
                    'Portal users must sign in with Microsoft Entra ID (password sign-in, self-registration and password reset are disabled)'),
            )),
            'message' => new TextboxField(array(
                'label' => 'Message for other domains',
                'default' => 'This service desk is for DHRP, ARMF and ARMUP staff only.',
                'configuration' => array('size' => 60, 'length' => 200),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        $bad = array();
        foreach (InternalAccessPlugin::parseDomains($config['domains'] ?? '') as $d)
            if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d))
                $bad[] = $d;
        if ($bad)
            $errors['domains'] = 'Invalid domain(s): ' . Format::htmlchars(implode(', ', $bad));
        elseif (!InternalAccessPlugin::parseDomains($config['domains'] ?? ''))
            $errors['domains'] = 'Enter at least one domain';
        return !$errors;
    }
}

class InternalAccessPlugin extends Plugin {

    var $config_class = 'InternalAccessConfig';

    // ost_filter.name is varchar(32)
    const FILTER_NAME = 'Internal domains only';

    private static $booted = false;
    private static $policy;

    function isMultiInstance() {
        return false;
    }

    static function parseDomains($text) {
        $out = array();
        foreach (preg_split('/[\s,;]+/', strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) as $d)
            $out[] = ltrim($d, '@');
        return array_values(array_unique($out));
    }

    static function getDomains() {
        return self::$policy ? self::parseDomains(self::$policy->get('domains')) : array();
    }

    static function isAllowed($email) {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        return $at !== false && in_array(substr($email, $at + 1), self::getDomains(), true);
    }

    static function getMessage() {
        return self::$policy ? (self::$policy->get('message') ?: 'Access restricted.') : '';
    }

    function bootstrap() {
        if (self::$booted)
            return;
        self::$booted = true;
        // Captured now: PluginManager clears the side-loaded config after bootstrap
        self::$policy = $this->getConfig();
        if (!self::getDomains())
            return;

        $this->syncFilter();
        Signal::connect('person.login', array($this, 'onLogin'));

        if (PHP_SAPI !== 'cli') {
            if (self::$policy->get('sso_only'))
                $this->enforceSsoOnly();
            elseif (preg_match('#(^|/)login\.php$#i', $_SERVER['SCRIPT_NAME'] ?? '')
                    && !preg_match('#/scp/#i', $_SERVER['SCRIPT_NAME'] ?? ''))
                // Still show the "not allowed" message after a refused sign-in
                ob_start(array($this, 'rewriteLoginPage'));
        }
    }

    /* Tickets: native reject filter ----------------------------------- */

    static function domainRegex(array $domains) {
        return '/@(' . implode('|', array_map(function($d) {
            return preg_quote($d, '/');
        }, $domains)) . ')$/i';
    }

    /** Create or update the reject filter so it always matches the setting */
    function syncFilter() {
        $regex = self::domainRegex(self::getDomains());
        $P = TABLE_PREFIX;
        $res = db_query("SELECT f.id, r.id AS rule_id, r.val FROM {$P}filter f
            LEFT JOIN {$P}filter_rule r ON r.filter_id = f.id AND r.what = 'email'
            WHERE f.name = " . db_input(self::FILTER_NAME) . " LIMIT 1");
        $row = $res ? db_fetch_array($res) : null;

        if (!$row) {
            // Order 99: vendor/system mail filters (ops/helptopics) must run first
            db_query("INSERT INTO {$P}filter SET execorder = 99, isactive = 1, flags = 0,
                status = 0, match_all_rules = 1, stop_onmatch = 1, target = 'Any',
                email_id = 0, name = " . db_input(self::FILTER_NAME) . ",
                notes = 'Managed by the Internal Access Policy plugin. Edit the allowed domains in the plugin settings.',
                created = NOW(), updated = NOW()");
            $filterId = db_insert_id();
            db_query("INSERT INTO {$P}filter_rule SET filter_id = " . db_input($filterId) . ",
                what = 'email', how = 'not_match', val = " . db_input($regex) . ",
                isactive = 1, notes = '', created = NOW(), updated = NOW()");
            db_query("INSERT INTO {$P}filter_action SET filter_id = " . db_input($filterId) . ",
                sort = 1, type = 'reject', configuration = '[]', updated = NOW()");
        }
        elseif ($row['val'] !== $regex) {
            if ($row['rule_id'])
                db_query("UPDATE {$P}filter_rule SET val = " . db_input($regex) . ",
                    how = 'not_match', isactive = 1, updated = NOW()
                    WHERE id = " . db_input($row['rule_id']));
            else
                db_query("INSERT INTO {$P}filter_rule SET filter_id = " . db_input($row['id']) . ",
                    what = 'email', how = 'not_match', val = " . db_input($regex) . ",
                    isactive = 1, notes = '', created = NOW(), updated = NOW()");
        }
    }

    /* Portal sign-in: domain check ------------------------------------- */

    function onLogin($person, $data) {
        global $ost, $thisstaff;
        if (!$person instanceof User || ($data['type'] ?? '') != 'login' || $thisstaff)
            return;
        $email = (string) $person->getEmail();
        if (self::isAllowed($email))
            return;

        // Sign the session out
        $_SESSION['_auth']['user'] = array();
        unset($_SESSION[':token']['client']);
        $_SESSION['_internal_access_denied'] = self::getMessage();

        // Remove an account created by this very sign-in (SSO auto-register)
        // (User::delete() refuses users who have tickets)
        if (strtotime((string) $person->created) > time() - 120)
            $person->delete();
        if ($ost)
            $ost->logWarning('Internal Access: portal sign-in refused',
                sprintf('%s is not in an allowed domain (%s)', $email,
                    implode(', ', self::getDomains())), false);
    }

    /* Microsoft sign-in only -------------------------------------------- */

    private function enforceSsoOnly() {
        $script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(scp|api|setup)$#i', $dir))
            return;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $do = strtolower((string) ($_REQUEST['do'] ?? ''));

        $deny = false;
        if ($script == 'login.php' && $method == 'POST' && isset($_POST['lpasswd']))
            $deny = 'Sign in with your Microsoft work account.';
        elseif ($script == 'pwreset.php')
            $deny = 'Passwords are managed by Microsoft. Use "Forgot password" on the Microsoft sign-in page.';
        elseif ($script == 'account.php' && ($do == 'create' || ($method == 'GET' && !$do && empty($_SESSION['_auth']['user']))))
            $deny = 'Accounts are created automatically the first time you sign in with Microsoft.';

        if ($deny) {
            $_SESSION['_internal_access_denied'] = $deny;
            Http::redirect(ROOT_PATH . 'login.php');
            exit;
        }

        // Sign-in page: show only the Microsoft button (+ any denial message)
        if ($script == 'login.php')
            ob_start(array($this, 'rewriteLoginPage'));
    }

    function rewriteLoginPage($html) {
        if (strpos($html, 'id="clientLogin"') === false)
            return $html;
        $msg = $_SESSION['_internal_access_denied'] ?? '';
        unset($_SESSION['_internal_access_denied']);

        if (!self::$policy->get('sso_only')) {
            if ($msg && ($pos = strpos($html, '<form action="login.php"')) !== false)
                $html = substr_replace($html, '<div id="msg_error">' . Format::htmlchars($msg) . '</div>', $pos, 0);
            return $html;
        }
        // Drop the username/password box and the "create an account" line
        $html = preg_replace_callback('#<div class="login-box">.*?</p>\s*</div>#s',
            function() { return ''; }, $html, 1);
        $html = preg_replace_callback('#<div style="margin-bottom: 5px">\s*[^<]*<a href="account\.php\?do=create">.*?</div>#s',
            function() { return ''; }, $html, 1);

        $notice = '<div class="pt-sso-note"><strong>Sign in with your Microsoft work account</strong>'
            . '<span>' . Format::htmlchars(self::getMessage()) . '</span></div>';
        if ($msg)
            $notice = '<div id="msg_error">' . Format::htmlchars($msg) . '</div>' . $notice;
        $pos = strpos($html, '<form action="login.php"');
        if ($pos !== false)
            $html = substr_replace($html, $notice, $pos, 0);
        return $html;
    }
}
