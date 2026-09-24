<?php
/**
 * Portal Theme — restyles the osTicket client portal without touching core.
 *
 * Plugins bootstrap before osTicket sets the global $ost, so
 * $ost->addExtraHeader() is not reachable here. Instead a scoped output
 * buffer post-processes client-portal HTML pages only (never the staff
 * panel, API, setup, or non-HTML responses) to:
 *   - add the stylesheet (+ web font) before </head>
 *   - swap the favicon and, when a preset ships one, the logo
 *   - add the optional top strip
 *   - replace the footer with a branded one
 *
 * A preset (presets/<name>.php + presets/<name>/ assets) supplies brand
 * defaults; any value entered on the config page overrides the preset.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class PortalThemeConfig extends PluginConfig {

    const DEFAULT_ACCENT = '#1f5fbf';
    const DEFAULT_INK = '#1d2433';

    private $preset;

    static function getPresets() {
        $presets = array('' => 'None (custom settings only)');
        foreach (glob(__DIR__ . '/presets/*.php') as $file) {
            $info = include $file;
            $presets[basename($file, '.php')] = $info['name'] ?? basename($file, '.php');
        }
        return $presets;
    }

    function getOptions() {
        $fromPreset = '— from preset —';
        return array(
            'preset' => new ChoiceField(array(
                'label' => 'Brand preset',
                'choices' => self::getPresets(),
                'hint' => 'Applies a complete brand (colours, font, logo, favicon, top strip, footer). Fields below override it.',
            )),
            'brand' => new SectionBreakField(array('label' => 'Brand')),
            'accent' => new TextboxField(array(
                'label' => 'Brand colour',
                'configuration' => array('size' => 10, 'length' => 7, 'placeholder' => $fromPreset),
                'hint' => 'Hex colour for navigation, primary buttons and links, e.g. #0006b3.',
            )),
            'ink' => new TextboxField(array(
                'label' => 'Ink colour',
                'configuration' => array('size' => 10, 'length' => 7, 'placeholder' => $fromPreset),
                'hint' => 'Hex colour for headings and dark buttons, e.g. #090c18.',
            )),
            'font' => new ChoiceField(array(
                'label' => 'Font',
                'choices' => array('' => $fromPreset,
                    'system' => 'System (Segoe UI / San Francisco)',
                    'inter' => 'Inter (Google Fonts, falls back to system)'),
            )),
            'buttons' => new ChoiceField(array(
                'label' => 'Button shape',
                'choices' => array('' => $fromPreset, 'rounded' => 'Rounded', 'pill' => 'Pill'),
            )),
            'strip' => new SectionBreakField(array(
                'label' => 'Top strip',
                'hint' => 'Thin bar above the header. Type "-" to hide a preset value.',
            )),
            'topbar_left' => new TextboxField(array(
                'label' => 'Left text',
                'configuration' => array('size' => 60, 'length' => 160, 'placeholder' => $fromPreset),
            )),
            'topbar_right' => new TextboxField(array(
                'label' => 'Right text',
                'configuration' => array('size' => 40, 'length' => 120, 'placeholder' => $fromPreset),
            )),
            'footer' => new SectionBreakField(array(
                'label' => 'Footer',
                'hint' => 'Leave all empty (and no preset) to keep the stock osTicket footer.',
            )),
            'footer_about' => new TextareaField(array(
                'label' => 'About text',
                'configuration' => array('rows' => 2, 'cols' => 60, 'html' => false),
            )),
            'footer_phone' => new TextboxField(array(
                'label' => 'Phone', 'configuration' => array('size' => 20, 'length' => 40),
            )),
            'footer_email' => new TextboxField(array(
                'label' => 'Email', 'configuration' => array('size' => 30, 'length' => 120),
            )),
            'footer_links' => new TextareaField(array(
                'label' => 'Link columns',
                'configuration' => array('rows' => 10, 'cols' => 60, 'html' => false),
                'hint' => 'Blocks separated by a blank line. First line = column title, then "Label | URL" per line. Relative URLs are resolved against the helpdesk.',
            )),
            'footer_offices' => new TextareaField(array(
                'label' => 'Offices',
                'configuration' => array('rows' => 3, 'cols' => 60, 'html' => false),
                'hint' => 'One per line: "City | Address".',
            )),
            'footer_copyright' => new TextboxField(array(
                'label' => 'Copyright line',
                'configuration' => array('size' => 60, 'length' => 200),
                'hint' => '{year} is replaced with the current year.',
            )),
            'footer_acknowledgement' => new TextareaField(array(
                'label' => 'Acknowledgement',
                'configuration' => array('rows' => 2, 'cols' => 60, 'html' => false),
            )),
            'advanced' => new SectionBreakField(array('label' => 'Advanced')),
            'custom_css' => new TextareaField(array(
                'label' => 'Additional CSS',
                'required' => false,
                'configuration' => array('rows' => 6, 'cols' => 60, 'html' => false),
                'hint' => 'Optional overrides, appended after the theme.',
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        foreach (array('accent', 'ink') as $k) {
            if (!empty($config[$k]) && !preg_match('/^#[0-9a-fA-F]{6}$/', $config[$k]))
                $errors[$k] = 'Use a 6-digit hex colour, e.g. #0006b3';
        }
        return !$errors;
    }

    static function choiceKey($v) {
        if (is_array($v)) { reset($v); return key($v); }
        return $v;
    }

    function getPresetName() {
        $name = self::choiceKey($this->get('preset'));
        return ($name && preg_match('/^[a-z0-9_-]+$/i', $name)
            && is_file(__DIR__ . "/presets/$name.php")) ? $name : '';
    }

    function getPreset() {
        if (!isset($this->preset)) {
            $name = $this->getPresetName();
            $this->preset = $name ? (include __DIR__ . "/presets/$name.php") : array();
        }
        return $this->preset;
    }

    /** Configured value, else preset value, else $default. "-" clears. */
    function value($key, $default='') {
        $v = trim((string) self::choiceKey($this->get($key)));
        if ($v === '-')
            return '';
        if ($v !== '')
            return $v;
        $preset = $this->getPreset();
        return isset($preset[$key]) ? $preset[$key] : $default;
    }

    private function colour($key, $default) {
        $c = strtolower($this->value($key));
        return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : $default;
    }

    function getAccent() { return $this->colour('accent', self::DEFAULT_ACCENT); }
    function getInk() { return $this->colour('ink', self::DEFAULT_INK); }

    /** Absolute path of a preset asset (logo / favicon), or null */
    function getAsset($key) {
        $preset = $this->getPreset();
        $name = $this->getPresetName();
        if (!$name || empty($preset[$key]))
            return null;
        $path = __DIR__ . "/presets/$name/" . basename($preset[$key]);
        return is_file($path) ? $path : null;
    }
}

