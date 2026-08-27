<?php
/** Dynamic render shim. The callback is registered by the plugin bootstrap. */
if (!defined('ABSPATH')) {
    exit;
}

echo Spacefast_Frames_Plugin::render_block($attributes, $content, $block); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block escapes every field.
