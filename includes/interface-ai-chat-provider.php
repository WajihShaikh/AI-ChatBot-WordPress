<?php
if (!defined('ABSPATH')) {
    exit;
}

interface AI_Chat_Provider_Interface {
    public function get_id();
    public function get_label();
    public function get_default_model();
    public function get_fallback_models();
    public function list_models($api_key);
    public function test_connection($api_key, $model);
    public function generate($api_key, $model, array $history, $instructions, array $args = array());
}
