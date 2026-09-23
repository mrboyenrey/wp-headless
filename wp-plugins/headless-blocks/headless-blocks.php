<?php
/**
 * Plugin Name:       Headless Blocks
 * Description:       Exposes a page's Gutenberg blocks to WPGraphQL as a typed union, so a headless front end can render them as components instead of raw HTML.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Boien Reyes
 * License:           MIT
 * Text Domain:       headless-blocks
 *
 * WHY THIS EXISTS
 * ---------------
 * WordPress stores a page as a single blob of HTML in `post_content`, with the
 * block structure encoded as HTML comments. That is fine for a theme, which
 * just echoes the rendered markup, but useless for a typed front end: the React
 * side would receive one opaque string and could not know that a given stretch
 * of it is a hero rather than a paragraph.
 *
 * So this plugin does the translation. It reads WordPress's own block tree via
 * parse_blocks(), narrows it to the handful of block types the front end knows
 * how to draw, and republishes them over GraphQL as a union of real types with
 * real fields. The editor keeps using the ordinary block editor; the front end
 * gets structured, typed data.
 *
 * Anything the front end does not know how to render is deliberately NOT
 * silently dropped: it is reported through `skippedBlocks` so a content mistake
 * is a visible, debuggable fact rather than a mysterious gap on the page.
 */

declare(strict_types=1);

namespace HeadlessBlocks;

if (!defined('ABSPATH')) {
    exit; // Refuse direct access.
}

require_once __DIR__ . '/includes/class-block-parser.php';
require_once __DIR__ . '/includes/class-schema.php';

/**
 * WPGraphQL fires this once its type registry exists, which is the only point
 * at which register_graphql_* functions are guaranteed to be available. If
 * WPGraphQL is deactivated the hook simply never fires and the plugin is inert.
 */
add_action('graphql_register_types', [Schema::class, 'register_types']);
