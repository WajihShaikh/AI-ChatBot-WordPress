<?php
if (!defined('ABSPATH')) {
    exit;
}

class AI_Chat_Provider_Gemini extends AI_Chat_Provider_Base {
    public function get_id() {
        return 'gemini';
    }

    public function get_label() {
        return 'Google Gemini';
    }

    public function get_default_model() {
        return 'gemini-3.6-flash';
    }

    public function get_fallback_models() {
        return array(
            $this->model_record('gemini-3.6-flash', 'Gemini 3.6 Flash', 'Latest stable Gemini model balancing speed and intelligence.', 'Agentic, multimodal, and text generation', 'recommended'),
            $this->model_record('gemini-3.5-flash', 'Gemini 3.5 Flash', 'Stable model for sustained reasoning, coding, and support tasks.', 'Reasoning and text generation', 'stable'),
            $this->model_record('gemini-3.5-flash-lite', 'Gemini 3.5 Flash-Lite', 'Fast, cost-effective stable model for high-throughput conversations.', 'Fast text generation', 'recommended'),
            $this->model_record('gemini-3.1-flash-lite', 'Gemini 3.1 Flash-Lite', 'Stable, cost-efficient model for lightweight requests.', 'Fast text generation', 'stable'),
            $this->model_record('gemini-3.1-pro-preview', 'Gemini 3.1 Pro Preview', 'Preview model for advanced intelligence and complex problem solving.', 'Advanced reasoning and text generation', 'preview'),
            $this->model_record('gemini-flash-latest', 'Gemini Flash Latest', 'Moving alias for the newest Gemini Flash release.', 'Text generation', 'alias'),
            $this->model_record('gemini-flash-lite-latest', 'Gemini Flash-Lite Latest', 'Moving alias for the newest Flash-Lite release.', 'Fast text generation', 'alias'),
            $this->model_record('gemini-pro-latest', 'Gemini Pro Latest', 'Moving alias for the newest Gemini Pro release.', 'Advanced reasoning and text generation', 'alias'),
        );
    }

