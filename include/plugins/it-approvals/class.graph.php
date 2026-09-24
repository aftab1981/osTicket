<?php
/**
 * Minimal Microsoft Graph client used to resolve a user's line manager.
 *
 * Uses the OAuth2 client-credentials flow against a dedicated Entra app
 * registration (Graph Application permission: User.Read.All). Results are
 * cached in itapp_mgr_cache so Graph is not called on every page view.
 */
class ITApprovalsGraph {

    const TIMEOUT = 5;

    // Global cloud by default. For national clouds define in ost-config.php, e.g.
    // GCC High: ITAPPROVALS_GRAPH_ENDPOINT = https://graph.microsoft.us
    //           ITAPPROVALS_LOGIN_ENDPOINT = https://login.microsoftonline.us
    static function graphBase() {
        return rtrim(defined('ITAPPROVALS_GRAPH_ENDPOINT')
            ? ITAPPROVALS_GRAPH_ENDPOINT : 'https://graph.microsoft.com', '/');
    }

    static function loginBase() {
        return rtrim(defined('ITAPPROVALS_LOGIN_ENDPOINT')
            ? ITAPPROVALS_LOGIN_ENDPOINT : 'https://login.microsoftonline.com', '/');
    }

    private $config;
    private $token;
    private $tokenExpires = 0;
    private $lastError = '';

    function __construct(ITApprovalsConfig $config) {
        $this->config = $config;
    }

    function isConfigured() {
        return $this->config->get('graph_tenant')
            && $this->config->get('graph_client_id')
            && $this->config->getGraphSecret();
    }

    function getLastError() {
        return $this->lastError;
    }

    /**
     * Returns array('email' => ..., 'name' => ...) for the manager of the
     * given user email, or null when the user has no manager (or lookup
     * failed — see getLastError()).
     *
     * $useCache=false forces a live lookup (used by the simulator).
     */
    function getManager($email, $useCache=true) {
        $this->lastError = '';
        $email = strtolower(trim($email));
        if (!$email)
            return null;

        $ttl = max(0, (int) $this->config->get('mgr_cache_hours', 24)) * 3600;
        if ($useCache && $ttl
                && ($C = ITApp_ManagerCache::lookup(array('email' => $email)))
                && (time() - strtotime($C->fetched)) < $ttl) {
            if ($C->manager_email)
                return array('email' => $C->manager_email, 'name' => $C->manager_name);
            $this->lastError = 'No manager set in Entra ID (cached)';
            return null;
        }

        if (!$this->isConfigured()) {
            $this->lastError = 'Microsoft Graph is not configured';
            return null;
        }

        $url = sprintf(self::graphBase() . '/v1.0/users/%s/manager'
            . '?$select=displayName,mail,userPrincipalName',
            rawurlencode($email));
        list($code, $body) = $this->request('GET', $url);

        if ($code == 404) {
            // Either the user has no manager or the user was not found by
            // this identifier. Distinguish so the admin sees the reason.
            $this->lastError = $this->userExists($email)
                ? 'No manager set in Entra ID'
                : 'User not found in Entra ID by this email/UPN';
            $manager = null;
        }
        elseif ($code != 200 || !is_array($body)) {
            $this->lastError = sprintf('Graph error (HTTP %s): %s', $code,
                is_array($body) && isset($body['error']['message'])
                    ? $body['error']['message'] : 'unexpected response');
            $this->logError($this->lastError);
            // Don't cache transient failures
            return null;
        }
        else {
            $mail = ($body['mail'] ?? '') ?: ($body['userPrincipalName'] ?? '');
            $manager = $mail
                ? array('email' => strtolower($mail),
                        'name' => ($body['displayName'] ?? '') ?: $mail)
                : null;
            if (!$manager)
                $this->lastError = 'Manager has no mail/UPN in Entra ID';
        }

        $this->cache($email, $manager);
        return $manager;
    }

    private function userExists($email) {
        $url = sprintf(self::graphBase() . '/v1.0/users/%s?$select=id',
            rawurlencode($email));
        list($code) = $this->request('GET', $url);
        return $code == 200;
    }

    private function cache($email, $manager) {
        $C = ITApp_ManagerCache::lookup(array('email' => $email));
        if (!$C)
            $C = new ITApp_ManagerCache(array('email' => $email));
        $C->manager_email = $manager ? $manager['email'] : null;
        $C->manager_name = $manager ? $manager['name'] : null;
        $C->fetched = SqlFunction::NOW();
        $C->save();
    }

    private function getToken() {
        if ($this->token && time() < $this->tokenExpires - 60)
            return $this->token;

        $url = sprintf(self::loginBase() . '/%s/oauth2/v2.0/token',
            rawurlencode($this->config->get('graph_tenant')));
        $form = http_build_query(array(
            'grant_type' => 'client_credentials',
            'client_id' => $this->config->get('graph_client_id'),
            'client_secret' => $this->config->getGraphSecret(),
            'scope' => self::graphBase() . '/.default',
        ));
        list($code, $body) = $this->http('POST', $url, $form,
            array('Content-Type: application/x-www-form-urlencoded'));

        if ($code != 200 || empty($body['access_token'])) {
            $this->lastError = sprintf('Token request failed (HTTP %s): %s',
                $code, $body['error_description'] ?? 'no response');
            $this->logError($this->lastError);
            return null;
        }
        $this->token = $body['access_token'];
        $this->tokenExpires = time() + (int) ($body['expires_in'] ?? 3600);
        return $this->token;
    }

    private function request($method, $url) {
        if (!($token = $this->getToken()))
            return array(0, null);
        return $this->http($method, $url, null, array(
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ));
    }

    private function http($method, $url, $body=null, $headers=array()) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        if ($body !== null)
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false)
            $this->lastError = 'Network error: ' . curl_error($ch);
        curl_close($ch);

        $json = $response ? json_decode($response, true) : null;
        return array($code, $json);
    }

    private function logError($msg) {
        global $ost;
        if ($ost)
            $ost->logWarning('IT Approvals: Microsoft Graph', $msg, false);
    }
}
