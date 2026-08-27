<?php
/**
 * Plugin Name: Spacefast Frames
 * Plugin URI: https://github.com/spacefast/frames
 * Description: Embed authenticated Spacefast pages with native WordPress blocks.
 * Version: 0.0.27
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Spacefast
 * License: MIT
 * Text Domain: spacefast-frames
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SPACEFAST_FRAMES_VERSION', '0.0.27');
define('SPACEFAST_FRAMES_FILE', __FILE__);
define('SPACEFAST_FRAMES_DIR', plugin_dir_path(__FILE__));

require_once SPACEFAST_FRAMES_DIR . 'includes/class-spacefast-frames-api.php';
require_once SPACEFAST_FRAMES_DIR . 'includes/class-spacefast-frames-plugin.php';

Spacefast_Frames_Plugin::boot();
