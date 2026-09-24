<?php
/**
 * IT Approvals — multi-level approval workflow for osTicket Help Topics.
 *
 * Integration points (no core modifications):
 *   ticket.create.validated  hold approval-enabled tickets
 *   ticket.created           start the workflow
 *   threadentry.created      auto-resubmit when the requester replies
 *   cron                     reminders
 *   ajax.client              client portal pages  (/ajax.php/itapprovals)
 *   ajax.scp                 SCP admin pages      (/scp/ajax.php/itapprovals/admin)
 *
 * (core apps/dispatcher.php uses relative requires that do not resolve
 *  from its sub-directory, so both UIs are served through ajax.php)
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.app.php';
require_once INCLUDE_DIR . 'class.dispatcher.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class.schema.php';
require_once __DIR__ . '/class.models.php';
require_once __DIR__ . '/class.graph.php';
require_once __DIR__ . '/class.engine.php';
require_once __DIR__ . '/class.portal.php';
require_once __DIR__ . '/class.admin.php';
require_once __DIR__ . '/class.formpack.php';
require_once __DIR__ . '/class.reports.php';

class ITApprovalsPlugin extends Plugin {

    var $config_class = 'ITApprovalsConfig';

    // Plugin instances are bootstrapped individually; signals must only be
    // connected once per request.
    private static $booted = false;
    private static $engine;

    function isMultiInstance() {
        return false;
    }

    function enable() {
        ITApprovalsSchema::ensure();
        return parent::enable();
    }

    static function getEngine() {
        return self::$engine;
    }

    function bootstrap() {
        if (self::$booted)
            return;
        self::$booted = true;

        $config = $this->getConfig();
        if (!ITApprovalsSchema::ensure())
            return;

        $engine = self::$engine = new ITApprovalsEngine($config);

        Signal::connect('ticket.create.validated',
            function($obj, &$vars) use ($engine) {
                $engine->onTicketValidated($obj, $vars);
            });
        Signal::connect('ticket.created', array($engine, 'onTicketCreated'));
        Signal::connect('threadentry.created', array($engine, 'onThreadEntry'));
        Signal::connect('cron', function() use ($engine) {
            $engine->onCron();
        });

        $portal = new ITApprovalsPortal($engine);
        Signal::connect('ajax.client', array($portal, 'registerUrls'));
        $portal->enableNavLink();

        $admin = new ITApprovalsAdmin($engine);
        Signal::connect('ajax.scp', array($admin, 'registerUrls'));
        // registerAdminApp() is not declared static in core
        (new Application())->registerAdminApp('IT Approvals',
            ITApprovalsAdmin::getUrl(), array('iconclass' => 'lists'));
    }
}
