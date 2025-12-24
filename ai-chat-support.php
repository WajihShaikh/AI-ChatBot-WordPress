<?php
/**
 * Plugin Name: AI Chat Support
 * Plugin URI: https://example.com
 * Description: AI-powered chatbot with Custom Context, History Fix, and Smooth Scroll.
 * Version: 1.1.6
 * Author: Wajih Shaikh
 * Author URI: https://goaccelovate.com
 * Company: GoAccelovate
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

define('AI_CHAT_VERSION', '1.1.6');
define('AI_CHAT_DB_VERSION', '1.1.4');
define('AI_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

class AI_Chat_Plugin {
    
    public function __construct() {
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
    }
    
    public function activate() {
        $this->create_tables();
        update_option('ai_chat_db_version', AI_CHAT_DB_VERSION);
        if (!get_option('ai_chat_widget_key')) {
            add_option('ai_chat_widget_key', wp_generate_password(32, false, false));
        }
        flush_rewrite_rules();
        
        // Defaults
        add_option('ai_chat_api_provider', 'gemini');
        add_option('ai_chat_model', 'gpt-4');
        add_option('ai_chat_gemini_model', 'gemini-1.5-flash');
        add_option('ai_chat_welcome_message', 'Hello! How can I help you today?');
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
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
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
            'widgetTitle' => 'AI Support'
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
            'permission_callback' => '__return_true',
        ));

        register_rest_route('ai-chat/v1', '/message', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_send_message'),
            'permission_callback' => '__return_true',
        ));
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
        $purpose = sanitize_text_field($request->get_param('purpose'));

        if (empty($name) || empty($email) || empty($purpose)) {
            return new WP_Error('ai_chat_missing_fields', 'Name, email, and purpose are required.', array('status' => 400));
        }

        $session_id = uniqid('chat_', true);
        $wpdb->insert($wpdb->prefix . 'ai_chats', array(
            'session_id' => $session_id,
            'user_name' => $name,
            'user_email' => $email,
            'purpose' => $purpose
        ));

        return rest_ensure_response(array('session_id' => $session_id));
    }

    public function rest_send_message($request) {
        $key_check = $this->validate_widget_key($request);
        if (is_wp_error($key_check)) return $key_check;

        $session_id = sanitize_text_field($request->get_param('session_id'));
        $message = sanitize_textarea_field($request->get_param('message'));

        if (empty($session_id) || $message === '') {
            return new WP_Error('ai_chat_missing_fields', 'Session ID and message are required.', array('status' => 400));
        }

        $response = $this->process_message($session_id, $message);
        if (is_wp_error($response)) return $response;
        return rest_ensure_response(array('response' => $response));
    }

    private function process_message($session_id, $message) {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'ai_chat_messages', array(
            'session_id' => $session_id,
            'role' => 'user',
            'message' => $message
        ));

        $exact_reply = $this->get_exact_reply($message);
        if ($exact_reply !== null) {
            $ai_response = $exact_reply;
        } else {
            $provider = get_option('ai_chat_api_provider', 'openai');
            $ai_response = ($provider === 'openai') ? $this->get_openai_response($session_id) : $this->get_gemini_response($session_id);
        }

        $wpdb->insert($wpdb->prefix . 'ai_chat_messages', array(
            'session_id' => $session_id,
            'role' => 'assistant',
            'message' => $ai_response
        ));

        return $ai_response;
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
                                    <th scope="col">Purpose</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chats as $chat): ?>
                                    <?php
                                    $user_name = trim($chat->user_name) ? $chat->user_name : 'Guest';
                                    $user_email = trim($chat->user_email) ? $chat->user_email : 'No email';
                                    $purpose_label = trim($chat->purpose) ? $chat->purpose : 'General';
                                    $purpose_slug = sanitize_title($purpose_label);
                                    $purpose_class = $purpose_slug ? 'ai-chat-purpose-' . $purpose_slug : '';
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
                                            <span class="ai-chat-purpose <?php echo esc_attr($purpose_class); ?>"><?php echo esc_html($purpose_label); ?></span>
                                        </td>
                                        <td><?php echo esc_html($formatted_date); ?></td>
                                        <td class="ai-chat-actions">
                                            <button class="button ai-chat-admin-btn ai-chat-admin-btn--ghost view-chat"
                                                data-session="<?php echo esc_attr($chat->session_id); ?>"
                                                data-name="<?php echo esc_attr($user_name); ?>"
                                                data-email="<?php echo esc_attr($user_email); ?>"
                                                data-purpose="<?php echo esc_attr($purpose_label); ?>"
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
        if (isset($_POST['ai_chat_save_settings'])) {
            check_admin_referer('ai_chat_settings');
            
            $raw_gemini_key = sanitize_text_field($_POST['gemini_key']);
            $clean_gemini_key = trim(str_ireplace(array('Name', 'API Key'), '', $raw_gemini_key));

            update_option('ai_chat_api_provider', sanitize_text_field($_POST['api_provider']));
            update_option('ai_chat_openai_key', sanitize_text_field($_POST['openai_key']));
            update_option('ai_chat_gemini_key', $clean_gemini_key);
            update_option('ai_chat_model', sanitize_text_field($_POST['model']));
            update_option('ai_chat_gemini_model', sanitize_text_field($_POST['gemini_model']));
            update_option('ai_chat_welcome_message', sanitize_text_field($_POST['welcome_message']));
            
            // New Setting: Context Instruction
            update_option('ai_chat_instruction', sanitize_textarea_field($_POST['ai_instruction']));
            
            update_option('ai_chat_badge_title', sanitize_text_field($_POST['badge_title']));
            update_option('ai_chat_badge_subtitle', sanitize_text_field($_POST['badge_subtitle']));
            update_option('ai_chat_badge_icon', sanitize_text_field($_POST['badge_icon']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $provider = get_option('ai_chat_api_provider', 'openai');
        $openai_key = get_option('ai_chat_openai_key', '');
        $gemini_key = get_option('ai_chat_gemini_key', '');
        $model = get_option('ai_chat_model', 'gpt-4');
        $gemini_model = get_option('ai_chat_gemini_model', 'gemini-1.5-flash');
        $welcome = get_option('ai_chat_welcome_message', 'Hello! How can I help you today?');
        $instruction = get_option('ai_chat_instruction', '');
        
        $badge_title = get_option('ai_chat_badge_title', 'Welcome to AI Assistant');
        $badge_subtitle = get_option('ai_chat_badge_subtitle', 'How can we help you?');
        $badge_icon = get_option('ai_chat_badge_icon', '🤖');
        ?>
        <div class="wrap ai-chat-admin ai-chat-settings">
            <div class="ai-chat-admin-hero ai-chat-admin-hero--settings">
                <div>
                    <h1>AI Chat Settings</h1>
                    <p class="ai-chat-admin-subtitle">Configure AI behavior, providers, and widget text.</p>
                </div>
            </div>
            <form method="post">
                <?php wp_nonce_field('ai_chat_settings'); ?>

                <div class="ai-chat-card">
                    <div class="ai-chat-card-header">
                        <div>
                            <h2>AI Personality & Context</h2>
                            <p class="ai-chat-card-subtitle">Teach the assistant about your business and tone.</p>
                        </div>
                    </div>
                    <div class="ai-chat-card-body">
                        <table class="form-table ai-chat-form-table">
                            <tr>
                                <th>Website Context / Instructions</th>
                                <td>
                                    <textarea name="ai_instruction" rows="6" cols="50" class="large-text code" placeholder="E.g. You are a support agent for a Pizza Shop called Mario's. We are open 9am-10pm. Do not give medical advice."><?php echo esc_textarea($instruction); ?></textarea>
                                    <p class="description">Provide details about your business so the AI knows how to answer. (Shift+Enter for new lines)</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Welcome Message</th>
                                <td><input type="text" name="welcome_message" value="<?php echo esc_attr($welcome); ?>" class="regular-text"></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="ai-chat-card">
                    <div class="ai-chat-card-header">
                        <div>
                            <h2>API Configuration</h2>
                            <p class="ai-chat-card-subtitle">Select a provider and set your API keys.</p>
                        </div>
                    </div>
                    <div class="ai-chat-card-body">
                        <table class="form-table ai-chat-form-table">
                            <tr>
                                <th>API Provider</th>
                                <td>
                                    <select name="api_provider" id="api_provider">
                                        <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI (ChatGPT)</option>
                                        <option value="gemini" <?php selected($provider, 'gemini'); ?>>Google Gemini</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="openai-field">
                                <th>OpenAI API Key</th>
                                <td><input type="password" name="openai_key" value="<?php echo esc_attr($openai_key); ?>" class="regular-text"></td>
                            </tr>
                            <tr class="openai-field">
                                <th>OpenAI Model</th>
                                <td>
                                    <select name="model">
                                        <option value="gpt-4" <?php selected($model, 'gpt-4'); ?>>GPT-4</option>
                                        <option value="gpt-3.5-turbo" <?php selected($model, 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                                        <option value="gpt-4o" <?php selected($model, 'gpt-4o'); ?>>GPT-4o</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="gemini-field">
                                <th>Gemini API Key</th>
                                <td>
                                    <input type="password" name="gemini_key" value="<?php echo esc_attr($gemini_key); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr class="gemini-field">
                                <th>Gemini Model</th>
                                <td>
                                    <select name="gemini_model">
                                        <option value="gemini-2.0-flash" <?php selected($gemini_model, 'gemini-2.0-flash'); ?>>Gemini 2.0 Flash (Latest)</option>
                                        <option value="gemini-1.5-flash" <?php selected($gemini_model, 'gemini-1.5-flash'); ?>>Gemini 1.5 Flash</option>
                                        <option value="gemini-1.5-pro" <?php selected($gemini_model, 'gemini-1.5-pro'); ?>>Gemini 1.5 Pro</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="ai-chat-card">
                    <div class="ai-chat-card-header">
                        <div>
                            <h2>Widget Appearance</h2>
                            <p class="ai-chat-card-subtitle">Adjust the badge text and icon shown to visitors.</p>
                        </div>
                    </div>
                    <div class="ai-chat-card-body">
                        <table class="form-table ai-chat-form-table">
                            <tr><th>Badge Title</th><td><input type="text" name="badge_title" value="<?php echo esc_attr($badge_title); ?>" class="regular-text"></td></tr>
                            <tr><th>Badge Subtitle</th><td><input type="text" name="badge_subtitle" value="<?php echo esc_attr($badge_subtitle); ?>" class="regular-text"></td></tr>
                            <tr><th>Badge Icon</th><td><input type="text" name="badge_icon" value="<?php echo esc_attr($badge_icon); ?>" class="regular-text ai-chat-icon-input"></td></tr>
                        </table>
                    </div>
                </div>

                <div class="ai-chat-form-actions">
                    <input type="submit" name="ai_chat_save_settings" class="button button-primary ai-chat-primary-btn" value="Save Settings">
                </div>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            function toggle() {
                if($('#api_provider').val() === 'openai') { $('.openai-field').show(); $('.gemini-field').hide(); }
                else { $('.openai-field').hide(); $('.gemini-field').show(); }
            }
            toggle(); $('#api_provider').change(toggle);
        });
        </script>
        <?php
    }

    public function exact_replies_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $wpdb->prefix . 'ai_chat_exact_replies';

        $notice = '';
        $notice_type = 'success';

        if (isset($_GET['delete'])) {
            $delete_id = absint($_GET['delete']);
            if ($delete_id) {
                check_admin_referer('ai_chat_delete_reply_' . $delete_id);
                $wpdb->delete($table, array('id' => $delete_id));
                $notice = 'Exact reply deleted.';
            }
        }

        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
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
            $question = isset($_POST['ai_chat_question']) ? wp_unslash($_POST['ai_chat_question']) : '';
            $answer = isset($_POST['ai_chat_answer']) ? wp_unslash($_POST['ai_chat_answer']) : '';
            $question = trim(str_replace(array("\r\n", "\r"), "\n", $question));
            $answer = str_replace(array("\r\n", "\r"), "\n", $answer);
            $reply_id = isset($_POST['reply_id']) ? absint($_POST['reply_id']) : 0;

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
            $notice = 'Widget key regenerated.';
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
                            <button type="submit" name="ai_chat_regen_widget_key" class="button ai-chat-admin-btn ai-chat-admin-btn--ghost">Regenerate Key</button>
                        </form>
                    </div>
                    <p class="description">Use this exact script tag on any site (HTML, Shopify, Wix, etc.). Keep your key private.</p>
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
    }
    
    public function frontend_scripts() {
        if (is_admin()) return;
        wp_enqueue_style('ai-chat-style', AI_CHAT_PLUGIN_URL . 'style.css', array(), AI_CHAT_VERSION);
        wp_enqueue_script('ai-chat-script', AI_CHAT_PLUGIN_URL . 'script.js', array('jquery'), AI_CHAT_VERSION, true);
        wp_localize_script('ai-chat-script', 'aiChat', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_chat_nonce'),
            'welcome_message' => get_option('ai_chat_welcome_message', 'Hello! How can I help you today?')
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
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path></svg>
        </button>

        <div id="ai-chat-window" role="region" aria-label="AI chat" style="display:none;">
            <div class="ai-chat-header">
                <div class="ai-chat-title">
                    <span class="status-dot" aria-hidden="true"></span>
                    <div class="ai-chat-title-text">
                        <span class="ai-chat-title-name">AI Support</span>
                        <span class="ai-chat-title-status">Online</span>
                    </div>
                </div>
                <div class="ai-chat-controls">
                    <button type="button" class="ai-chat-control ai-chat-minimize" aria-label="Minimize chat">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                            <path d="M6 9l6 6 6-6"></path>
                        </svg>
                    </button>
                    <button type="button" class="ai-chat-control ai-chat-close-chat" aria-label="End chat">
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
                            <span class="ai-chat-field-label">Purpose</span>
                            <select id="chat-purpose" required>
                                <option value="">Select Topic</option>
                                <option value="Support">Support</option>
                                <option value="Sales">Sales</option>
                                <option value="General">General</option>
                            </select>
                        </label>
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
                <input type="text" id="ai-chat-input" placeholder="Type a message..." autocomplete="off" aria-label="Message">
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
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $purpose = sanitize_text_field($_POST['purpose']);
        $session_id = uniqid('chat_', true);
        $wpdb->insert($wpdb->prefix . 'ai_chats', array('session_id' => $session_id, 'user_name' => $name, 'user_email' => $email, 'purpose' => $purpose));
        wp_send_json_success(array('session_id' => $session_id));
    }

    // Load History (Fixes Disappearing Chat on Refresh)
    public function load_chat_history() {
        check_ajax_referer('ai_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id']);
        if(!$session_id) wp_send_json_error('No ID');

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ai_chats WHERE session_id = %s", $session_id));
        if(!$exists) { wp_send_json_error('Invalid Session'); return; }

        $messages = $wpdb->get_results($wpdb->prepare("SELECT role, message, created_at FROM {$wpdb->prefix}ai_chat_messages WHERE session_id = %s ORDER BY created_at ASC", $session_id));
        wp_send_json_success($messages);
    }
    
    // Send Message
    public function send_message() {
        check_ajax_referer('ai_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id']);
        // Use textarea field to allow newlines
        $message = sanitize_textarea_field($_POST['message']);
        $ai_response = $this->process_message($session_id, $message);
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
    
    private function get_openai_response($session_id) {
        $api_key = get_option('ai_chat_openai_key');
        if (empty($api_key)) return 'Error: OpenAI API key not configured.';
        
        global $wpdb;
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT role, message FROM {$wpdb->prefix}ai_chat_messages WHERE session_id = %s ORDER BY created_at DESC LIMIT 10",
            $session_id
        ));
        $history = array_reverse($history);
        
        // --- CUSTOM CONTEXT LOGIC ---
        $custom_instruction = get_option('ai_chat_instruction', '');
        $system_content = "You are a helpful assistant. " . $custom_instruction;
        
        $messages = array(array(
            'role' => 'system',
            'content' => $system_content
        ));
        foreach ($history as $msg) {
            $messages[] = array('role' => $msg->role, 'content' => $msg->message);
        }
        
        $user_model = get_option('ai_chat_model', 'gpt-4');
        $model_chain = array_values(array_unique(array($user_model, 'gpt-4o', 'gpt-3.5-turbo')));
        $last_error = 'Error processing response.';
        
        foreach ($model_chain as $model) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
                'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type'  => 'application/json'),
                'body' => json_encode(array('model' => $model, 'messages' => $messages, 'max_tokens' => 500)),
                'timeout' => 45
            ));
            
            if (is_wp_error($response)) { $last_error = 'Connection Error: ' . $response->get_error_message(); break; }
            $status = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['choices'][0]['message']['content'])) return $body['choices'][0]['message']['content'];
            if ($status >= 400) { if ($status === 404) continue; break; }
        }
        return $last_error;
    }
    
    private function get_gemini_response($session_id) {
        $api_key = get_option('ai_chat_gemini_key');
        if (empty($api_key)) return 'Error: Gemini API key not configured.';
        $user_model = get_option('ai_chat_gemini_model', 'gemini-1.5-flash');
        
        global $wpdb;
        $history = $wpdb->get_results($wpdb->prepare("SELECT role, message FROM {$wpdb->prefix}ai_chat_messages WHERE session_id = %s ORDER BY created_at ASC", $session_id));
        
        $contents = array();
        
        // Strict Role Alternation (User -> Model -> User)
        foreach ($history as $msg) {
            $role = ($msg->role === 'user') ? 'user' : 'model';
            $text = trim($msg->message);
            if (empty($text)) continue;

            if (empty($contents)) {
                $contents[] = array('role' => $role, 'parts' => array(array('text' => $text)));
            } else {
                $last_idx = count($contents) - 1;
                if ($contents[$last_idx]['role'] === $role) {
                    $contents[$last_idx]['parts'][0]['text'] .= "\n\n" . $text;
                } else {
                    $contents[] = array('role' => $role, 'parts' => array(array('text' => $text)));
                }
            }
        }
        if (!empty($contents) && $contents[0]['role'] === 'model') array_shift($contents);
        if (empty($contents)) $contents[] = array('role' => 'user', 'parts' => array(array('text' => 'Hello')));
        
        $candidates = array_values(array_unique(array($user_model, 'gemini-2.0-flash', 'gemini-1.5-flash')));
        $last_error = 'Unknown error';
        
        foreach ($candidates as $model) {
            $response = $this->make_gemini_request($model, $contents, $api_key);
            if (strpos($response, 'API Error') !== 0 && strpos($response, 'Connection Error') !== 0) return $response;
            $last_error = $response;
        }
        return $last_error;
    }

    private function make_gemini_request($model, $contents, $api_key) {
        // --- CUSTOM CONTEXT LOGIC ---
        $custom_instruction = get_option('ai_chat_instruction', '');
        $system_prompt = "You are a helpful assistant. " . $custom_instruction . ". Be polite.";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $api_key;
        $payload = array(
            'contents' => $contents,
            'systemInstruction' => array('parts' => array(array('text' => $system_prompt))),
            'safetySettings' => array(
                array('category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'),
                array('category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'),
                array('category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'),
                array('category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH')
            )
        );
        
        $response = wp_remote_post($url, array('headers' => array('Content-Type' => 'application/json'), 'body' => json_encode($payload), 'timeout' => 60));
        if (is_wp_error($response)) return 'Connection Error: ' . $response->get_error_message();
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) return $body['candidates'][0]['content']['parts'][0]['text'];
        $err = $body['error']['message'] ?? 'Unknown';
        return 'API Error: ' . $err;
    }
    
    public function get_messages() {
        if (!current_user_can('manage_options')) { wp_send_json_error('Unauthorized'); return; }
        check_ajax_referer('ai_chat_admin_nonce', 'nonce');
        global $wpdb;
        $messages = $wpdb->get_results($wpdb->prepare("SELECT role, message, created_at FROM {$wpdb->prefix}ai_chat_messages WHERE session_id = %s ORDER BY created_at ASC", $_POST['session_id']));
        wp_send_json_success($messages);
    }
    
    public function delete_chat() {
        if (!current_user_can('manage_options')) { wp_send_json_error('Unauthorized'); return; }
        check_ajax_referer('ai_chat_admin_nonce', 'nonce');
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'ai_chats', array('session_id' => $_POST['session_id']));
        $wpdb->delete($wpdb->prefix . 'ai_chat_messages', array('session_id' => $_POST['session_id']));
        wp_send_json_success();
    }
}
new AI_Chat_Plugin();