    public function list_models($api_key) {
        $api_key = trim((string) $api_key);
        if ($api_key === '') {
            return new WP_Error('ai_chat_missing_key', 'Enter or save a Gemini API key first.');
        }

        $response = $this->request('https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', array(
            'method' => 'GET',
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key,
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = $this->decode_response($response);
        if ($status < 200 || $status >= 300) {
            return $this->provider_error('Google Gemini', $status, $body);
        }

        $metadata = array();
        foreach ($this->get_fallback_models() as $record) {
            $metadata[$record['id']] = $record;
        }

        $models = array();
        if (!empty($body['models']) && is_array($body['models'])) {
            foreach ($body['models'] as $item) {
                $id = isset($item['name']) ? preg_replace('#^models/#', '', (string) $item['name']) : '';
                $id = $this->normalize_model($id);
                $methods = isset($item['supportedGenerationMethods']) && is_array($item['supportedGenerationMethods']) ? $item['supportedGenerationMethods'] : array();
                if (!$this->is_supported_text_model($id, $methods)) {
                    continue;
                }
                if (isset($metadata[$id])) {
                    $models[$id] = $metadata[$id];
                } else {
                    $display_name = isset($item['displayName']) ? sanitize_text_field($item['displayName']) : $this->humanize_model($id);
                    $description = isset($item['description']) ? sanitize_text_field($item['description']) : 'Available to this Gemini API key.';
                    $models[$id] = $this->model_record($id, $display_name, $description, 'Text generation', 'account');
                }
            }
        }

        foreach ($metadata as $id => $record) {
            if (!isset($models[$id])) {
                $models[$id] = $record;
            }
        }

        $preferred_order = array_column($this->get_fallback_models(), 'id');
        uasort($models, function ($a, $b) use ($preferred_order) {
            $a_index = array_search($a['id'], $preferred_order, true);
            $b_index = array_search($b['id'], $preferred_order, true);
            $a_index = $a_index === false ? 999 : $a_index;
            $b_index = $b_index === false ? 999 : $b_index;
            if ($a_index === $b_index) {
                return strnatcasecmp($a['name'], $b['name']);
            }
            return $a_index <=> $b_index;
        });

        return array_values($models);
    }

    public function test_connection($api_key, $model) {
        $result = $this->generate($api_key, $model, array(
            array('role' => 'user', 'message' => 'Reply with exactly OK.'),
        ), 'This is a connection test. Follow the user instruction exactly.', array('max_output_tokens' => 128));

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'message' => 'Connection successful. Google Gemini API is configured correctly.',
            'response' => trim(wp_strip_all_tags($result)),
        );
    }

    public function generate($api_key, $model, array $history, $instructions, array $args = array()) {
        $api_key = trim((string) $api_key);
        $model = $this->normalize_model($model);
        if ($api_key === '') {
            return new WP_Error('ai_chat_missing_key', 'Gemini API key is not configured.');
        }
        if ($model === '') {
            return new WP_Error('ai_chat_missing_model', 'Select a Gemini model.');
        }

        $contents = array();
        foreach ($history as $item) {
            $role = isset($item['role']) && $item['role'] === 'assistant' ? 'model' : 'user';
            $message = isset($item['message']) ? trim((string) $item['message']) : '';
            if ($message === '') {
                continue;
            }
            $contents[] = array(
                'role' => $role,
                'parts' => array(
                    array('text' => $message),
                ),
            );
        }
        if (empty($contents)) {
            return new WP_Error('ai_chat_empty_prompt', 'No message was provided to Gemini.');
        }

        $payload = array(
            'contents' => $contents,
            'generationConfig' => array(
                'maxOutputTokens' => isset($args['max_output_tokens']) ? max(16, min(2000, absint($args['max_output_tokens']))) : 500,
            ),
            'store' => false,
        );
        $instructions = trim((string) $instructions);
        if ($instructions !== '') {
            $payload['systemInstruction'] = array(
                'parts' => array(
                    array('text' => $instructions),
                ),
            );
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );
        $response = $this->request($url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key,
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = $this->decode_response($response);
        if ($status < 200 || $status >= 300) {
            return $this->provider_error('Google Gemini', $status, $body);
        }

        $text = $this->extract_text($body);
        if ($text === '') {
            $finish_reason = isset($body['candidates'][0]['finishReason']) ? sanitize_text_field($body['candidates'][0]['finishReason']) : '';
            if ($finish_reason !== '') {
                return new WP_Error('ai_chat_empty_response', 'Google Gemini returned no text. Finish reason: ' . $finish_reason . '.');
            }
            return new WP_Error('ai_chat_empty_response', 'Google Gemini returned an empty response.');
        }
        return $text;
    }

    private function extract_text(array $body) {
        $parts = array();
        if (!empty($body['candidates']) && is_array($body['candidates'])) {
            foreach ($body['candidates'] as $candidate) {
                if (empty($candidate['content']['parts']) || !is_array($candidate['content']['parts'])) {
                    continue;
                }
                foreach ($candidate['content']['parts'] as $part) {
                    if (isset($part['text']) && is_string($part['text'])) {
                        $parts[] = $part['text'];
                    }
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    private function is_supported_text_model($id, array $methods) {
        if ($id === '' || strpos($id, 'gemini-') !== 0) {
            return false;
        }
        $blocked = array('image', 'live', 'audio', 'tts', 'embedding', 'robotics', 'computer-use', 'deep-research');
        foreach ($blocked as $fragment) {
            if (strpos($id, $fragment) !== false) {
                return false;
            }
        }
        if (!empty($methods) && !in_array('generateContent', $methods, true)) {
            return false;
        }
        return true;
    }

    private function humanize_model($id) {
        return ucwords(str_replace(array('-', '_'), ' ', $id));
    }
}