class PortalThemePlugin extends Plugin {

    var $config_class = 'PortalThemeConfig';

    private static $booted = false;

    // Captured at bootstrap: PluginManager clears the side-loaded instance
    // config right after bootstrapping, before the page is rendered
    private $themeConfig;

    function isMultiInstance() {
        return false;
    }

    function bootstrap() {
        if (self::$booted || PHP_SAPI === 'cli')
            return;
        self::$booted = true;

        // Client portal, plus the agent sign-in / password-reset pages
        // (the rest of the staff panel keeps the stock look)
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $staffLogin = preg_match('#/scp/(login|pwreset)\.php$#i', $script);
        if (!$staffLogin && preg_match('#/(scp|api|setup|include)/#i', $script))
            return;

        $this->themeConfig = $this->getConfig();
        $self = $this;
        ob_start(function($html) use ($self, $staffLogin) {
            return $staffLogin ? $self->transformStaffLogin($html) : $self->transform($html);
        });
    }

    /** Agent sign-in pages: brand panel + restyled sign-in card */
    function transformStaffLogin($html) {
        if (stripos($html, '</head>') === false || strpos($html, 'id="loginBox"') === false)
            return $html;
        $config = $this->themeConfig;
        $h = function($s) { return Format::htmlchars((string) $s); };

        $favicon = '';
        if ($path = $config->getAsset('favicon')) {
            $html = preg_replace_callback('#<link rel="icon"[^>]*oscar-favicon[^>]*>\s*#i',
                function() { return ''; }, $html);
            $favicon = sprintf('<link rel="icon" type="%s" href="%s">', self::mime($path), self::dataUri($path));
        }
        if ($path = $config->getAsset('logo')) {
            $uri = self::dataUri($path);
            $html = preg_replace_callback('#(<img[^>]*src=")logo\.php\?login(")#',
                function($m) use ($uri) { return $m[1] . $uri . $m[2]; }, $html, 1);
        }

        $panel = sprintf('<div class="pt-login-brand"><div class="pt-login-brand-inner">'
            . '<p class="pt-eyebrow">%s</p><h1>%s</h1><p>%s</p>'
            . '<a class="pt-login-portal" href="%s">%s &rarr;</a></div></div>',
            $h($config->value('staff_login_eyebrow', 'IT Service Desk')),
            $h($config->value('staff_login_title', 'Agent sign in')),
            $h($config->value('staff_login_text',
                'For service desk agents and administrators. Staff requesting IT help should use the portal.')),
            $h(ROOT_PATH), $h($config->value('staff_login_link', 'Go to the service desk portal')));
        $pos = strpos($html, '<div id="brickwall">');
        if ($pos === false)
            $pos = strpos($html, '<div id="loginBox">');
        $html = substr_replace($html, $panel, $pos, 0);

        $head = $favicon . "\n" . $this->getHead('staff-login.css');
        return substr_replace($html, $head . "\n", stripos($html, '</head>'), 0);
    }

