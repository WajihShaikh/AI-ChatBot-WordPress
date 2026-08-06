<?php
/**
 * Plugin Name: AI Chat Support
 * Plugin URI: https://example.com
 * Description: Secure, multi-provider AI chatbot with conversation history, lead capture, exact replies, and external embed support.
 * Version: 2.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ai-chat-support
 * Author: Wajih Shaikh
 * Author URI: https://goaccelovate.com
 * Company: GoAccelovate
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

define('AI_CHAT_VERSION', '2.1.0');
define('AI_CHAT_DB_VERSION', '1.1.4');
define('AI_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AI_CHAT_PLUGIN_DIR . 'includes/interface-ai-chat-provider.php';
require_once AI_CHAT_PLUGIN_DIR . 'includes/class-ai-chat-key-store.php';
require_once AI_CHAT_PLUGIN_DIR . 'includes/class-ai-chat-provider-base.php';
require_once AI_CHAT_PLUGIN_DIR . 'includes/class-ai-chat-provider-openai.php';
require_once AI_CHAT_PLUGIN_DIR . 'includes/class-ai-chat-provider-gemini.php';
require_once AI_CHAT_PLUGIN_DIR . 'includes/class-ai-chat-provider-manager.php';

class AI_Chat_Plugin {
    private $providers;
    private $key_store;

    public function __construct() {
        $this->providers = new AI_Chat_Provider_Manager();
        $this->key_store = new AI_Chat_Key_Store();
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('init', array($this, 'maybe_create_tables'));
        add_action('init', array($this, 'register_widget_route'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('template_redirect', array($this, 'serve_widget_js'));
        add_filter('query_vars', array($this, 'register_widget_query_var'));
        add_filter('rest_pre_serve_request', array($this, 'add_cors_headers'), 10, 4);
        
        add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
        add_action('wp_footer', array($this, 'chat_widget'));
        
        // AJAX Endpoints
        add_action('wp_ajax_ai_chat_send_message', array($this, 'send_message'));
        add_action('wp_ajax_nopriv_ai_chat_send_message', array($this, 'send_message'));
        
        add_action('wp_ajax_ai_chat_save_user', array($this, 'save_user_info'));
        add_action('wp_ajax_nopriv_ai_chat_save_user', array($this, 'save_user_info'));
        
        // Fix for Refresh/History Loading
        add_action('wp_ajax_ai_chat_load_history', array($this, 'load_chat_history'));
        add_action('wp_ajax_nopriv_ai_chat_load_history', array($this, 'load_chat_history'));
        
        add_action('wp_ajax_ai_chat_get_messages', array($this, 'get_messages')); // Admin View
        add_action('wp_ajax_ai_chat_delete_chat', array($this, 'delete_chat')); // Admin Delete
        add_action('wp_ajax_ai_chat_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_ai_chat_refresh_models', array($this, 'ajax_refresh_models'));
    }
    
    public function activate() {
        $this->create_tables();
        update_option('ai_chat_db_version', AI_CHAT_DB_VERSION);
        if (!get_option('ai_chat_widget_key')) {
            add_option('ai_chat_widget_key', wp_generate_password(32, false, false));
        }
        flush_rewrite_rules();
        
        // Defaults
        add_option('ai_chat_api_provider', 'openai');
        add_option('ai_chat_model_openai', 'gpt-5.6-luna', '', false);
        add_option('ai_chat_model_gemini', 'gemini-3.6-flash', '', false);
        add_option('ai_chat_welcome_message', 'Hello! How can I help you today?');
        add_option('ai_chat_rate_limit', 20, '', false);
        add_option('ai_chat_auto_language', 1, '', false);
        $this->key_store->get('openai');
        $this->key_store->get('gemini');
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }

    public function maybe_create_tables() {
        $installed_version = get_option('ai_chat_db_version');
        if ($installed_version !== AI_CHAT_DB_VERSION) {
            $this->create_tables();
            update_option('ai_chat_db_version', AI_CHAT_DB_VERSION);
        }
        if (!get_option('ai_chat_widget_key')) {
            add_option('ai_chat_widget_key', wp_generate_password(32, false, false));
        }
        if (get_option('ai_chat_auto_language', null) === null) {
            add_option('ai_chat_auto_language', 1, '', false);
        }
        // Migrate version 1.x API keys into protected, non-autoloaded options.
        $this->key_store->get('openai');
        $this->key_store->get('gemini');
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$wpdb->prefix}ai_chats (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            user_name varchar(255) NOT NULL,
            user_email varchar(255) NOT NULL,
            purpose varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE {$wpdb->prefix}ai_chat_messages (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            role varchar(50) NOT NULL,
            message text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE {$wpdb->prefix}ai_chat_exact_replies (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            question text NOT NULL,
            answer text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY question (question(191))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    public function register_widget_route() {
        add_rewrite_rule('^chatbot-widget\\.js$', 'index.php?ai_chat_widget=1', 'top');
    }

    public function register_widget_query_var($vars) {
        $vars[] = 'ai_chat_widget';
        return $vars;
    }

    public function serve_widget_js() {
        $requested = (bool) get_query_var('ai_chat_widget');
        if (!$requested) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path = parse_url($request_uri, PHP_URL_PATH);
            if ($path && preg_match('~/chatbot-widget\\.js$~', $path)) {
                $requested = true;
            }
        }
        if (!$requested) return;

        $config = array(
            'restBase' => rest_url('ai-chat/v1'),
            'siteUrl' => home_url(),
            'cssUrl' => AI_CHAT_PLUGIN_URL . 'style.css',
            'badgeTitle' => get_option('ai_chat_badge_title', 'Welcome to AI Assistant'),
            'badgeSubtitle' => get_option('ai_chat_badge_subtitle', 'How can we help you?'),
            'badgeIcon' => get_option('ai_chat_badge_icon', '🤖'),
            'welcomeMessage' => get_option('ai_chat_welcome_message', 'Hello! How can I help you today?'),
            'siteLanguage' => str_replace('_', '-', get_locale()),
            'widgetTitle' => 'AI Chat Support'
        );

        header('Content-Type: application/javascript; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo 'window.AIChatWidgetConfig = ' . wp_json_encode($config) . ';' . "\n";
        $widget_path = AI_CHAT_PLUGIN_DIR . 'chatbot-widget.js';
        if (file_exists($widget_path)) {
            readfile($widget_path);
        } else {
            echo "console.error('AI Chat widget file missing.');";
        }
        exit;
    }

    public function register_rest_routes() {
        register_rest_route('ai-chat/v1', '/session', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_create_session'),
            'permission_callback' => array($this, 'rest_permission'),
        ));

        register_rest_route('ai-chat/v1', '/message', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_send_message'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
    }

    public function rest_permission($request) {
        if ($request->get_method() === 'OPTIONS') {
            return true;
        }
        return $this->validate_widget_key($request);
    }

    public function add_cors_headers($served, $result, $request, $server) {
        $route = $request->get_route();
        if (strpos($route, '/ai-chat/v1/') === 0) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-AI-CHAT-KEY');
        }
        return $served;
    }

    private function validate_widget_key($request) {
        $stored_key = get_option('ai_chat_widget_key', '');
        if ($stored_key === '') return true;
        $key = $request->get_param('key');
        if (!$key) {
            $key = $request->get_header('x-ai-chat-key');
        }
        if (!$key || !hash_equals($stored_key, $key)) {
            return new WP_Error('ai_chat_invalid_key', 'Invalid widget key.', array('status' => 403));
        }
        return true;
    }

    public function rest_create_session($request) {
        $key_check = $this->validate_widget_key($request);
        if (is_wp_error($key_check)) return $key_check;

        global $wpdb;
        $name = sanitize_text_field($request->get_param('name'));
        $email = sanitize_email($request->get_param('email'));
        $phone = sanitize_text_field($request->get_param('phone'));
        if ($phone === '') {
            $phone = sanitize_text_field($request->get_param('purpose'));
        }

        if ($name === '' || !is_email($email)) {
            return new WP_Error('ai_chat_missing_fields', 'A valid name and email address are required.', array('status' => 400));
        }

        $session_rate = $this->check_session_creation_rate_limit();
        if (is_wp_error($session_rate)) {
            return $session_rate;
        }

        $session_id = 'chat_' . wp_generate_uuid4();
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ai_chats',
            array(
                'session_id' => $session_id,
                'user_name' => $name,
                'user_email' => $email,
                'purpose' => $phone,
            ),
            array('%s', '%s', '%s', '%s')
        );
        if ($inserted === false) {
            return new WP_Error('ai_chat_storage_error', 'The chat could not be started. Please try again.', array('status' => 500));
        }

        return rest_ensure_response(array('session_id' => $session_id));
    }

    public function rest_send_message($request) {
        $key_check = $this->validate_widget_key($request);
        if (is_wp_error($key_check)) return $key_check;

        $session_id = sanitize_text_field($request->get_param('session_id'));
        $message = sanitize_textarea_field($request->get_param('message'));
        $visitor_language = $this->sanitize_visitor_language($request->get_param('visitor_language'));

        if (empty($session_id) || $message === '') {
            return new WP_Error('ai_chat_missing_fields', 'Session ID and message are required.', array('status' => 400));
        }

        $response = $this->process_message($session_id, $message, $visitor_language);
        if (is_wp_error($response)) return $response;
        return rest_ensure_response(array('response' => $response));
    }

    private function process_message($session_id, $message, $visitor_language = '') {
        global $wpdb;

        $session_id = sanitize_text_field($session_id);
        $message = trim(sanitize_textarea_field($message));
        if ($session_id === '' || $message === '') {
            return new WP_Error('ai_chat_missing_fields', 'Please enter a message and try again.');
        }
        if (strlen($message) > 4000) {
            return new WP_Error('ai_chat_message_too_long', 'Your message is too long. Please keep it under 4,000 characters.');
        }

        $session_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ai_chats WHERE session_id = %s LIMIT 1",
            $session_id
        ));
        if (!$session_exists) {
            return new WP_Error('ai_chat_invalid_session', 'This chat session is no longer valid. Please start a new conversation.');
        }

        $rate_check = $this->check_rate_limit($session_id);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ai_chat_messages',
            array(
                'session_id' => $session_id,
                'role' => 'user',
                'message' => $message,
            ),
            array('%s', '%s', '%s')
        );
        if ($inserted === false) {
            return new WP_Error('ai_chat_storage_error', 'The message could not be saved. Please try again.');
        }

        $exact_reply = $this->get_exact_reply($message);
        $auto_language = (bool) get_option('ai_chat_auto_language', 1);

        if ($exact_reply !== null && !$auto_language) {
            $ai_response = $exact_reply;
        } else {
            $provider_id = sanitize_key(get_option('ai_chat_api_provider', 'openai'));
            $provider = $this->providers->get($provider_id);
            if (!$provider) {
                if ($exact_reply !== null) {
                    $ai_response = $exact_reply;
                } else {
                    return new WP_Error('ai_chat_provider_missing', 'The assistant is not configured correctly.');
                }
            } else {
                $api_key = $this->key_store->get($provider_id);
                if ($api_key === '') {
                    if ($exact_reply !== null) {
                        $ai_response = $exact_reply;
                    } else {
                        return new WP_Error('ai_chat_provider_key_missing', 'The assistant is temporarily unavailable. Please contact the site administrator.');
                    }
                } else {
                    $history_rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT role, message FROM {$wpdb->prefix}ai_chat_messages WHERE session_id = %s ORDER BY id DESC LIMIT 12",
                        $session_id
                    ));
                    $history_rows = array_reverse($history_rows);
                    $history = array();
                    foreach ($history_rows as $row) {
                        $history[] = array(
                            'role' => $row->role === 'assistant' ? 'assistant' : 'user',
                            'message' => $row->message,
                        );
                    }

                    $instructions = trim((string) get_option('ai_chat_instruction', ''));
                    $system_instruction = 'You are a helpful website support assistant. Be accurate, concise, polite, and transparent when you do not know something.';
                    if ($instructions !== '') {
                        $system_instruction .= "\n\nBusiness context and instructions:\n" . $instructions;
                    }

                    if ($auto_language) {
                        $visitor_language = $this->sanitize_visitor_language($visitor_language);
                        $language_hint = $visitor_language !== '' ? $visitor_language : str_replace('_', '-', get_locale());
                        $system_instruction .= "\n\nAutomatic language behavior:\n"
                            . "- Detect the language used in the visitor's latest message and reply in that same language and writing system.\n"
                            . "- If the latest message is too short, contains only a name, number, emoji, URL, or is otherwise ambiguous, use the visitor locale hint: " . $language_hint . ".\n"
                            . "- If the visitor changes language during the conversation, switch to the new language immediately.\n"
                            . "- Keep brand names, product names, URLs, email addresses, code, and technical identifiers unchanged unless a standard localized form exists.\n"
                            . "- Do not announce, explain, or mention language detection unless the visitor asks about it.";
                    }

                    if ($exact_reply !== null) {
                        $system_instruction .= "\n\nAuthoritative answer for the visitor's latest question:\n" . $exact_reply
                            . "\nUse this answer as the source of truth. Return the same meaning and facts, translated into the visitor's language only when needed. Do not add unsupported information.";
                    }

                    $model = $this->providers->get_selected_model($provider_id);
                    $generated = $provider->generate($api_key, $model, $history, $system_instruction, array('max_output_tokens' => 500));
                    if (is_wp_error($generated)) {
                        // Preserve deterministic replies if translation is temporarily unavailable.
                        if ($exact_reply !== null) {
                            $ai_response = $exact_reply;
                        } else {
                            // Do not expose provider responses, API configuration, or request details to visitors.
                            return new WP_Error('ai_chat_assistant_unavailable', 'The assistant is temporarily unavailable. Please try again shortly.');
                        }
                    } else {
                        $ai_response = trim((string) $generated);
                    }
                }
            }
        }

        if ($ai_response === '') {
            return new WP_Error('ai_chat_empty_response', 'The assistant did not return a response. Please try again.');
        }

        $wpdb->insert(
            $wpdb->prefix . 'ai_chat_messages',
            array(
                'session_id' => $session_id,
                'role' => 'assistant',
                'message' => $ai_response,
            ),
            array('%s', '%s', '%s')
        );

        return $ai_response;
    }

    private function check_rate_limit($session_id) {
        $limit = max(5, min(120, absint(get_option('ai_chat_rate_limit', 20))));
        $ip = $this->get_visitor_ip();
        $buckets = array(
            'ai_chat_rate_ip_' . md5($ip),
            'ai_chat_rate_session_' . md5($session_id),
        );

        foreach ($buckets as $bucket) {
            if ((int) get_transient($bucket) >= $limit) {
                return new WP_Error(
                    'ai_chat_rate_limited',
                    'Too many messages were sent. Please wait a minute and try again.',
                    array('status' => 429)
                );
            }
        }
        foreach ($buckets as $bucket) {
            set_transient($bucket, (int) get_transient($bucket) + 1, MINUTE_IN_SECONDS);
        }
        return true;
    }

    private function check_session_creation_rate_limit() {
        $bucket = 'ai_chat_session_rate_' . md5($this->get_visitor_ip());
        $count = (int) get_transient($bucket);
        if ($count >= 10) {
            return new WP_Error(
                'ai_chat_session_rate_limited',
                'Too many chat sessions were started. Please wait a minute and try again.',
                array('status' => 429)
            );
        }
        set_transient($bucket, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    private function sanitize_visitor_language($language = '') {
        $language = is_string($language) ? trim(sanitize_text_field($language)) : '';
        $language = str_replace('_', '-', $language);

        if ($language === '' && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $accepted = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']));
            $language = trim(explode(',', $accepted)[0]);
            $language = trim(explode(';', $language)[0]);
        }

        if ($language === '') {
            return '';
        }

        $language = substr($language, 0, 35);
        if (!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language)) {
            return '';
        }

        return $language;
    }

    private function get_visitor_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    public function add_admin_menu() {
        add_menu_page('AI Chats', 'AI Chats', 'manage_options', 'ai-chats', array($this, 'admin_page'), 'dashicons-format-chat', 30);
        add_submenu_page('ai-chats', 'Settings', 'Settings', 'manage_options', 'ai-chats-settings', array($this, 'settings_page'));
        add_submenu_page('ai-chats', 'Exact Replies', 'Exact Replies', 'manage_options', 'ai-chats-replies', array($this, 'exact_replies_page'));
        add_submenu_page('ai-chats', 'Embed Code', 'Embed Code', 'manage_options', 'ai-chats-embed', array($this, 'embed_page'));
    }
    
    public function admin_page() {
        global $wpdb;
        $chats = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ai_chats ORDER BY created_at DESC LIMIT 100");
        $total_chats = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ai_chats");
        $latest_time = 'No chats yet';
        if ($chats && !empty($chats[0]->created_at)) {
            $latest_time = date_i18n('M j, Y H:i', strtotime($chats[0]->created_at));
        }
        $active_provider_id = sanitize_key(get_option('ai_chat_api_provider', 'openai'));
        $active_provider = $this->providers->get($active_provider_id);
        $provider_label = $active_provider ? $active_provider->get_label() : 'Not configured';
        $active_model = $active_provider ? $this->providers->get_selected_model($active_provider_id) : '';
        ?>
        <div class="wrap ai-chat-admin">
            <div class="ai-chat-admin-hero">
                <div>
                    <h1>AI Chat History</h1>
                    <p class="ai-chat-admin-subtitle">Latest 100 conversations with your visitors.</p>
                </div>
                <div class="ai-chat-admin-stats">
                    <div class="ai-chat-admin-stat">
                        <span class="ai-chat-admin-stat-label">Total chats</span>
                        <span class="ai-chat-admin-stat-value"><?php echo number_format_i18n($total_chats); ?></span>
                    </div>
                    <div class="ai-chat-admin-stat">
                        <span class="ai-chat-admin-stat-label">Latest chat</span>
                        <span class="ai-chat-admin-stat-value"><?php echo esc_html($latest_time); ?></span>
                    </div>
                    <div class="ai-chat-admin-stat">
                        <span class="ai-chat-admin-stat-label">Active AI</span>
                        <span class="ai-chat-admin-stat-value ai-chat-admin-stat-value--compact"><?php echo esc_html($provider_label); ?></span>
                        <?php if ($active_model): ?><span class="ai-chat-admin-stat-meta"><?php echo esc_html($active_model); ?></span><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="ai-chat-card">
                <div class="ai-chat-card-header">
                    <div>
                        <h2>Conversations</h2>
                        <p class="ai-chat-card-subtitle">Click View to read the full transcript.</p>
                    </div>
                </div>
                <?php if ($chats): ?>
                    <div class="ai-chat-table-wrap">
                        <table class="ai-chat-table">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">User</th>
                                    <th scope="col">Phone</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chats as $chat): ?>
                                    <?php
                                    $user_name = trim($chat->user_name) ? $chat->user_name : 'Guest';
                                    $user_email = trim($chat->user_email) ? $chat->user_email : 'No email';
                                    $phone_label = trim($chat->purpose) ? $chat->purpose : 'Not provided';
                                    $formatted_date = date_i18n('M j, Y H:i', strtotime($chat->created_at));
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $chat->id; ?></td>
                                        <td>
                                            <div class="ai-chat-admin-user">
                                                <div class="ai-chat-admin-name"><?php echo esc_html($user_name); ?></div>
                                                <div class="ai-chat-admin-email"><?php echo esc_html($user_email); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="ai-chat-purpose"><?php echo esc_html($phone_label); ?></span>
                                        </td>
                                        <td><?php echo esc_html($formatted_date); ?></td>
                                        <td class="ai-chat-actions">
                                            <button class="button ai-chat-admin-btn ai-chat-admin-btn--ghost view-chat"
                                                data-session="<?php echo esc_attr($chat->session_id); ?>"
                                                data-name="<?php echo esc_attr($user_name); ?>"
                                                data-email="<?php echo esc_attr($user_email); ?>"
                                                data-phone="<?php echo esc_attr($phone_label); ?>"
                                                data-date="<?php echo esc_attr($formatted_date); ?>">
                                                View
                                            </button>
                                            <button class="button ai-chat-admin-btn ai-chat-admin-btn--danger delete-chat"
                                                data-session="<?php echo esc_attr($chat->session_id); ?>">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="ai-chat-empty">
                        <div class="ai-chat-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15a4 4 0 0 1-4 4H8l-5 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"></path>
                                <path d="M8 10h8"></path>
                                <path d="M8 14h5"></path>
                            </svg>
                        </div>
                        <h3>No chats yet</h3>
                        <p>Once visitors start chatting, their conversations will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div id="chat-modal" class="ai-chat-admin-modal" aria-hidden="true">
            <div class="ai-chat-admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="ai-chat-admin-modal-title">
                <div class="ai-chat-admin-modal-header">
                    <div>
                        <h2 id="ai-chat-admin-modal-title">Chat Conversation</h2>
                        <div class="ai-chat-admin-modal-meta" id="ai-chat-admin-modal-meta"></div>
                    </div>
                    <button type="button" id="close-modal" class="ai-chat-admin-close" aria-label="Close">&times;</button>
                </div>
                <div id="chat-messages-content" class="ai-chat-admin-thread"></div>
            </div>
        </div>
        <?php
    }
    
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage AI Chat settings.', 'ai-chat-support'));
        }

        $notice = '';
        $notice_type = 'success';

        if (isset($_POST['ai_chat_save_settings'])) {
            check_admin_referer('ai_chat_settings');
            $provider_id = isset($_POST['api_provider']) ? sanitize_key(wp_unslash($_POST['api_provider'])) : 'openai';
            if (!$this->providers->get($provider_id)) {
                $provider_id = 'openai';
            }

            foreach ($this->providers->all() as $id => $provider) {
                $remove_field = 'remove_' . $id . '_key';
                $key_field = $id . '_api_key';
                $model_field = 'model_' . $id;

                if (!empty($_POST[$remove_field])) {
                    $this->key_store->delete($id);
                    delete_option($this->provider_status_option($id));
                } elseif (isset($_POST[$key_field])) {
                    $new_key = trim(sanitize_text_field(wp_unslash($_POST[$key_field])));
                    if ($new_key !== '') {
                        $this->key_store->save($id, $new_key);
                        delete_option($this->provider_status_option($id));
                    }
                }

                if (isset($_POST[$model_field])) {
                    $this->providers->save_selected_model($id, wp_unslash($_POST[$model_field]));
                }
            }

            update_option('ai_chat_api_provider', $provider_id, false);
            update_option('ai_chat_welcome_message', isset($_POST['welcome_message']) ? sanitize_text_field(wp_unslash($_POST['welcome_message'])) : '', false);
            update_option('ai_chat_instruction', isset($_POST['ai_instruction']) ? sanitize_textarea_field(wp_unslash($_POST['ai_instruction'])) : '', false);
            update_option('ai_chat_auto_language', !empty($_POST['auto_language']) ? 1 : 0, false);
            update_option('ai_chat_badge_title', isset($_POST['badge_title']) ? sanitize_text_field(wp_unslash($_POST['badge_title'])) : '', false);
            update_option('ai_chat_badge_subtitle', isset($_POST['badge_subtitle']) ? sanitize_text_field(wp_unslash($_POST['badge_subtitle'])) : '', false);
            update_option('ai_chat_badge_icon', isset($_POST['badge_icon']) ? sanitize_text_field(wp_unslash($_POST['badge_icon'])) : '', false);
            $rate_limit = isset($_POST['rate_limit']) ? absint(wp_unslash($_POST['rate_limit'])) : 20;
            update_option('ai_chat_rate_limit', max(5, min(120, $rate_limit)), false);

            $notice = 'Settings saved successfully.';
        }

        $active_provider = get_option('ai_chat_api_provider', 'openai');
        if (!$this->providers->get($active_provider)) {
            $active_provider = 'openai';
        }
        $welcome = get_option('ai_chat_welcome_message', 'Hello! How can I help you today?');
        $instruction = get_option('ai_chat_instruction', '');
        $auto_language = (bool) get_option('ai_chat_auto_language', 1);
        $badge_title = get_option('ai_chat_badge_title', 'Welcome to AI Assistant');
        $badge_subtitle = get_option('ai_chat_badge_subtitle', 'How can we help you?');
        $badge_icon = get_option('ai_chat_badge_icon', '🤖');
        $rate_limit = max(5, min(120, absint(get_option('ai_chat_rate_limit', 20))));
        ?>
        <div class="wrap ai-chat-admin ai-chat-settings">
            <div class="ai-chat-admin-hero ai-chat-admin-hero--settings">
                <div>
                    <span class="ai-chat-eyebrow">AI workspace</span>
                    <h1>AI Chat Settings</h1>
                    <p class="ai-chat-admin-subtitle">Connect providers, choose models, control assistant behavior, and protect API usage.</p>
                </div>
                <div class="ai-chat-hero-status">
                    <span class="ai-chat-status-dot <?php echo $this->key_store->has($active_provider) ? 'is-online' : 'is-offline'; ?>"></span>
                    <span><?php echo esc_html($this->key_store->has($active_provider) ? 'Active provider configured' : 'Setup required'); ?></span>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <div id="ai-chat-settings-notice" class="ai-chat-inline-notice" role="status" aria-live="polite" hidden></div>

            <form method="post" id="ai-chat-settings-form" autocomplete="off">
                <?php wp_nonce_field('ai_chat_settings'); ?>

                <nav class="ai-chat-settings-tabs" aria-label="AI Chat settings sections" role="tablist">
                    <button type="button" id="ai-chat-tab-providers" class="ai-chat-settings-tab is-active" data-tab="providers" role="tab" aria-selected="true" aria-controls="ai-chat-panel-providers" tabindex="0">AI Providers</button>
                    <button type="button" id="ai-chat-tab-assistant" class="ai-chat-settings-tab" data-tab="assistant" role="tab" aria-selected="false" aria-controls="ai-chat-panel-assistant" tabindex="-1">Assistant</button>
                    <button type="button" id="ai-chat-tab-widget" class="ai-chat-settings-tab" data-tab="widget" role="tab" aria-selected="false" aria-controls="ai-chat-panel-widget" tabindex="-1">Widget</button>
                    <button type="button" id="ai-chat-tab-security" class="ai-chat-settings-tab" data-tab="security" role="tab" aria-selected="false" aria-controls="ai-chat-panel-security" tabindex="-1">Security</button>
                </nav>

                <section id="ai-chat-panel-providers" class="ai-chat-settings-panel is-active" data-panel="providers" role="tabpanel" aria-labelledby="ai-chat-tab-providers">
                    <div class="ai-chat-section-heading">
                        <div>
                            <h2>AI providers</h2>
                            <p>Configure each provider independently, then select which one powers visitor conversations.</p>
                        </div>
                    </div>
                    <div class="ai-chat-provider-grid">
                        <?php
                        foreach ($this->providers->all() as $id => $provider) {
                            $this->render_provider_settings_card($id, $provider, $active_provider);
                        }
                        ?>
                    </div>
                </section>

                <section id="ai-chat-panel-assistant" class="ai-chat-settings-panel" data-panel="assistant" role="tabpanel" aria-labelledby="ai-chat-tab-assistant" hidden>
                    <div class="ai-chat-card ai-chat-settings-card">
                        <div class="ai-chat-card-header">
                            <div>
                                <h2>Assistant behavior</h2>
                                <p class="ai-chat-card-subtitle">Give the assistant accurate business context and a clear opening message.</p>
                            </div>
                        </div>
                        <div class="ai-chat-card-body ai-chat-form-stack">
                            <label class="ai-chat-admin-field">
                                <span class="ai-chat-admin-field-label">Website context and instructions</span>
                                <textarea name="ai_instruction" rows="9" class="ai-chat-admin-textarea" placeholder="Describe your business, services, opening hours, policies, tone, and boundaries."><?php echo esc_textarea($instruction); ?></textarea>
                                <span class="ai-chat-field-help">This instruction is sent server-side with each provider request. Do not place private customer data here.</span>
                            </label>
                            <label class="ai-chat-language-setting">
                                <span class="ai-chat-language-setting-copy">
                                    <span class="ai-chat-admin-field-label">Automatically match the visitor’s language</span>
                                    <span class="ai-chat-field-help">Detects the language of each visitor message and replies in the same language. The browser language is used only when a message is too short or ambiguous.</span>
                                </span>
                                <span class="ai-chat-switch">
                                    <input type="checkbox" name="auto_language" value="1" <?php checked($auto_language); ?>>
                                    <span class="ai-chat-switch-track" aria-hidden="true"></span>
                                </span>
                            </label>
                            <label class="ai-chat-admin-field">
                                <span class="ai-chat-admin-field-label">Welcome message</span>
                                <input type="text" name="welcome_message" value="<?php echo esc_attr($welcome); ?>" class="ai-chat-admin-input" maxlength="240">
                                <span class="ai-chat-field-help">Shown before the visitor sends a message. Enter a neutral or multilingual welcome message when serving multiple languages.</span>
                            </label>
                        </div>
                    </div>
                </section>

                <section id="ai-chat-panel-widget" class="ai-chat-settings-panel" data-panel="widget" role="tabpanel" aria-labelledby="ai-chat-tab-widget" hidden>
                    <div class="ai-chat-card ai-chat-settings-card">
                        <div class="ai-chat-card-header">
                            <div>
                                <h2>Widget appearance</h2>
                                <p class="ai-chat-card-subtitle">Keep the welcome badge concise and easy to understand.</p>
                            </div>
                        </div>
                        <div class="ai-chat-card-body">
                            <div class="ai-chat-field-grid">
                                <label class="ai-chat-admin-field">
                                    <span class="ai-chat-admin-field-label">Badge title</span>
                                    <input type="text" name="badge_title" value="<?php echo esc_attr($badge_title); ?>" class="ai-chat-admin-input" maxlength="80">
                                </label>
                                <label class="ai-chat-admin-field">
                                    <span class="ai-chat-admin-field-label">Badge subtitle</span>
                                    <input type="text" name="badge_subtitle" value="<?php echo esc_attr($badge_subtitle); ?>" class="ai-chat-admin-input" maxlength="120">
                                </label>
                                <label class="ai-chat-admin-field ai-chat-icon-field">
                                    <span class="ai-chat-admin-field-label">Badge icon</span>
                                    <input type="text" name="badge_icon" value="<?php echo esc_attr($badge_icon); ?>" class="ai-chat-admin-input" maxlength="12">
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="ai-chat-panel-security" class="ai-chat-settings-panel" data-panel="security" role="tabpanel" aria-labelledby="ai-chat-tab-security" hidden>
                    <div class="ai-chat-security-grid">
                        <div class="ai-chat-card ai-chat-settings-card">
                            <div class="ai-chat-card-header">
                                <div>
                                    <h2>API key protection</h2>
                                    <p class="ai-chat-card-subtitle">Provider keys stay on the WordPress server and are never included in frontend JavaScript.</p>
                                </div>
                            </div>
                            <div class="ai-chat-card-body">
                                <div class="ai-chat-security-item">
                                    <span class="ai-chat-security-icon dashicons dashicons-lock"></span>
                                    <div>
                                        <strong><?php echo esc_html($this->key_store->encryption_method()); ?></strong>
                                        <p>Saved keys are stored in non-autoloaded WordPress options and masked after saving.</p>
                                    </div>
                                </div>
                                <?php if (!$this->key_store->is_strong_encryption_available()): ?>
                                    <div class="ai-chat-warning-box">Strong encryption is unavailable on this server. Enable Sodium or OpenSSL in PHP; the current compatibility fallback is not encryption.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ai-chat-card ai-chat-settings-card">
                            <div class="ai-chat-card-header">
                                <div>
                                    <h2>Abuse protection</h2>
                                    <p class="ai-chat-card-subtitle">Limit message bursts to reduce automated API-credit abuse.</p>
                                </div>
                            </div>
                            <div class="ai-chat-card-body">
                                <label class="ai-chat-admin-field">
                                    <span class="ai-chat-admin-field-label">Messages per minute per visitor</span>
                                    <input type="number" name="rate_limit" value="<?php echo esc_attr($rate_limit); ?>" class="ai-chat-admin-input ai-chat-number-input" min="5" max="120" step="1">
                                    <span class="ai-chat-field-help">Recommended: 20. Applies per IP address and per chat session to both the native widget and external embed. New-session bursts are limited separately.</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="ai-chat-save-bar">
                    <div>
                        <strong>Ready to apply changes?</strong>
                        <span>Connection tests do not save a newly entered key until you click Save Settings.</span>
                    </div>
                    <button type="submit" name="ai_chat_save_settings" class="button button-primary ai-chat-primary-btn">Save Settings</button>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_provider_settings_card($id, AI_Chat_Provider_Interface $provider, $active_provider) {
        $models = $this->providers->get_models($id);
        $selected_model = $this->providers->get_selected_model($id);
        $masked_key = $this->key_store->masked($id);
        $status = $this->get_provider_status($id);
        $updated_at = $this->providers->get_models_updated_at($id);
        $is_active = $active_provider === $id;
        ?>
        <article class="ai-chat-provider-card <?php echo $is_active ? 'is-selected' : ''; ?>" data-provider="<?php echo esc_attr($id); ?>">
            <div class="ai-chat-provider-card-top">
                <label class="ai-chat-provider-choice">
                    <input type="radio" name="api_provider" value="<?php echo esc_attr($id); ?>" <?php checked($is_active); ?>>
                    <span class="ai-chat-provider-mark"><?php echo $id === 'openai' ? 'OA' : 'G'; ?></span>
                    <span>
                        <strong><?php echo esc_html($provider->get_label()); ?></strong>
                        <small><?php echo $is_active ? 'Active provider' : 'Available provider'; ?></small>
                    </span>
                </label>
                <span class="ai-chat-connection-status <?php echo esc_attr($status['class']); ?>" data-status-chip>
                    <span class="ai-chat-status-dot"></span>
                    <span data-status-text><?php echo esc_html($status['label']); ?></span>
                </span>
            </div>

            <div class="ai-chat-provider-card-body">
                <label class="ai-chat-admin-field">
                    <span class="ai-chat-admin-field-label"><?php echo esc_html($provider->get_label()); ?> API key</span>
                    <div class="ai-chat-secret-field">
                        <input type="password" name="<?php echo esc_attr($id); ?>_api_key" value="" class="ai-chat-admin-input" placeholder="<?php echo esc_attr($masked_key ? 'Saved ' . $masked_key . ' — enter a new key to replace it' : 'Enter API key'); ?>" autocomplete="new-password" data-api-key>
                        <button type="button" class="ai-chat-secret-toggle" aria-label="Show API key" aria-pressed="false" title="Show or hide API key"><span class="dashicons dashicons-visibility"></span></button>
                    </div>
                    <span class="ai-chat-field-help"><?php echo $masked_key ? 'A key is saved. Leave this field empty to keep it.' : 'No API key has been saved for this provider.'; ?></span>
                </label>

                <label class="ai-chat-admin-field">
                    <span class="ai-chat-admin-field-label">Model</span>
                    <select name="model_<?php echo esc_attr($id); ?>" class="ai-chat-admin-select" data-model-select>
                        <?php
                        $found_selected = false;
                        foreach ($models as $model) {
                            $model_id = isset($model['id']) ? $model['id'] : '';
                            if ($model_id === '') {
                                continue;
                            }
                            if ($model_id === $selected_model) {
                                $found_selected = true;
                            }
                            ?>
                            <option
                                value="<?php echo esc_attr($model_id); ?>"
                                data-description="<?php echo esc_attr(isset($model['description']) ? $model['description'] : ''); ?>"
                                data-capability="<?php echo esc_attr(isset($model['capability']) ? $model['capability'] : 'Text generation'); ?>"
                                data-status="<?php echo esc_attr(isset($model['status']) ? $model['status'] : 'stable'); ?>"
                                <?php selected($selected_model, $model_id); ?>
                            ><?php echo esc_html((isset($model['name']) ? $model['name'] : $model_id) . ' — ' . $model_id); ?></option>
                            <?php
                        }
                        if (!$found_selected && $selected_model !== ''):
                            ?>
                            <option value="<?php echo esc_attr($selected_model); ?>" selected data-description="Previously selected model." data-capability="Text generation" data-status="custom"><?php echo esc_html($selected_model); ?></option>
                        <?php endif; ?>
                    </select>
                </label>

                <div class="ai-chat-model-summary" data-model-summary>
                    <strong data-model-name></strong>
                    <p data-model-description></p>
                    <div class="ai-chat-model-meta">
                        <span data-model-capability></span>
                        <span data-model-status></span>
                    </div>
                </div>

                <div class="ai-chat-provider-actions">
                    <button type="button" class="button ai-chat-test-connection" data-provider-action="test">Test Connection</button>
                    <button type="button" class="button ai-chat-refresh-models" data-provider-action="refresh">Refresh Models</button>
                </div>

                <div class="ai-chat-provider-foot">
                    <label class="ai-chat-remove-key">
                        <input type="checkbox" name="remove_<?php echo esc_attr($id); ?>_key" value="1">
                        Remove saved API key when settings are saved
                    </label>
                    <?php if ($updated_at): ?>
                        <span>Models refreshed <?php echo esc_html(human_time_diff($updated_at, time())); ?> ago</span>
                    <?php else: ?>
                        <span>Using built-in model catalog</span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }

    private function provider_status_option($provider_id) {
        return 'ai_chat_provider_status_' . sanitize_key($provider_id);
    }

    private function get_provider_status($provider_id) {
        $saved = get_option($this->provider_status_option($provider_id), array());
        if (!$this->key_store->has($provider_id)) {
            return array('label' => 'Not configured', 'class' => 'is-neutral');
        }
        if (!is_array($saved) || empty($saved['state'])) {
            return array('label' => 'Not tested', 'class' => 'is-neutral');
        }
        if ($saved['state'] === 'connected') {
            return array('label' => 'Connected', 'class' => 'is-success');
        }
        return array('label' => 'Needs attention', 'class' => 'is-error');
    }

    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not authorized to test API connections.'), 403);
        }
        check_ajax_referer('ai_chat_settings_nonce', 'nonce');

        $provider_id = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        $provider = $this->providers->get($provider_id);
        if (!$provider) {
            wp_send_json_error(array('message' => 'Unsupported AI provider.'), 400);
        }

        $submitted_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
        $api_key = $submitted_key !== '' ? $submitted_key : $this->key_store->get($provider_id);
        $model = isset($_POST['model']) ? preg_replace('/[^a-zA-Z0-9._:\-]/', '', wp_unslash($_POST['model'])) : $this->providers->get_selected_model($provider_id);

        $result = $provider->test_connection($api_key, $model);
        if (is_wp_error($result)) {
            update_option($this->provider_status_option($provider_id), array(
                'state' => 'error',
                'tested_at' => time(),
                'model' => $model,
                'message' => $result->get_error_message(),
            ), false);
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        update_option($this->provider_status_option($provider_id), array(
            'state' => 'connected',
            'tested_at' => time(),
            'model' => $model,
            'message' => $result['message'],
        ), false);
        wp_send_json_success(array(
            'message' => $result['message'],
            'sample' => isset($result['response']) ? $result['response'] : '',
        ));
    }

    public function ajax_refresh_models() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not authorized to refresh models.'), 403);
        }
        check_ajax_referer('ai_chat_settings_nonce', 'nonce');

        $provider_id = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        if (!$this->providers->get($provider_id)) {
            wp_send_json_error(array('message' => 'Unsupported AI provider.'), 400);
        }

        $submitted_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
        $api_key = $submitted_key !== '' ? $submitted_key : $this->key_store->get($provider_id);
        $models = $this->providers->refresh_models($provider_id, $api_key);
        if (is_wp_error($models)) {
            wp_send_json_error(array('message' => $models->get_error_message()), 400);
        }

        wp_send_json_success(array(
            'message' => sprintf('%d compatible models loaded from %s.', count($models), $this->providers->get($provider_id)->get_label()),
            'models' => $models,
        ));
    }

    public function exact_replies_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $wpdb->prefix . 'ai_chat_exact_replies';

        $notice = '';
        $notice_type = 'success';

        if (isset($_GET['delete'])) {
            $delete_id = absint(wp_unslash($_GET['delete']));
            if ($delete_id) {
                check_admin_referer('ai_chat_delete_reply_' . $delete_id);
                $wpdb->delete($table, array('id' => $delete_id));
                $notice = 'Exact reply deleted.';
            }
        }

        $edit_id = isset($_GET['edit']) ? absint(wp_unslash($_GET['edit'])) : 0;
        $editing = false;
        $edit_question = '';
        $edit_answer = '';

        if ($edit_id) {
            $edit_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $edit_id));
            if ($edit_row) {
                $editing = true;
                $edit_question = $edit_row->question;
                $edit_answer = $edit_row->answer;
            } else {
                $edit_id = 0;
            }
        }

        if (isset($_POST['ai_chat_save_reply'])) {
            check_admin_referer('ai_chat_save_reply');
            $question = isset($_POST['ai_chat_question']) ? sanitize_textarea_field(wp_unslash($_POST['ai_chat_question'])) : '';
            $answer = isset($_POST['ai_chat_answer']) ? sanitize_textarea_field(wp_unslash($_POST['ai_chat_answer'])) : '';
            $question = trim(str_replace(array("\r\n", "\r"), "\n", $question));
            $answer = str_replace(array("\r\n", "\r"), "\n", $answer);
            $reply_id = isset($_POST['reply_id']) ? absint(wp_unslash($_POST['reply_id'])) : 0;

            if ($question === '' || trim($answer) === '') {
                $notice = 'Please enter both a question and an answer.';
                $notice_type = 'error';
            } else {
                if ($reply_id) {
                    $wpdb->update(
                        $table,
                        array('question' => $question, 'answer' => $answer),
                        array('id' => $reply_id)
                    );
                    $notice = 'Exact reply updated.';
                    $editing = false;
                    $edit_id = 0;
                    $edit_question = '';
                    $edit_answer = '';
                } else {
                    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE question = %s LIMIT 1", $question));
                    if ($existing_id) {
                        $wpdb->update(
                            $table,
                            array('question' => $question, 'answer' => $answer),
                            array('id' => $existing_id)
                        );
                        $notice = 'Existing reply updated for that question.';
                    } else {
                        $wpdb->insert(
                            $table,
                            array('question' => $question, 'answer' => $answer)
                        );
                        $notice = 'Exact reply added.';
                    }
                }
                $edit_question = '';
                $edit_answer = '';
            }
        }

        $replies = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
        $form_title = $editing ? 'Edit Exact Reply' : 'Add Exact Reply';
        $submit_label = $editing ? 'Update Reply' : 'Save Reply';
        $cancel_url = admin_url('admin.php?page=ai-chats-replies');
        ?>
        <div class="wrap ai-chat-admin ai-chat-replies">
            <div class="ai-chat-admin-hero ai-chat-admin-hero--settings">
                <div>
                    <h1>Exact Replies</h1>
                    <p class="ai-chat-admin-subtitle">Return custom answers for exact matching questions.</p>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <form method="post" class="ai-chat-replies-form">
                <?php wp_nonce_field('ai_chat_save_reply'); ?>
                <input type="hidden" name="reply_id" value="<?php echo esc_attr($edit_id); ?>">

                <div class="ai-chat-card">
                    <div class="ai-chat-card-header">
                        <div>
                            <h2><?php echo esc_html($form_title); ?></h2>
                            <p class="ai-chat-card-subtitle">Questions must match exactly (case sensitive).</p>
                        </div>
                    </div>
                    <div class="ai-chat-card-body">
                        <div class="ai-chat-reply-grid">
                            <label class="ai-chat-admin-field">
                                <span class="ai-chat-admin-field-label">Exact Question</span>
                                <textarea name="ai_chat_question" rows="4" class="ai-chat-admin-textarea" placeholder="Type the exact user question" required><?php echo esc_textarea($edit_question); ?></textarea>
                            </label>
                            <label class="ai-chat-admin-field">
                                <span class="ai-chat-admin-field-label">Exact Answer</span>
                                <textarea name="ai_chat_answer" rows="6" class="ai-chat-admin-textarea" placeholder="Type the exact AI reply" required><?php echo esc_textarea($edit_answer); ?></textarea>
                            </label>
                        </div>
                        <div class="ai-chat-form-actions">
                            <input type="submit" name="ai_chat_save_reply" class="button button-primary ai-chat-primary-btn" value="<?php echo esc_attr($submit_label); ?>">
                            <?php if ($editing): ?>
                                <a href="<?php echo esc_url($cancel_url); ?>" class="button ai-chat-admin-btn ai-chat-admin-btn--ghost">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <div class="ai-chat-card">
                <div class="ai-chat-card-header">
                    <div>
                        <h2>Saved Replies</h2>
                        <p class="ai-chat-card-subtitle">These answers will be returned exactly as stored.</p>
                    </div>
                </div>
                <?php if ($replies): ?>
                    <div class="ai-chat-table-wrap">
                        <table class="ai-chat-table">
                            <thead>
                                <tr>
                                    <th scope="col">Question</th>
                                    <th scope="col">Answer</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($replies as $reply): ?>
                                    <?php
                                    $edit_url = add_query_arg(
                                        array('page' => 'ai-chats-replies', 'edit' => (int) $reply->id),
                                        admin_url('admin.php')
                                    );
                                    $delete_url = wp_nonce_url(
                                        add_query_arg(
                                            array('page' => 'ai-chats-replies', 'delete' => (int) $reply->id),
                                            admin_url('admin.php')
                                        ),
                                        'ai_chat_delete_reply_' . (int) $reply->id
                                    );
                                    ?>
                                    <tr>
                                        <td><div class="ai-chat-reply-text"><?php echo esc_html($reply->question); ?></div></td>
                                        <td><div class="ai-chat-reply-text"><?php echo esc_html($reply->answer); ?></div></td>
                                        <td><?php echo esc_html(date_i18n('M j, Y', strtotime($reply->created_at))); ?></td>
                                        <td class="ai-chat-actions">
                                            <a class="button ai-chat-admin-btn ai-chat-admin-btn--ghost" href="<?php echo esc_url($edit_url); ?>">Edit</a>
                                            <a class="button ai-chat-admin-btn ai-chat-admin-btn--danger" href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Delete this exact reply?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="ai-chat-empty">
                        <div class="ai-chat-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15a4 4 0 0 1-4 4H8l-5 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"></path>
                                <path d="M8 10h8"></path>
                                <path d="M8 14h5"></path>
                            </svg>
                        </div>
                        <h3>No exact replies yet</h3>
                        <p>Add your first question and answer above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function embed_page() {
        if (!current_user_can('manage_options')) return;

        $notice = '';
        if (isset($_POST['ai_chat_regen_widget_key'])) {
            check_admin_referer('ai_chat_regen_widget_key');
            $new_key = wp_generate_password(32, false, false);
            update_option('ai_chat_widget_key', $new_key);
            $notice = 'Widget access key regenerated.';
        }

        $key = get_option('ai_chat_widget_key', '');
        if ($key === '') {
            $key = wp_generate_password(32, false, false);
            update_option('ai_chat_widget_key', $key);
        }

        $script_src = home_url('/chatbot-widget.js?key=' . rawurlencode($key));
        $embed_code = '<script src="' . esc_url($script_src) . '"></script>';
        ?>
        <div class="wrap ai-chat-admin ai-chat-embed">
            <div class="ai-chat-admin-hero ai-chat-admin-hero--settings">
                <div>
                    <h1>Embed Code</h1>
                    <p class="ai-chat-admin-subtitle">Add the chatbot to any external site with a single script tag.</p>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <div class="ai-chat-card">
                <div class="ai-chat-card-header">
                    <div>
                        <h2>Script Tag</h2>
                        <p class="ai-chat-card-subtitle">Paste this just before the closing &lt;/body&gt; tag.</p>
                    </div>
                </div>
                <div class="ai-chat-card-body">
                    <label class="ai-chat-admin-field">
                        <span class="ai-chat-admin-field-label">Embed Script</span>
                        <textarea class="ai-chat-admin-textarea ai-chat-embed-code" rows="3" readonly><?php echo esc_textarea($embed_code); ?></textarea>
                    </label>
                    <div class="ai-chat-embed-actions">
                        <form method="post">
                            <?php wp_nonce_field('ai_chat_regen_widget_key'); ?>
                            <button type="submit" name="ai_chat_regen_widget_key" class="button ai-chat-admin-btn ai-chat-admin-btn--ghost">Regenerate Access Key</button>
                        </form>
                    </div>
                    <p class="description">Use this exact script tag on any site (HTML, Shopify, Wix, etc.). This revocable widget access key is not an AI-provider API key and will be visible in the embedded page source.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function admin_scripts($hook) {
        $is_history = ($hook === 'toplevel_page_ai-chats');
        $is_settings = ($hook === 'ai-chats_page_ai-chats-settings');
        $is_replies = ($hook === 'ai-chats_page_ai-chats-replies');
        $is_embed = ($hook === 'ai-chats_page_ai-chats-embed');
        if (!$is_history && !$is_settings && !$is_replies && !$is_embed) return;

        wp_enqueue_style('ai-chat-admin', AI_CHAT_PLUGIN_URL . 'admin.css', array(), AI_CHAT_VERSION);

        if ($is_history) {
            wp_enqueue_script('ai-chat-admin', AI_CHAT_PLUGIN_URL . 'admin.js', array('jquery'), AI_CHAT_VERSION, true);
            wp_localize_script('ai-chat-admin', 'aiChatAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ai_chat_admin_nonce')
            ));
        }

        if ($is_settings) {
            wp_enqueue_script('ai-chat-admin-settings', AI_CHAT_PLUGIN_URL . 'admin-settings.js', array('jquery'), AI_CHAT_VERSION, true);
            wp_localize_script('ai-chat-admin-settings', 'aiChatSettings', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ai_chat_settings_nonce'),
            ));
        }
    }
    
    public function frontend_scripts() {
        if (is_admin()) return;
        wp_enqueue_style('ai-chat-style', AI_CHAT_PLUGIN_URL . 'style.css', array(), AI_CHAT_VERSION);
        wp_enqueue_script('ai-chat-script', AI_CHAT_PLUGIN_URL . 'script.js', array('jquery'), AI_CHAT_VERSION, true);
        wp_localize_script('ai-chat-script', 'aiChat', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_chat_nonce'),
            'welcome_message' => get_option('ai_chat_welcome_message', 'Hello! How can I help you today?'),
            'site_language' => str_replace('_', '-', get_locale())
        ));
    }
    
   public function chat_widget() {
        if (is_admin()) return;
        $badge_title = get_option('ai_chat_badge_title', 'Welcome to AI Assistant');
        $badge_subtitle = get_option('ai_chat_badge_subtitle', 'How can we help you?');
        $badge_icon = get_option('ai_chat_badge_icon', '🤖');
        ?>
        <div id="ai-chat-welcome-badge" role="button" tabindex="0" aria-label="Open chat">
            <span class="welcome-badge-icon"><?php echo esc_html($badge_icon); ?></span>
            <div class="welcome-badge-text">
                <h4 class="welcome-badge-title"><?php echo esc_html($badge_title); ?></h4>
                <p class="welcome-badge-subtitle"><?php echo esc_html($badge_subtitle); ?></p>
            </div>
            <button type="button" class="welcome-badge-close" aria-label="Dismiss chat prompt">&times;</button>
        </div>

        <button id="ai-chat-button" type="button" aria-label="Open chat">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 12a8 8 0 0 1 16 0"></path>
                <path d="M4 12v5a2 2 0 0 0 2 2h2v-7H6a2 2 0 0 0-2 2"></path>
                <path d="M20 12v5a2 2 0 0 1-2 2h-2v-7h2a2 2 0 0 1 2 2"></path>
            </svg>
        </button>

        <div id="ai-chat-window" class="show-prechat" role="region" aria-label="AI chat" style="display:none;">
            <div class="ai-chat-header">
                <div class="ai-chat-title">
                        <div class="ai-chat-title-text">
                            <span class="ai-chat-title-name">AI Chat Support</span>
                        </div>
                </div>
                <div class="ai-chat-controls">
                    <button type="button" class="ai-chat-control ai-chat-minimize" aria-label="Minimize chat">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <path d="M6 9l6 6 6-6"></path>
                        </svg>
                    </button>
                    <button type="button" class="ai-chat-control ai-chat-close-chat" aria-label="Close chat">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <path d="M6 6l12 12M18 6l-12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div id="ai-chat-prechat" class="ai-chat-prechat">
                <div class="ai-chat-prechat-card">
                    <div class="ai-chat-prechat-header">
                        <h3 class="ai-chat-prechat-title">Start Chat</h3>
                        <p class="ai-chat-prechat-subtitle">Tell us a bit about you to begin.</p>
                    </div>
                    <form id="ai-chat-user-form" class="ai-chat-prechat-form">
                        <label class="ai-chat-field">
                            <span class="ai-chat-field-label">Name</span>
                            <input type="text" id="chat-name" placeholder="Your name" autocomplete="name" required>
                        </label>
                        <label class="ai-chat-field">
                            <span class="ai-chat-field-label">Email</span>
                            <input type="email" id="chat-email" placeholder="you@example.com" autocomplete="email" required>
                        </label>
                        <label class="ai-chat-field">
                            <span class="ai-chat-field-label">Phone Number</span>
                            <input type="tel" id="chat-phone" placeholder="Phone number (optional)" autocomplete="tel" inputmode="tel">
                        </label>
                        <div class="ai-chat-form-error" role="alert" aria-live="polite" hidden></div>
                        <button type="submit">Start Chat</button>
                    </form>
                </div>
            </div>

            <div id="ai-chat-messages" aria-live="polite"></div>
            
            <div class="ai-chat-input-area">
                <div id="ai-chat-emoji-picker" style="display:none;"></div>
                <div class="ai-chat-left-actions">
                    <button type="button" id="ai-chat-emoji-toggle" class="icon-btn emoji" title="Emojis" aria-label="Insert emoji">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                            <circle cx="9" cy="10" r="1"></circle>
                            <circle cx="15" cy="10" r="1"></circle>
                        </svg>
                    </button>
                </div>
                <textarea id="ai-chat-input" rows="1" placeholder="Type a message..." autocomplete="off" aria-label="Message"></textarea>
                <div class="ai-chat-right-actions">
                    <button type="button" id="ai-chat-voice-toggle" class="icon-btn voice" title="Voice Input" aria-label="Voice input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
                    </button>
                    <button id="ai-chat-send" type="button" aria-label="Send message">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    // Save User
    public function save_user_info() {
        check_ajax_referer('ai_chat_nonce', 'nonce');
        global $wpdb;

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = '';
        if (isset($_POST['phone'])) {
            $phone = sanitize_text_field(wp_unslash($_POST['phone']));
        } elseif (isset($_POST['purpose'])) {
            $phone = sanitize_text_field(wp_unslash($_POST['purpose']));
        }

        if ($name === '' || !is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid name and email address.'), 400);
        }

        $session_rate = $this->check_session_creation_rate_limit();
        if (is_wp_error($session_rate)) {
            wp_send_json_error(
                array('message' => $session_rate->get_error_message(), 'code' => $session_rate->get_error_code()),
                429
            );
        }

        $session_id = 'chat_' . wp_generate_uuid4();
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ai_chats',
            array(
                'session_id' => $session_id,
                'user_name' => $name,
                'user_email' => $email,
                'purpose' => $phone,
            ),
            array('%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            wp_send_json_error(array('message' => 'The chat could not be started. Please try again.'), 500);
        }

        wp_send_json_success(array('session_id' => $session_id));
    }

    // Load History (Fixes Disappearing Chat on Refresh)
    public function load_chat_history() {
        check_ajax_referer('ai_chat_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        if ($session_id === '') {
            wp_send_json_error(array('message' => 'No chat session was provided.'), 400);
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ai_chats WHERE session_id = %s LIMIT 1",
            $session_id
        ));
        if (!$exists) {
            wp_send_json_error(array('message' => 'This chat session is no longer valid.'), 404);
        }

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role, message, created_at FROM {$wpdb->prefix}ai_chat_messages WHERE session_id = %s ORDER BY id ASC",
            $session_id
        ));
        wp_send_json_success($messages);
    }
    
    // Send Message
    public function send_message() {
        check_ajax_referer('ai_chat_nonce', 'nonce');
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $visitor_language = isset($_POST['visitor_language']) ? $this->sanitize_visitor_language(wp_unslash($_POST['visitor_language'])) : '';

        $ai_response = $this->process_message($session_id, $message, $visitor_language);
        if (is_wp_error($ai_response)) {
            $status = 400;
            $error_data = $ai_response->get_error_data();
            if (is_array($error_data) && !empty($error_data['status'])) {
                $status = absint($error_data['status']);
            } elseif ($ai_response->get_error_code() === 'ai_chat_invalid_session') {
                $status = 404;
            } elseif ($ai_response->get_error_code() === 'ai_chat_assistant_unavailable') {
                $status = 503;
            }
            wp_send_json_error(
                array(
                    'message' => $ai_response->get_error_message(),
                    'code' => $ai_response->get_error_code(),
                ),
                $status
            );
        }
        wp_send_json_success(array('response' => $ai_response));
    }

    private function get_exact_reply($message) {
        global $wpdb;
        $question = trim(str_replace(array("\r\n", "\r"), "\n", $message));
        if ($question === '') return null;
        $table = $wpdb->prefix . 'ai_chat_exact_replies';
        $reply = $wpdb->get_var($wpdb->prepare("SELECT answer FROM {$table} WHERE question = %s ORDER BY id DESC LIMIT 1", $question));
        if ($reply === null) return null;
        return $reply;
    }
    
    public function get_messages() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to view chat messages.', 'ai-chat-support')), 403);
        }
        check_ajax_referer('ai_chat_admin_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        if ($session_id === '') {
            wp_send_json_error(array('message' => __('A valid chat session is required.', 'ai-chat-support')), 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ai_chat_messages';
        $messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT role, message, created_at FROM {$table} WHERE session_id = %s ORDER BY created_at ASC",
                $session_id
            )
        );

        wp_send_json_success(is_array($messages) ? $messages : array());
    }
    
    public function delete_chat() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to delete chats.', 'ai-chat-support')), 403);
        }
        check_ajax_referer('ai_chat_admin_nonce', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        if ($session_id === '') {
            wp_send_json_error(array('message' => __('A valid chat session is required.', 'ai-chat-support')), 400);
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'ai_chats', array('session_id' => $session_id), array('%s'));
        $wpdb->delete($wpdb->prefix . 'ai_chat_messages', array('session_id' => $session_id), array('%s'));
        wp_send_json_success();
    }
}
new AI_Chat_Plugin();