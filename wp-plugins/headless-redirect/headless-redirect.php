<?php
/**
 * Plugin Name:       Headless Redirect
 * Description:       Sends WordPress's own front end to the React application, so the active theme is never publicly reachable. wp-admin, the REST API, WPGraphQL, previews and feeds are left alone.
 * Version:           1.0.0
 * Author:            Boien Reyes
 * License:           MIT
 * Text Domain:       headless-redirect
 *
 * WHY THIS EXISTS
 * ---------------
 * In a headless install WordPress is a content store, not a website. It still
 * has an active theme, and by default that theme stays reachable at the
 * WordPress address: anyone who opens the site root instead of the application
 * gets Twenty Twenty-Five, rendered from the same content the React front end
 * is supposed to draw.
 *
 * That is not a bug in either system, but it is a trap. Two front ends on one
 * body of content drift apart, and "the layout is broken" turns out to mean "I
 * was looking at the wrong address" - which is a confusing way to spend an
 * afternoon.
 *
 * So this plugin closes the second front end. A request for a WordPress page is
 * redirected to the equivalent path on the application, and the theme is never
 * served to a browser.
 *
 * Deliberately NOT redirected:
 *   wp-admin, wp-login.php     - where content is edited
 *   /graphql, /wp-json         - how the front end fetches content
 *   previews and feeds         - an editor checking their own work
 *   /wp-content, /wp-includes  - static assets, including uploads
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Refuse direct access.
}

/**
 * Where the application lives.
 *
 * A constant rather than a literal, so the address is configuration instead of
 * code, with a filter on top so another plugin or a theme can override it
 * without editing this file.
 */
function headless_redirect_target(): string {
    $target = defined('HEADLESS_FRONTEND_URL')
        ? (string) HEADLESS_FRONTEND_URL
        : 'http://localhost:3000';

    return (string) apply_filters('headless_redirect_target', $target);
}

/**
 * Paths that belong to WordPress itself rather than to the public site.
 *
 * /graphql matters most: the application fetches content over the Compose
 * network at `wordpress:80/graphql`, and redirecting that would break the site
 * in a way that looks like a WordPress outage.
 *
 * @var array<int, string>
 */
const HEADLESS_REDIRECT_EXEMPT_PATHS = [
    '/graphql',
    '/wp-json',
    '/wp-login.php',
    '/wp-admin',
    '/wp-content',
    '/wp-includes',
    '/wp-cron.php',
];

add_action('template_redirect', static function (): void {
    // Admin, AJAX and cron are WordPress doing its job, not the public site.
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    // A draft preview or a feed is an editor's concern, not a visitor's.
    if (is_preview() || is_customize_preview() || is_feed()) {
        return;
    }

    $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path        = (string) (parse_url($request_uri, PHP_URL_PATH) ?: '/');

    foreach (HEADLESS_REDIRECT_EXEMPT_PATHS as $exempt) {
        if (str_starts_with($path, $exempt)) {
            return;
        }
    }

    $query  = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $suffix = $query === '' ? $path : $path . '?' . $query;

    /*
     * 302, not 301.
     *
     * The redirect is permanent in intent but the address is not: it changes
     * with the port, and in a Codespace with the forwarded hostname. A 301 is
     * cached by the browser indefinitely, so a changed address would present as
     * the site being broken with nothing on the server to explain it. A 302
     * costs one extra request and cannot poison a browser.
     */
    wp_redirect(rtrim(headless_redirect_target(), '/') . $suffix, 302);
    exit;
});