    /** Post-process one client-portal page. Plain string operations only:
     *  preg_replace would treat "\2" in CSS escapes as backreferences. */
    function transform($html) {
        $container = '<div id="container">';
        if (stripos($html, '</head>') === false
                || strpos($html, $container) === false)
            return $html;

        $config = $this->themeConfig;

        // Favicon: drop the stock links, add ours
        $favicon = '';
        if ($path = $config->getAsset('favicon')) {
            $html = preg_replace_callback('#<link rel="icon"[^>]*oscar-favicon[^>]*>\s*#i',
                function() { return ''; }, $html);
            $uri = self::dataUri($path);
            $favicon = sprintf('<link rel="icon" type="%1$s" href="%2$s"><link rel="apple-touch-icon" href="%2$s">',
                self::mime($path), $uri);
        }

        // Logo from the preset (otherwise the osTicket logo setting is used)
        if ($path = $config->getAsset('logo')) {
            $uri = self::dataUri($path);
            $html = preg_replace_callback('#(<a[^>]*id="logo"[^>]*>.*?<img[^>]*src=")[^"]*(")#s',
                function($m) use ($uri) { return $m[1] . $uri . $m[2]; }, $html, 1);
        }

        // Top strip
        if ($strip = $this->getTopStrip()) {
            $c = strpos($html, $container);
            $html = substr_replace($html, $strip, $c + strlen($container), 0);
        }

        // Footer
        if ($footer = $this->getFooter()) {
            $start = strpos($html, '<div id="footer">');
            $end = $start !== false ? strpos($html, '<div id="overlay">', $start) : false;
            if ($start !== false && $end !== false)
                $html = substr_replace($html, $footer . "\n", $start, $end - $start);
        }

        // Help-topic forms are named "HT – <sub-category>" for admins; show
        // portal users a friendlier section heading (also for AJAX-loaded forms)
        if (($b = strripos($html, '</body>')) !== false)
            $html = substr_replace($html, '<script>(function(){function f(){'
                . 'document.querySelectorAll(".form-header h3").forEach(function(h){'
                . 'if(/^HT\s*[–-]\s*/.test(h.textContent))h.textContent="Request details";});}'
                . 'new MutationObserver(f).observe(document.body,{childList:true,subtree:true});f();})();</script>', $b, 0);

        $head = $favicon . "\n" . $this->getHead();
        return substr_replace($html, $head . "\n", stripos($html, '</head>'), 0);
    }

    private function getHead($stylesheet='portal.css') {
        $config = $this->themeConfig;
        $accent = $config->getAccent();
        $ink = $config->getInk();
        $inter = $config->value('font', 'system') == 'inter';
        $pill = $config->value('buttons', 'rounded') == 'pill';

        $vars = sprintf(':root{--pt-accent:%s;--pt-accent-dark:%s;--pt-accent-soft:%s;'
            . '--pt-accent-tint:%s;--pt-ink:%s;--pt-ink-hover:%s;--pt-btn-radius:%s;%s}',
            $accent, self::shade($accent, -0.18), self::mix($accent, '#ffffff', 0.92),
            self::mix($accent, '#ffffff', 0.97), $ink, self::shade($ink, 0.18),
            $pill ? '999px' : '8px',
            $inter ? '--pt-font:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
                . '--pt-heading-weight:800;--pt-heading-tracking:-.025em;' : '');

        $fonts = $inter
            ? '<link rel="preconnect" href="https://fonts.googleapis.com">'
                . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">'
            : '';
        $css = file_get_contents(__DIR__ . '/css/' . basename($stylesheet));
        // Admin-provided overrides: strip anything that could close the tag
        $custom = str_ireplace('</style', '', (string) $config->get('custom_css'));
        return "$fonts\n<style id=\"portal-theme\">\n$vars\n$css\n$custom\n</style>";
    }

