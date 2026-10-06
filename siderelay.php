<?php

/**
 * Plugin Name: SideRelay for WooCommerce
 * Description: Send confirmed purchases to SideRelay using your website’s saved privacy choices.
 * Version: 0.1.0-rc.1
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * License: MIT
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Plugin.php';
(new \SideRelay\WooCommerce\Plugin())->register();
