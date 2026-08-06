<?php
if (!defined('ABSPATH')) {
    exit;
}

class AI_Chat_Key_Store {
    const PREFIX_SODIUM = 'aic:sodium:v1:';
    const PREFIX_OPENSSL = 'aic:openssl:v1:';
    const PREFIX_PLAIN = 'aic:plain:v1:';

    public function save($provider, $api_key) {
        $provider = sanitize_key($provider);
        $api_key = trim((string) $api_key);
        if ($provider === '' || $api_key === '') {
            return false;
        }

        $encrypted = $this->encrypt($api_key);
        return update_option($this->option_name($provider), $encrypted, false);
    }

    public function get($provider) {
        $provider = sanitize_key($provider);
        if ($provider === '') {
            return '';
        }

        $value = get_option($this->option_name($provider), '');
        if ($value === '') {
            // Backward-compatible migration from version 1.x options.
            $legacy_name = $provider === 'openai' ? 'ai_chat_openai_key' : 'ai_chat_gemini_key';
            $legacy = trim((string) get_option($legacy_name, ''));
            if ($legacy !== '') {
                $saved = $this->save($provider, $legacy);
                if ($saved || get_option($this->option_name($provider), '') !== '') {
                    delete_option($legacy_name);
                }
                return $legacy;
            }
            return '';
        }

        return $this->decrypt($value);
    }

    public function delete($provider) {
        return delete_option($this->option_name($provider));
    }

    public function has($provider) {
        return $this->get($provider) !== '';
    }

    public function masked($provider) {
        $key = $this->get($provider);
        if ($key === '') {
            return '';
        }
        $suffix = substr($key, -4);
        return '••••••••' . $suffix;
    }

    public function encryption_method() {
        if (function_exists('sodium_crypto_secretbox')) {
            return 'Sodium authenticated encryption';
        }
        if (function_exists('openssl_encrypt')) {
            return 'OpenSSL AES-256-GCM encryption';
        }
        return 'Base64 compatibility fallback (not encryption)';
    }

    public function is_strong_encryption_available() {
        return function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt');
    }

    private function option_name($provider) {
        return 'ai_chat_api_key_' . $provider;
    }

    private function encryption_key() {
        $material = wp_salt('auth') . '|' . wp_salt('secure_auth');
        return hash('sha256', $material, true);
    }

    private function encrypt($plaintext) {
        $key = $this->encryption_key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return self::PREFIX_SODIUM . base64_encode($nonce . $ciphertext);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ciphertext !== false) {
                return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $ciphertext);
            }
        }

        // This fallback avoids breaking sites without crypto extensions. The admin UI clearly reports it.
        return self::PREFIX_PLAIN . base64_encode($plaintext);
    }

    private function decrypt($stored) {
        $stored = (string) $stored;
        $key = $this->encryption_key();

        if (strpos($stored, self::PREFIX_SODIUM) === 0) {
            $decoded = base64_decode(substr($stored, strlen(self::PREFIX_SODIUM)), true);
            if ($decoded === false || !function_exists('sodium_crypto_secretbox_open')) {
                return '';
            }
            $nonce_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $nonce = substr($decoded, 0, $nonce_length);
            $ciphertext = substr($decoded, $nonce_length);
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            return $plaintext === false ? '' : $plaintext;
        }

        if (strpos($stored, self::PREFIX_OPENSSL) === 0) {
            $decoded = base64_decode(substr($stored, strlen(self::PREFIX_OPENSSL)), true);
            if ($decoded === false || !function_exists('openssl_decrypt') || strlen($decoded) < 28) {
                return '';
            }
            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $ciphertext = substr($decoded, 28);
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plaintext === false ? '' : $plaintext;
        }

        if (strpos($stored, self::PREFIX_PLAIN) === 0) {
            $decoded = base64_decode(substr($stored, strlen(self::PREFIX_PLAIN)), true);
            return $decoded === false ? '' : $decoded;
        }

        // Allows a safe, one-time migration if a value was stored without a prefix.
        return $stored;
    }
}