    private function getTopStrip() {
        $config = $this->themeConfig;
        $left = $config->value('topbar_left');
        $right = $config->value('topbar_right');
        if (!$left && !$right)
            return '';
        return sprintf('<div class="pt-topbar"><div class="pt-topbar-inner">'
            . '<span>%s</span><span>%s</span></div></div>',
            Format::htmlchars($left), Format::htmlchars($right));
    }

    private function getFooter() {
        $c = $this->themeConfig;
        $about = $c->value('footer_about');
        $links = $c->value('footer_links');
        $offices = $c->value('footer_offices');
        $copyright = $c->value('footer_copyright');
        if (!$about && !$links && !$offices && !$copyright)
            return '';

        $h = function($s) { return Format::htmlchars((string) $s); };
        $url = function($u) {
            $u = trim($u);
            if (preg_match('#^(https?:|mailto:|tel:|/)#i', $u))
                return $u;
            return ROOT_PATH . ltrim($u, '/');
        };

        // Brand column
        $brand = '<div class="pt-footer-brand">';
        if ($logo = $c->getAsset('logo'))
            $brand .= sprintf('<img class="pt-footer-logo" src="%s" alt="">', self::dataUri($logo));
        if ($about)
            $brand .= '<p>' . $h($about) . '</p>';
        if ($phone = $c->value('footer_phone'))
            $brand .= sprintf('<a class="pt-footer-phone" href="tel:%s">%s</a>',
                $h(preg_replace('/[^0-9+]/', '', $phone)), $h($phone));
        if ($email = $c->value('footer_email'))
            $brand .= sprintf('<a class="pt-footer-email" href="mailto:%1$s">%1$s</a>', $h($email));
        $brand .= '</div>';

        // Link columns
        $cols = '';
        foreach (preg_split('/\R\s*\R/', trim($links)) as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block))));
            if (!$lines)
                continue;
            $title = array_shift($lines);
            $items = '';
            foreach ($lines as $line) {
                list($label, $href) = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
                if ($label && $href)
                    $items .= sprintf('<li><a href="%s"%s>%s</a></li>', $h($url($href)),
                        preg_match('#^https?:#i', $href) ? ' target="_blank" rel="noopener"' : '',
                        $h($label));
            }
            $cols .= sprintf('<div class="pt-footer-col"><h4>%s</h4><ul>%s</ul></div>', $h($title), $items);
        }

        // Offices
        $officeHtml = '';
        foreach (array_filter(array_map('trim', preg_split('/\R/', $offices))) as $line) {
            list($city, $address) = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $officeHtml .= sprintf('<div><strong>%s</strong><span>%s</span></div>', $h($city), $h($address));
        }

        $bottom = $copyright ? '<p>' . $h(str_replace('{year}', date('Y'), $copyright)) . '</p>' : '';
        $ack = $c->value('footer_acknowledgement');

        return '<div id="footer" class="pt-footer"><div class="pt-footer-inner">'
            . '<div class="pt-footer-top">' . $brand . $cols . '</div>'
            . ($officeHtml ? '<div class="pt-footer-offices">' . $officeHtml . '</div>' : '')
            . '<div class="pt-footer-bottom">' . $bottom . '</div>'
            . ($ack ? '<p class="pt-footer-ack">' . $h($ack) . '</p>' : '')
            . '</div></div>';
    }

    /* Helpers --------------------------------------------------------- */

    private static function mime($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = array('png' => 'image/png', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif');
        return $map[$ext] ?? 'application/octet-stream';
    }

    private static function dataUri($path) {
        static $cache = array();
        if (!isset($cache[$path]))
            $cache[$path] = 'data:' . self::mime($path) . ';base64,'
                . base64_encode(file_get_contents($path));
        return $cache[$path];
    }

    private static function rgb($hex) {
        $hex = ltrim($hex, '#');
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)));
    }

    private static function hex(array $rgb) {
        return vsprintf('#%02x%02x%02x', array_map(function($v) {
            return max(0, min(255, (int) round($v)));
        }, $rgb));
    }

    // $amount < 0 darkens, > 0 lightens
    static function shade($hex, $amount) {
        return self::mix($hex, $amount < 0 ? '#000000' : '#ffffff', abs($amount));
    }

    static function mix($hex, $with, $weight) {
        $a = self::rgb($hex);
        $b = self::rgb($with);
        $out = array();
        for ($i = 0; $i < 3; $i++)
            $out[] = $a[$i] * (1 - $weight) + $b[$i] * $weight;
        return self::hex($out);
    }
}
