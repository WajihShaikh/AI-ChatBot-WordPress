<?php
if (!defined('ABSPATH')) {
    exit;
}

class AI_Chat_Provider_OpenAI extends AI_Chat_Provider_Base {
    public function get_id() {
        return 'openai';
    }

    public function get_label() {
        return 'OpenAI';
    }

    public function get_default_model() {
        return 'gpt-5.6-luna';
    }

    public function get_fallback_models() {
        return array(
            $this->model_record('gpt-5.6', 'GPT-5.6 Sol', 'Flagship model for complex reasoning and professional work.', 'Advanced reasoning and text generation', 'recommended'),
            $this->model_record('gpt-5.6-terra', 'GPT-5.6 Terra', 'Balances strong intelligence with lower cost than the flagship tier.', 'Reasoning and text generation', 'stable'),
            $this->model_record('gpt-5.6-luna', 'GPT-5.6 Luna', 'Fast, cost-efficient choice for high-volume support conversations.', 'Fast text generation', 'recommended'),
            $this->model_record('gpt-5.5', 'GPT-5.5', 'Strong general-purpose model for coding and professional tasks.', 'Reasoning and text generation', 'stable'),
            $this->model_record('gpt-5.4', 'GPT-5.4', 'General-purpose frontier model for complex professional work.', 'Reasoning and text generation', 'stable'),
            $this->model_record('gpt-5.4-mini', 'GPT-5.4 mini', 'Efficient model for high-volume workflows and support use cases.', 'Fast text generation', 'stable'),
            $this->model_record('gpt-5.4-nano', 'GPT-5.4 nano', 'Lowest-cost GPT-5.4-class model for simple, high-volume requests.', 'Fast text generation', 'stable'),
            $this->model_record('gpt-4.1', 'GPT-4.1', 'Reliable non-reasoning model for instruction following and general text tasks.', 'Text generation', 'legacy'),
            $this->model_record('gpt-4.1-mini', 'GPT-4.1 mini', 'Smaller, faster GPT-4.1 option.', 'Fast text generation', 'legacy'),
            $this->model_record('gpt-4o-mini', 'GPT-4o mini', 'Affordable older model for focused tasks.', 'Fast text generation', 'legacy'),
        );
    }

    public function list_models($api_key) {
        $api_key = trim((string) $api_key);
        if ($api_key === '') {
            return new WP_Error('ai_chat_missing_key', 'Enter or save an OpenAI API key first.');
        }

        $response = $this->request('https://api.openai.com/v1/models', array(
            'method' => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = $this->decode_response($response);
        if ($status < 200 || $status >= 300) {
            return $this->provider_error('OpenAI', $status, $body);
        }

        $metadata = array();
        foreach ($this->get_fallback_models() as $record) {
            $metadata[$record['id']] = $record;
        }

        $models = array();
        if (!empty($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $item) {
                $id = isset($item['id']) ? $this->normalize_model($item['id']) : '';
                if (!$this->is_supported_text_model($id)) {
                    continue;
                }
                if (isset($metadata[$id])) {
                    $models[$id] = $metadata[$id];
                } else {
                    $models[$id] = $this->model_record($id, $this->humanize_model($id), 'Available to this OpenAI project.', 'Text generation', 'account');
                }
            }
        }

        // Keep the curated catalog visible even when the account model endpoint omits aliases.
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
            'message' => 'Connection successful. OpenAI API is configured correctly.',
            'response' => trim(wp_strip_all_tags($result)),
        );
    }

    public function generate($api_key, $model, array $history, $instructions, array $args = array()) {
        $api_key = trim((string) $api_key);
        $model = $this->normalize_model($model);
        if ($api_key === '') {
            return new WP_Error('ai_chat_missing_key', 'OpenAI API key is not configured.');
        }
        if ($model === '') {
            return new WP_Error('ai_chat_missing_model', 'Select an OpenAI model.');
        }

        $input = array();
        foreach ($history as $item) {
            $role = isset($item['role']) && $item['role'] === 'assistant' ? 'assistant' : 'user';
            $message = isset($item['message']) ? trim((string) $item['message']) : '';
            if ($message === '') {
                continue;
            }
            $input[] = array(
                'role' => $role,
                'content' => $message,
            );
        }

        if (empty($input)) {
            return new WP_Error('ai_chat_empty_prompt', 'No message was provided to OpenAI.');
        }

        $payload = array(
            'model' => $model,
            'input' => $input,
            'instructions' => trim((string) $instructions),
            'max_output_tokens' => isset($args['max_output_tokens']) ? max(16, min(2000, absint($args['max_output_tokens']))) : 500,
            'store' => false,
        );

        $response = $this->request('https://api.openai.com/v1/responses', array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = $this->decode_response($response);
        if ($status < 200 || $status >= 300) {
            return $this->provider_error('OpenAI', $status, $body);
        }

        $text = $this->extract_text($body);
        if ($text === '') {
            return new WP_Error('ai_chat_empty_response', 'OpenAI returned an empty response.');
        }
        return $text;
    }

    private function extract_text(array $body) {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return trim($body['output_text']);
        }
        $parts = array();
        if (!empty($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $output) {
                if (empty($output['content']) || !is_array($output['content'])) {
                    continue;
                }
                foreach ($output['content'] as $content) {
                    if (isset($content['text']) && is_string($content['text'])) {
                        $parts[] = $content['text'];
                    }
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    private function is_supported_text_model($id) {
        if ($id === '') {
            return false;
        }
        if (!preg_match('/^(gpt-(5|4\.1|4o))/', $id)) {
            return false;
        }
        $blocked = array('audio', 'realtime', 'transcribe', 'tts', 'image', 'search', 'moderation', 'embedding', 'whisper', 'sora', 'codex', 'chat');
        foreach ($blocked as $fragment) {
            if (strpos($id, $fragment) !== false) {
                return false;
            }
        }
        return true;
    }

    private function humanize_model($id) {
        return strtoupper(str_replace(array('-', '_'), ' ', $id));
    }
}
