<?php
if (!defined('ABSPATH')) {
    exit;
}

class AI_Chat_Provider_Manager {
    private $providers = array();

    public function __construct() {
        $this->register(new AI_Chat_Provider_OpenAI());
        $this->register(new AI_Chat_Provider_Gemini());
        $this->providers = apply_filters('ai_chat_providers', $this->providers);
    }

    public function register(AI_Chat_Provider_Interface $provider) {
        $this->providers[$provider->get_id()] = $provider;
    }

    public function get($provider_id) {
        $provider_id = sanitize_key($provider_id);
        return isset($this->providers[$provider_id]) ? $this->providers[$provider_id] : null;
    }

    public function all() {
        return $this->providers;
    }

    public function model_option_name($provider_id) {
        return 'ai_chat_model_' . sanitize_key($provider_id);
    }

    public function models_cache_option_name($provider_id) {
        return 'ai_chat_models_cache_' . sanitize_key($provider_id);
    }

    public function get_selected_model($provider_id) {
        $provider = $this->get($provider_id);
        if (!$provider) {
            return '';
        }
        $legacy = $provider_id === 'openai' ? get_option('ai_chat_model', '') : get_option('ai_chat_gemini_model', '');
        $selected = get_option($this->model_option_name($provider_id), $legacy);
        return $selected ? $selected : $provider->get_default_model();
    }

    public function save_selected_model($provider_id, $model) {
        $model = preg_replace('/[^a-zA-Z0-9._:\-]/', '', (string) $model);
        $model = substr($model, 0, 120);
        return update_option($this->model_option_name($provider_id), $model, false);
    }

    public function get_models($provider_id) {
        $provider = $this->get($provider_id);
        if (!$provider) {
            return array();
        }
        $cache = get_option($this->models_cache_option_name($provider_id), array());
        if (is_array($cache) && !empty($cache['models']) && is_array($cache['models'])) {
            return $cache['models'];
        }
        return $provider->get_fallback_models();
    }

    public function refresh_models($provider_id, $api_key) {
        $provider = $this->get($provider_id);
        if (!$provider) {
            return new WP_Error('ai_chat_invalid_provider', 'The selected AI provider is not supported.');
        }
        $models = $provider->list_models($api_key);
        if (is_wp_error($models)) {
            return $models;
        }
        update_option($this->models_cache_option_name($provider_id), array(
            'models' => $models,
            'updated_at' => time(),
        ), false);
        return $models;
    }

    public function get_models_updated_at($provider_id) {
        $cache = get_option($this->models_cache_option_name($provider_id), array());
        return !empty($cache['updated_at']) ? absint($cache['updated_at']) : 0;
    }
}
