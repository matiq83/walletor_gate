<?php

/*
  Plugin Name: GatePay Integration
  Description: GatePay Integration
  Version: 1.0.0
  Author: Muhammad Atiq
 */

// Make sure we don't expose any info if called directly
if (!function_exists('add_action')) {
    echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
    exit;
}

//ini_set('display_errors', 1);ini_set('display_startup_errors', 1);error_reporting(E_ALL);
//Global define variables
define('WALLETOR_GATE_PLUGIN_NAME', 'GatePay');
define('WALLETOR_GATE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WALLETOR_GATE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WALLETOR_GATE_SLUG', plugin_basename(__DIR__));
define('WALLETOR_GATE_SITE_BASE_URL', rtrim(get_bloginfo('url'), "/") . "/");
define('WALLETOR_GATE_LANG_DIR', WALLETOR_GATE_PLUGIN_PATH . 'language/');
define('WALLETOR_GATE_VIEWS_DIR', WALLETOR_GATE_PLUGIN_PATH . 'views/');
define('WALLETOR_GATE_ASSETS_DIR_URL', WALLETOR_GATE_PLUGIN_URL . 'assets/');
define('WALLETOR_GATE_ASSETS_DIR_PATH', WALLETOR_GATE_PLUGIN_PATH . 'assets/');
define('WALLETOR_GATE_SETTINGS_KEY', '_walletor_gate_options');
define('WALLETOR_GATE_TEXT_DOMAIN', 'walletor_gate');

//Plugin update checker
require WALLETOR_GATE_PLUGIN_PATH . 'update/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
                WALLETOR_GATE_UPDATE_URL . WALLETOR_GATE_SLUG . '.json',
                __FILE__,
                WALLETOR_GATE_SLUG
);

//Load the classes
require_once WALLETOR_GATE_PLUGIN_PATH . '/inc/helpers/autoloader.php';

//Get main class instance
$main = WALLETOR_GATE\Inc\Main::get_instance();

//Plugin activation hook
register_activation_hook(__FILE__, [$main, 'walletor_gate_install']);

//Plugin deactivation hook
register_deactivation_hook(__FILE__, [$main, 'walletor_gate_uninstall']);
