<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class AI_Chat_Provider_Base implements AI_Chat_Provider_Interface {
    protected function request($url, array $args) {
        $defaults = array(
            'timeout' => 45,
            'redirection' => 2,
            'sslverify' => true,
            'headers' => array('Content-Type' => 'application/json'),
            'user-agent' => 'AI-Chat-Support/' . (defined('AI_CHAT_VERSION') ? AI_CHAT_VERSION : '2.0.0') . '; ' . home_url('/'),
        );
        $args = wp_parse_args($args, $defaults);
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('ai_chat_connection_error', 'The provider could not be reached. Check the server connection and try again.');
        }
        return $response;
    }

    protected function decode_response($response) {
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    protected function provider_error($provider, $status, array $body = array()) {
        $provider_message = '';
        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            $provider_message = sanitize_text_field($body['error']['message']);
        } elseif (isset($body['message']) && is_string($body['message'])) {
            $provider_message = sanitize_text_field($body['message']);
        }

        switch ((int) $status) {
            case 400:
                $message = 'The provider rejected the request. Check the selected model and configuration.';
                break;
            case 401:
            case 403:
                $message = 'Authentication failed. Check the API key and its permissions.';
                break;
            case 404:
                $message = 'The selected model is unavailable for this API key or account.';
                break;
            case 408:
            case 504:
                $message = 'The provider timed out. Please try again.';
                break;
            case 429:
                $message = 'The provider rate limit or account quota was reached.';
                break;
            default:
                $message = $provider . ' returned an unexpected error.';
        }

        // Provider messages can help administrators, but are sanitized and never include request headers or API keys.
        if ($provider_message !== '') {
            $provider_message = preg_replace('/\b(sk-[A-Za-z0-9_-]{12,}|AIza[A-Za-z0-9_-]{20,})\b/', '[redacted]', $provider_message);
            $provider_message = substr($provider_message, 0, 240);
            if ($provider_message !== '') {
                $message .= ' ' . $provider_message;
            }
        }

        return new WP_Error('ai_chat_provider_error', $message, array('status' => (int) $status));
    }

    protected function normalize_model($model) {
        $model = preg_replace('/[^a-zA-Z0-9._:\-]/', '', (string) $model);
        return substr($model, 0, 120);
    }

    protected function model_record($id, $name, $description, $capability = 'Text generation', $status = 'stable') {
        return array(
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'capability' => $capability,
            'status' => $status,
        );
    }
}
