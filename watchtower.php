<?php

class watchtower extends rcube_plugin
{
    public $task = 'settings';

    /**
     * @var rcmail
     */
    protected $rc;

    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Load plugin config (watchtower_session_backend, redis_dsn, etc.)
        $this->load_config();

        // Load localization texts
        $this->add_texts('localization/', true);

        // Add entry into Settings list
        $this->add_hook('settings_actions', array($this, 'settings_actions'));

        // Load skin-specific CSS on ALL settings pages
        $skin = $this->rc->config->get('skin', 'elastic');
        $this->include_stylesheet('skins/' . $skin . '/watchtower.css');

        // Register action handler for ?_task=settings&_action=plugin.watchtower
        $this->register_action('plugin.watchtower', array($this, 'settings_init'));
    }

    /**
     * Action handler for plugin.watchtower
     */
    public function settings_init()
    {
        $this->rc = rcmail::get_instance();

        $allow_own = (bool) $this->rc->config->get('watchtower_allow_user_view_own', false);
        $is_admin  = $this->is_admin_user($this->rc->user);

        // If user is not allowed to see this page at all, bail out
        if (!$is_admin && !$allow_own) {
            return;
        }

        // Log entry so we know debug is working
        $this->debug_log('watchtower settings_init entered', array(
            'task'   => $this->rc->task,
            'action' => $this->rc->action,
        ), true);

        // Our content goes into plugin.body rendered by the plugin template
        $this->register_handler('plugin.body', array($this, 'settings_content'));

        $this->rc->output->set_pagetitle($this->gettext('settings_title'));

        // Use the generic plugin template, which includes plugin.body
        $this->rc->output->send('plugin');
    }

    /**
     * Register Watchtower in Settings actions.
     * - Admins: always see it.
     * - Non-admins: only if watchtower_allow_user_view_own = true.
     */
    public function settings_actions($args)
    {
        $allow_own = (bool) $this->rc->config->get('watchtower_allow_user_view_own', false);
        $user      = $this->rc->user;
        $is_admin  = $this->is_admin_user($user);

        if ($is_admin || $allow_own) {
            $args['actions'][] = array(
                'action' => 'plugin.watchtower',
                'class'  => 'watchtower',
                'label'  => 'watchtower.settings_title',
                'title'  => 'watchtower.settings_description',
            );
        }

        return $args;
    }

    /**
     * Main content for the settings page.
     */
    public function settings_content()
    {
        $intro = rcube::Q($this->gettext('settings_intro'));

        $sessions = $this->load_sessions();
        $logins   = $this->load_login_log();

        $allow_own = (bool) $this->rc->config->get('watchtower_allow_user_view_own', false);
        $is_admin  = $this->is_admin_user($this->rc->user);

        // Non-admins are only allowed to see their own data
        if ($allow_own && !$is_admin) {
            $sessions = $this->filter_sessions_for_current_user($sessions);
            $logins   = $this->filter_logins_for_current_user($logins);
        }

        $html  = '<div id="watchtower-settings">';
        $html .= '<h1>' . rcube::Q($this->gettext('settings_title')) . '</h1>';
        $html .= '<p class="watchtower-intro">' . $intro . '</p>';

        // Sessions section
        $html .= '<div class="section watchtower-section-sessions">';
        $html .= '<h2 class="section-title">' . rcube::Q($this->gettext('sessions_title')) . '</h2>';
        $html .= '<div class="watchtower-sessions-wrap">';
        $html .= $this->render_sessions_table($sessions);
        $html .= '</div>';
        $html .= '</div>';

        // Login events section
        $html .= '<div class="section watchtower-section-logins">';
        $html .= '<h2 class="section-title">' . rcube::Q($this->gettext('logins_title')) . '</h2>';
        $html .= '<div class="watchtower-logins-wrap">';
        $html .= $this->render_logins_table($logins);
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>'; // #watchtower-settings

        return $html;
    }

    /**
     * Decide which backend to use and load recent sessions.
     * Backends: db, redis, apcu, auto (default).
     */
    protected function load_sessions()
    {
        $backend = $this->get_session_backend();
        $this->debug_log('using session backend', array('backend' => $backend), true);

        switch ($backend) {
            case 'redis':
                return $this->load_sessions_from_redis();

            case 'apcu':
                return $this->load_sessions_from_apcu();

            case 'db':
            default:
                return $this->load_sessions_from_db();
        }
    }

    /**
     * Determine effective session backend.
     */
    protected function get_session_backend()
    {
        $backend = strtolower((string) $this->rc->config->get('watchtower_session_backend', 'auto'));

        if ($backend === 'auto') {
            $handler = strtolower((string) ini_get('session.save_handler'));
            if ($handler === 'redis') {
                return 'redis';
            }
            if ($handler === 'apcu' || $handler === 'apc') {
                return 'apcu';
            }
            return 'db';
        }

        if (in_array($backend, array('db', 'redis', 'apcu'), true)) {
            return $backend;
        }

        return 'db';
    }

    /**
     * Load recent sessions from Roundcube's DB session table.
     */
    protected function load_sessions_from_db()
    {
        $rows = array();

        $db = $this->rc->get_dbh();

        $sess_table  = $this->rc->config->get('db_table_session', 'session');
        $users_table = $this->rc->config->get('db_table_users', 'users');

        $sql = "SELECT sess_id, changed, ip, vars FROM $sess_table ORDER BY changed DESC LIMIT 200";
        $res = $db->query($sql);

        if (!$res) {
            $this->debug_log('failed to query session table');
            return $rows;
        }

        $sample_logged = false;

        while ($row = $db->fetch_assoc($res)) {
            $was_decoded = false;
            $vars        = $this->decode_session_vars($row['vars'], $was_decoded);

            if (!$was_decoded && !$sample_logged) {
                $this->debug_log(
                    'session vars decode failed (db)',
                    array(
                        'sess_id'  => $row['sess_id'],
                        'changed'  => $row['changed'],
                        'vars_raw' => substr($row['vars'], 0, 200),
                    ),
                    true
                );
                $sample_logged = true;
            } elseif ($was_decoded && !$sample_logged) {
                $this->debug_log(
                    'session vars decoded sample (db)',
                    array(
                        'sess_id' => $row['sess_id'],
                        'keys'    => array_keys($vars),
                    ),
                    true
                );
                $sample_logged = true;
            }

            $rows[] = $this->build_session_row(
                $row['sess_id'],
                $row['changed'],
                isset($row['ip']) ? $row['ip'] : '',
                $vars,
                $users_table
            );
        }

        return $rows;
    }

    /**
     * Load recent sessions from Redis.
     * Supports both URL-style DSN and colon-style "host:port:db:password".
     * Handles Roundcube-style wrapper: ['changed','ip','vars' => <encoded>].
     */
    protected function load_sessions_from_redis()
    {
        $rows = array();

        if (!class_exists('Redis')) {
            $this->debug_log('Redis extension not available', array(), true);
            return $rows;
        }

        // Prefer explicit watchtower DSN, then Roundcube's redis_hosts, then session.save_path
        $dsn = $this->rc->config->get('watchtower_redis_dsn', null);
        if (!$dsn) {
            $hosts = (array) $this->rc->config->get('redis_hosts', array());
            if (!empty($hosts)) {
                $dsn = reset($hosts);
            }
        }
        if (!$dsn) {
            $dsn = ini_get('session.save_path');
        }
        if (!$dsn) {
            $this->debug_log('no redis DSN available', array(), true);
            return $rows;
        }

        $host    = '127.0.0.1';
        $port    = 6379;
        $pass    = null;
        $dbindex = null;

        // Optional prefix hint; if empty we'll scan '*' and auto-detect
        $prefix = (string) $this->rc->config->get('watchtower_redis_prefix', '');

        // 1) URL-style DSN, e.g. tcp://127.0.0.1:6379?database=2&prefix=rcsess_
        if (strpos($dsn, '://') !== false) {
            $url = @parse_url($dsn);
            if ($url === false || empty($url['host'])) {
                $this->debug_log('failed to parse redis DSN (url style)', array('dsn' => $dsn), true);
                return $rows;
            }

            $host = $url['host'];
            $port = isset($url['port']) ? (int) $url['port'] : 6379;
            $pass = isset($url['pass']) ? $url['pass'] : null;

            $params = array();
            if (!empty($url['query'])) {
                parse_str($url['query'], $params);
            }

            if (isset($params['database']) && $params['database'] !== '') {
                $dbindex = (int) $params['database'];
            }

            if ($prefix === '' && !empty($params['prefix'])) {
                $prefix = (string) $params['prefix'];
            }
        }
        // 2) Colon-style DSN, e.g. localhost:6379:1:password
        else {
            $parts = explode(':', $dsn);

            if (!empty($parts[0])) {
                $host = $parts[0];
            }

            if (isset($parts[1]) && $parts[1] !== '' && is_numeric($parts[1])) {
                $port = (int) $parts[1];
            }

            if (isset($parts[2]) && $parts[2] !== '') {
                // third piece is DB index
                $dbindex = is_numeric($parts[2]) ? (int) $parts[2] : null;
            }

            if (isset($parts[3]) && $parts[3] !== '') {
                $pass = $parts[3];
            }
        }

        // If prefix is still empty, we will scan all keys ("*") and auto-detect session-like ones
        $pattern = ($prefix !== '' ? $prefix . '*' : '*');

        $this->debug_log('redis connection parameters', array(
            'dsn'     => $dsn,
            'host'    => $host,
            'port'    => $port,
            'db'      => $dbindex,
            'prefix'  => $prefix !== '' ? $prefix : '(none)',
            'pattern' => $pattern,
        ), true);

        $redis = new Redis();
        try {
            $redis->connect($host, $port, 1.5);
        } catch (\Exception $e) {
            $this->debug_log('redis connect failed', array('error' => $e->getMessage()), true);
            return $rows;
        }

        if ($pass !== null && $pass !== '') {
            try {
                $redis->auth($pass);
            } catch (\Exception $e) {
                $this->debug_log('redis auth failed', array('error' => $e->getMessage()), true);
                return $rows;
            }
        }

        if ($dbindex !== null) {
            try {
                $redis->select($dbindex);
            } catch (\Exception $e) {
                $this->debug_log('redis select(db) failed', array('error' => $e->getMessage()), true);
            }
        }

        $users_table   = $this->rc->config->get('db_table_users', 'users');
        $it            = null;
        $sample_logged = false;

        while (true) {
            $keys = $redis->scan($it, $pattern, 50);
            if ($keys === false || $keys === null) {
                break;
            }

            foreach ($keys as $key) {
                if (count($rows) >= 200) {
                    break 2;
                }

                // Skip obvious non-session keys (IMAP/cache/etc.)
                if (strpos($key, 'IMAP:') !== false || strpos($key, 'IMAPEXP:') !== false || strpos($key, 'cache:') !== false) {
                    continue;
                }

                // Only care about string-valued keys
                $type = $redis->type($key);
                if ($type !== Redis::REDIS_STRING) {
                    continue;
                }

                $ttl = $redis->ttl($key);
                $val = $redis->get($key);
                if (!is_string($val) || $val === '') {
                    continue;
                }

                // First level decode
                $was_decoded = false;
                $outer       = $this->decode_session_vars($val, $was_decoded);

                $changed = null;
                $ip      = '';
                $vars    = array();

                if ($was_decoded && is_array($outer) && $outer) {
                    // Roundcube-style Redis session wrapper: ['changed','ip','vars' => <encoded>]
                    if (array_key_exists('vars', $outer)) {
                        $changed = isset($outer['changed']) ? $outer['changed'] : null;
                        $ip      = isset($outer['ip']) ? (string) $outer['ip'] : '';

                        $inner_raw       = $outer['vars'];
                        $inner_decoded   = false;
                        $inner_session   = $this->decode_session_vars($inner_raw, $inner_decoded);

                        if ($inner_decoded && is_array($inner_session) && $inner_session) {
                            $vars = $inner_session;

                            if (!$sample_logged) {
                                $this->debug_log(
                                    'redis inner session decoded sample',
                                    array('key' => $key, 'keys' => array_keys($vars)),
                                    true
                                );
                                $sample_logged = true;
                            }
                        } else {
                            // Fallback: treat outer as vars if inner fails
                            $vars = $outer;
                        }
                    } else {
                        // No wrapper, just plain session vars
                        $vars = $outer;

                        if (!$sample_logged) {
                            $this->debug_log(
                                'redis session decoded sample',
                                array('key' => $key, 'keys' => array_keys($vars)),
                                true
                            );
                            $sample_logged = true;
                        }
                    }
                } else {
                    // Not decodable, but still treat as an opaque session
                    if (!$sample_logged) {
                        $this->debug_log(
                            'redis value treated as session (no decode)',
                            array(
                                'key' => $key,
                                'ttl' => $ttl,
                                'len' => strlen($val),
                            ),
                            true
                        );
                        $sample_logged = true;
                    }
                }

                $rows[] = $this->build_session_row(
                    $key,
                    $changed,
                    $ip,
                    $vars,
                    $users_table
                );
            }

            if ($it === 0 || $it === null) {
                break;
            }
        }

        $this->debug_log(
            'redis scan finished',
            array(
                'session_rows' => count($rows),
                'db'           => $dbindex,
            ),
            true
        );

        return $rows;
    }

    /**
     * Load recent sessions from APCu (Roundcube APCu backend).
     */
    protected function load_sessions_from_apcu()
    {
        $rows = array();

        if (!function_exists('apcu_cache_info') || !function_exists('apcu_fetch')) {
            $this->debug_log('APCu functions not available', array(), true);
            return $rows;
        }

        $info = @apcu_cache_info();
        if (!is_array($info) || empty($info['cache_list'])) {
            return $rows;
        }

        $prefix        = (string) $this->rc->config->get('watchtower_apcu_prefix', 'rcsess_');
        $users_table   = $this->rc->config->get('db_table_users', 'users');
        $sample_logged = false;

        foreach ($info['cache_list'] as $entry) {
            if (count($rows) >= 200) {
                break;
            }

            if (empty($entry['info'])) {
                continue;
            }

            $key = $entry['info'];
            if ($prefix !== '' && strpos($key, $prefix) !== 0) {
                continue;
            }

            $ok_fetch = false;
            $val      = apcu_fetch($key, $ok_fetch);
            if (!$ok_fetch || !is_string($val) || $val === '') {
                continue;
            }

            $was_decoded = false;
            $vars        = $this->decode_session_vars($val, $was_decoded);

            if (!$was_decoded || !is_array($vars) || !$vars) {
                if (!$sample_logged) {
                    $this->debug_log(
                        'apcu session decode failed',
                        array('key' => $key),
                        true
                    );
                    $sample_logged = true;
                }
                continue;
            }

            if (!$sample_logged) {
                $this->debug_log(
                    'apcu session decoded sample',
                    array('key' => $key, 'keys' => array_keys($vars)),
                    true
                );
                $sample_logged = true;
            }

            $rows[] = $this->build_session_row(
                $key,
                null,
                '',
                $vars,
                $users_table
            );
        }

        return $rows;
    }

    /**
     * Decode session vars that might be stored as:
     *  - base64(serialize(array))
     *  - serialize(array)
     *  - PHP session string "name|serializedvalue..."
     */
    protected function decode_session_vars($vars_raw, &$was_decoded = null)
    {
        $was_decoded = false;
        $vars        = null;

        // Attempt 1: base64 + unserialize
        $decoded = @base64_decode($vars_raw, true);
        if ($decoded !== false && $decoded !== '') {
            $vars = @unserialize($decoded);
        }

        // Attempt 2: plain unserialize if first failed
        if (!is_array($vars)) {
            $vars = @unserialize($vars_raw);
        }

        // Attempt 3: PHP session string "key|serializedvalue..."
        if (!is_array($vars)) {
            $vars = $this->decode_php_session_string($vars_raw);
        }

        if (!is_array($vars) || !$vars) {
            return array();
        }

        $was_decoded = true;
        return $vars;
    }

    /**
     * Decode a PHP session-encoded string without polluting the real $_SESSION.
     */
    protected function decode_php_session_string($data)
    {
        if (!is_string($data) || $data === '') {
            return array();
        }

        if (strpos($data, '|') === false) {
            return array();
        }

        // Backup current session superglobal
        $backup = isset($_SESSION) ? $_SESSION : array();

        // Use a temp array and session_decode on it
        $_SESSION = array();
        $ok       = @session_decode($data);
        $result   = ($ok && is_array($_SESSION)) ? $_SESSION : array();

        // Restore original
        $_SESSION = $backup;

        return $result;
    }

    /**
     * Build a normalized row from decoded session vars and optional DB info.
     */
    protected function build_session_row($sess_id, $changed, $ip, array $vars, $users_table = null)
    {
        $db = $this->rc->get_dbh();

        // Numeric user_id if present
        $user_id = null;
        if (isset($vars['user_id']) && is_numeric($vars['user_id'])) {
            $user_id = (int) $vars['user_id'];
        }

        // Username / login from vars
        $username = '';
        if (!empty($vars['username'])) {
            $username = (string) $vars['username'];
        } elseif (!empty($vars['user'])) {
            $username = (string) $vars['user'];
        }

        // If we have an id but no username, try resolving from users table
        if ($user_id && $username === '' && $users_table) {
            $u_res = $db->query("SELECT username FROM $users_table WHERE user_id = ?", $user_id);
            if ($u_row = $db->fetch_assoc($u_res)) {
                $username = (string) $u_row['username'];
            }
        }

        // IMAP host / storage host
        $imap_host = '';
        if (!empty($vars['imap_host'])) {
            $imap_host = (string) $vars['imap_host'];
        } elseif (!empty($vars['storage_host'])) {
            $imap_host = (string) $vars['storage_host'];
        }

        // IP: prefer DB column, but fall back to vars if DB ip is empty
        $ip_out = $ip;
        if (($ip_out === '' || $ip_out === null) && !empty($vars['ip'])) {
            $ip_out = (string) $vars['ip'];
        }

        // Last activity / timestamp
        $changed_out = $this->format_timestamp($changed);

        if (($changed === null || $changed === '' || !is_numeric($changed)) && !empty($vars['changed'])) {
            $changed_out = $this->format_timestamp($vars['changed']);
        } elseif (($changed === null || $changed === '') && !empty($vars['timestamp'])) {
            $changed_out = $this->format_timestamp($vars['timestamp']);
        }

        $user_agent = $this->extract_user_agent($vars);

        return array(
            'sess_id'    => $sess_id,
            'changed'    => $changed_out,
            'ip'         => $ip_out,
            'user_id'    => $user_id,
            'user'       => $username,
            'imap_host'  => $imap_host,
            'user_agent' => $user_agent,
        );
    }

    /**
     * Load login events from log file, supporting JSONL and Roundcube-style lines.
     */
    protected function load_login_log()
    {
        $config  = $this->rc->config;
        $logfile = $config->get('watchtower_logins_file', 'logs/userlogins.log');

        // Resolve relative to Roundcube root
        if ($logfile && $logfile[0] !== '/' && defined('RCUBE_INSTALL_PATH')) {
            $logfile = RCUBE_INSTALL_PATH . $logfile;
        }

        if (!is_readable($logfile)) {
            $this->debug_log('login log file not readable', array('file' => $logfile));
            return array();
        }

        $lines = @file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $this->debug_log('failed to read login log file', array('file' => $logfile));
            return array();
        }

        if (count($lines) > 200) {
            $lines = array_slice($lines, -200);
        }

        $events = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // 1) Try JSONL (our structured format)
            $data = json_decode($line, true);
            if (is_array($data)) {
                $events[] = array(
                    'timestamp' => isset($data['timestamp']) ? $data['timestamp'] : '',
                    'user'      => isset($data['user']) ? $data['user'] : '',
                    'ip'        => isset($data['ip']) ? $data['ip'] : '',
                    'device'    => isset($data['device']) ? $data['device'] : '',
                    'success'   => !empty($data['success']),
                );
                continue;
            }

            // 2) Try Roundcube-style login line
            $parsed = $this->parse_roundcube_log_line($line);
            if ($parsed) {
                $events[] = $parsed;
                continue;
            }

            // Unknown format → skip silently
        }

        return $events;
    }

    /**
     * Parse a Roundcube-style login line.
     *
     * Example:
     * [02-Dec-2025 22:14:07 +0000]: <778qsk06> FAILED login for gene@genesworld.net from 40.142.217.207
     */
    protected function parse_roundcube_log_line($line)
    {
        $pattern = '/^\[(?P<ts>[^\]]+)\]:\s+<[^>]+>\s+(?P<result>[A-Z]+)\s+login\s+for\s+(?P<user>\S+)\s+from\s+(?P<ip>\S+)/i';

        if (!preg_match($pattern, $line, $m)) {
            return null;
        }

        $success = (strtoupper($m['result']) !== 'FAILED');

        return array(
            'timestamp' => $m['ts'],
            'user'      => $m['user'],
            'ip'        => $m['ip'],
            'device'    => $this->gettext('roundcube_web'),
            'success'   => $success,
        );
    }

    /**
     * Render sessions table.
     */
    protected function render_sessions_table(array $sessions)
    {
        if (empty($sessions)) {
            return '<div class="watchtower-empty">'
                . rcube::Q($this->gettext('no_sessions_found'))
                . '</div>';
        }

        $h  = '<table class="watchtower-table watchtower-sessions">';
        $h .= '<thead><tr>';
        $h .= '<th>' . rcube::Q($this->gettext('col_last_activity')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_user')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_ip')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_host')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_user_agent')) . '</th>';
        $h .= '</tr></thead><tbody>';

        foreach ($sessions as $row) {
            // Prefer resolved username, fall back to UID label, else blank
            if (!empty($row['user'])) {
                $user_label = $row['user'];
            } elseif (!empty($row['user_id'])) {
                $user_label = 'UID ' . $row['user_id'];
            } else {
                $user_label = '';
            }

            $h .= '<tr>';
            $h .= '<td>' . rcube::Q($row['changed']) . '</td>';
            $h .= '<td>' . rcube::Q($user_label) . '</td>';
            $h .= '<td>' . rcube::Q($row['ip']) . '</td>';
            $h .= '<td>' . rcube::Q($row['imap_host']) . '</td>';
            $h .= '<td class="watchtower-agent">' . rcube::Q($row['user_agent']) . '</td>';
            $h .= '</tr>';
        }

        $h .= '</tbody></table>';

        return $h;
    }

    /**
     * Render login events table.
     */
    protected function render_logins_table(array $events)
    {
        if (empty($events)) {
            return '<div class="watchtower-empty">'
                . rcube::Q($this->gettext('no_logins_found'))
                . '</div>';
        }

        $h  = '<table class="watchtower-table watchtower-logins">';
        $h .= '<thead><tr>';
        $h .= '<th>' . rcube::Q($this->gettext('col_when')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_user')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_ip')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_device')) . '</th>';
        $h .= '<th>' . rcube::Q($this->gettext('col_result')) . '</th>';
        $h .= '</tr></thead><tbody>';

        foreach ($events as $row) {
            $class = $row['success'] ? 'result-ok' : 'result-fail';
            $label = $row['success'] ? $this->gettext('result_ok') : $this->gettext('result_fail');

            $h .= '<tr>';
            $h .= '<td>' . rcube::Q($row['timestamp']) . '</td>';
            $h .= '<td>' . rcube::Q($row['user']) . '</td>';
            $h .= '<td>' . rcube::Q($row['ip']) . '</td>';
            $h .= '<td class="watchtower-device">' . rcube::Q($row['device']) . '</td>';
            $h .= '<td class="watchtower-result ' . $class . '">' . rcube::Q($label) . '</td>';
            $h .= '</tr>';
        }

        $h .= '</tbody></table>';

        return $h;
    }

    /**
     * Timestamp normalization.
     */
    protected function format_timestamp($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            $ts = (int) $value;
        } else {
            $ts = @strtotime($value);
        }

        if (!$ts) {
            return (string) $value;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Extract user agent from session vars.
     */
    protected function extract_user_agent(array $vars)
    {
        if (isset($vars['user_agent']) && $vars['user_agent'] !== '') {
            return (string) $vars['user_agent'];
        }
        if (isset($vars['browser']) && $vars['browser'] !== '') {
            return (string) $vars['browser'];
        }
        if (isset($vars['HTTP_USER_AGENT']) && $vars['HTTP_USER_AGENT'] !== '') {
            return (string) $vars['HTTP_USER_AGENT'];
        }

        return '';
    }

    /**
     * Determine if current user is an admin.
     */
    protected function is_admin_user($user = null)
    {
        if ($user === null) {
            $user = $this->rc->user;
        }
        if (!$user) {
            return false;
        }

        // Local DB admin is usually user_id = 1
        $is_admin = ($user->ID == 1);

        // Allow external ACLs to participate
        if (method_exists($user, 'is_admin')) {
            $is_admin = $is_admin || $user->is_admin();
        }

        return $is_admin;
    }

    /**
     * Get the current user's login/username string.
     */
    protected function get_current_login_name()
    {
        $user = $this->rc->user;
        if (!$user) {
            return null;
        }

        if (method_exists($user, 'get_username')) {
            return (string) $user->get_username();
        }

        if (method_exists($user, 'get_login')) {
            return (string) $user->get_login();
        }

        if (isset($user->data['username'])) {
            return (string) $user->data['username'];
        }

        return null;
    }

    /**
     * Filter session rows down to the current user only.
     */
    protected function filter_sessions_for_current_user(array $sessions)
    {
        $login = $this->get_current_login_name();
        if ($login === null || $login === '') {
            return array();
        }

        $login_lc = strtolower($login);
        $out      = array();

        foreach ($sessions as $row) {
            if (empty($row['user'])) {
                continue;
            }
            if (strtolower((string) $row['user']) === $login_lc) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Filter login events down to the current user only.
     */
    protected function filter_logins_for_current_user(array $events)
    {
        $login = $this->get_current_login_name();
        if ($login === null || $login === '') {
            return array();
        }

        $login_lc = strtolower($login);
        $out      = array();

        foreach ($events as $row) {
            if (empty($row['user'])) {
                continue;
            }
            if (strtolower((string) $row['user']) === $login_lc) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Debug logger.
     * When $force = true, it will log regardless of config flag.
     */
    protected function debug_log($message, array $context = array(), $force = false)
    {
        // Normal gate
        if (!$force && !$this->rc->config->get('watchtower_debug', false)) {
            return;
        }

        $log_dir = $this->rc->config->get('log_dir', 'logs');
        if ($log_dir && $log_dir[0] !== '/' && defined('RCUBE_INSTALL_PATH')) {
            $log_dir = RCUBE_INSTALL_PATH . $log_dir;
        }

        if (!$log_dir) {
            return;
        }

        $file = rtrim($log_dir, '/\\') . '/watchtower-debug.log';

        $line = date('Y-m-d H:i:s') . ' $message';
        $line = date('Y-m-d H:i:s') . ' ' . $message;
        if (!empty($context)) {
            $json = json_encode($context);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        $line .= "\n";

        @file_put_contents($file, $line, FILE_APPEND);
    }
}
