<?php
/**
 * Plugin Name: AI Chat Support
 * Plugin URI: https://example.com
 * Description: AI-powered chatbot with Custom Context, History Fix, and Smooth Scroll.
 * Version: 1.1.1
 * Author: Wajih Shaikh
 * Author URI: https://goaccelovate.com
 * Company: GoAccelovate
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

define('AI_CHAT_VERSION', '1.1.1');
define('AI_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

class AI_Chat_Plugin {
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        
        // Defaults
        add_option('ai_chat_api_provider', 'gemini');
        add_option('ai_chat_model', 'gpt-4');
        add_option('ai_chat_gemini_model', 'gemini-1.5-flash');
        add_option('ai_chat_welcome_message', 'Hello! How can I help you today?');
    }
    
    public function deactivate() {}
    
    public function add_admin_menu() {
        add_menu_page('AI Chats', 'AI Chats', 'manage_options', 'ai-chats', array($this, 'admin_page'), 'dashicons-format-chat', 30);
        add_submenu_page('ai-chats', 'Settings', 'Settings', 'manage_options', 'ai-chats-settings', array($this, 'settings_page'));
    }
    
    public function admin_page() {
        global $wpdb;
        $chats = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ai_chats ORDER BY created_at DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>AI Chat History</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Purpose</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($chats): foreach ($chats as $chat): ?>
                    <tr>
                        <td><?php echo $chat->id; ?></td>
                        <td><?php echo esc_html($chat->user_name); ?></td>
                        <td><?php echo esc_html($chat->user_email); ?></td>
                        <td><?php echo esc_html($chat->purpose); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($chat->created_at)); ?></td>
                        <td>
                            <button class="button view-chat" data-session="<?php echo esc_attr($chat->session_id); ?>">View</button>
                            <button class="button delete-chat" data-session="<?php echo esc_attr($chat->session_id); ?>">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6">No chats found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="chat-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
            <div style="position:relative; width:80%; max-width:800px; margin:50px auto; background:#fff; padding:20px; border-radius:8px; max-height:80vh; overflow-y:auto;">
                <span id="close-modal" style="position:absolute; top:10px; right:20px; font-size:28px; cursor:pointer;">&times;</span>
                <h2>Chat Conversation</h2>
                <div id="chat-messages-content"></div>
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
        <div class="wrap">
            <h1>AI Chat Settings</h1>
            <form method="post">
                <?php wp_nonce_field('ai_chat_settings'); ?>
                
                <h2>AI Personality & Context</h2>
                <table class="form-table">
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

                <h2>API Configuration</h2>
                <table class="form-table">
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
                
                <h2>Widget Appearance</h2>
                <table class="form-table">
                    <tr><th>Badge Title</th><td><input type="text" name="badge_title" value="<?php echo esc_attr($badge_title); ?>" class="regular-text"></td></tr>
                    <tr><th>Badge Subtitle</th><td><input type="text" name="badge_subtitle" value="<?php echo esc_attr($badge_subtitle); ?>" class="regular-text"></td></tr>
                    <tr><th>Badge Icon</th><td><input type="text" name="badge_icon" value="<?php echo esc_attr($badge_icon); ?>" style="width:100px;"></td></tr>
                </table>
                <p class="submit"><input type="submit" name="ai_chat_save_settings" class="button button-primary" value="Save Settings"></p>
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

    public function admin_scripts($hook) {
        if ($hook !== 'toplevel_page_ai-chats') return;
        wp_enqueue_script('ai-chat-admin', AI_CHAT_PLUGIN_URL . 'admin.js', array('jquery'), AI_CHAT_VERSION, true);
        wp_localize_script('ai-chat-admin', 'aiChatAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_chat_admin_nonce')
        ));
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

        <div id="ai-chat-modal" role="dialog" aria-modal="true" aria-labelledby="ai-chat-modal-title" style="display:none;">
            <div class="ai-chat-modal-content">
                <button type="button" class="ai-chat-close" aria-label="Close">&times;</button>
                <h2 id="ai-chat-modal-title">Start Chat</h2>
                <p>Please provide your details.</p>
                <form id="ai-chat-user-form">
                    <input type="text" id="chat-name" placeholder="Name" required>
                    <input type="email" id="chat-email" placeholder="Email" required>
                    <select id="chat-purpose" required>
                        <option value="">Select Topic</option>
                        <option value="Support">Support</option>
                        <option value="Sales">Sales</option>
                        <option value="General">General</option>
                    </select>
                    <button type="submit">Start Chat</button>
                </form>
            </div>
        </div>

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
        global $wpdb;
        $session_id = sanitize_text_field($_POST['session_id']);
        // Use textarea field to allow newlines
        $message = sanitize_textarea_field($_POST['message']);
        
        $wpdb->insert($wpdb->prefix . 'ai_chat_messages', array('session_id' => $session_id, 'role' => 'user', 'message' => $message));
        
        $provider = get_option('ai_chat_api_provider', 'openai');
        $ai_response = ($provider === 'openai') ? $this->get_openai_response($session_id) : $this->get_gemini_response($session_id);
        
        $wpdb->insert($wpdb->prefix . 'ai_chat_messages', array('session_id' => $session_id, 'role' => 'assistant', 'message' => $ai_response));
        wp_send_json_success(array('response' => $ai_response));
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
