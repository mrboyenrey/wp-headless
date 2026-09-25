<?php
/**
 * The content model the front end depends on beyond pages and posts.
 *
 * @package HeadlessBlocks
 */

declare(strict_types=1);

namespace HeadlessBlocks;

/**
 * Registers the Service post type and the fields that belong to it.
 *
 * Pages and posts come from WordPress itself. Services do not, so they are
 * declared here, beside the block contract, because the two are part of the
 * same promise: the front end can only rely on content whose shape it knows.
 *
 * The fields are registered post meta rather than an ACF field group. That is a
 * deliberate trade worth being able to defend. ACF would be faster to click
 * together in the admin, but it adds a plugin to the assembled list and it puts
 * the field definitions somewhere the front end cannot read. Registered meta
 * exposed over GraphQL keeps the whole contract in one place, in a file that
 * can be reviewed as code.
 *
 * The cost is honest and worth stating: there is no editor UI for these fields
 * beyond the Custom Fields panel, so a nicer admin would mean either ACF or a
 * small block to edit them. For three text fields, that is the right trade.
 */
final class Content_Types {

    public const SERVICE = 'service';

    /** Meta keys, named once so the resolver and the seeder cannot drift. */
    public const META_SHORT_DESCRIPTION = 'short_description';
    public const META_PRICE             = 'price';
    public const META_ICON              = 'icon';
    public const META_DESCRIPTION       = 'meta_description';

    /** Everything the front end renders, and therefore everything it can ask about. */
    public const RENDERED_TYPES = ['page', 'post', self::SERVICE];

    /** The one navigation location this site has. */
    public const MENU_LOCATION = 'primary';

    /**
     * Hooked to `after_setup_theme`.
     *
     * Declaring a menu location is normally the theme's job. There is no theme
     * here, and the editor still needs somewhere to put the navigation, so the
     * plugin declares it: one location, which an editor fills in under
     * Appearance, Menus exactly as they would on any other WordPress site.
     *
     * It is also load-bearing for the API rather than only for the admin.
     * WPGraphQL will not expose a menu that is not assigned to a registered
     * location, so without this the front end sees no menu at all.
     */
    public static function register_menu_location(): void {
        register_nav_menus([
            self::MENU_LOCATION => __('Primary navigation', 'headless-blocks'),
        ]);
    }

    /**
     * Hooked to `init`.
     */
    public static function register(): void {
        register_post_type(self::SERVICE, [
            'labels'              => [
                'name'          => __('Services', 'headless-blocks'),
                'singular_name' => __('Service', 'headless-blocks'),
            ],
            'description'         => __('A service offered, held as structured data rather than prose.', 'headless-blocks'),
            'public'              => true,
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-screenoptions',
            'supports'            => ['title', 'editor', 'excerpt', 'thumbnail'],
            /*
             * The front end owns the index. WordPress is not serving this site,
             * so letting it also generate an archive page would create a second
             * URL for the same content, which is the drift this project spends
             * its time avoiding.
             */
            'has_archive'         => false,
            'rewrite'             => ['slug' => 'services'],
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Service',
            'graphql_plural_name' => 'Services',
        ]);

        self::register_meta();
    }

    private static function register_meta(): void {
        foreach ([
            self::META_SHORT_DESCRIPTION,
            self::META_PRICE,
            self::META_ICON,
        ] as $key) {
            register_post_meta(self::SERVICE, $key, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => static fn (): bool => current_user_can('edit_posts'),
            ]);
        }

        /*
         * The SEO description applies to everything the front end renders, not
         * just to services, so it is registered against each of them in turn.
         * `show_in_rest` is what makes it available to the block editor's
         * Custom Fields panel as well as to GraphQL.
         */
        foreach (self::RENDERED_TYPES as $post_type) {
            register_post_meta($post_type, self::META_DESCRIPTION, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => static fn (): bool => current_user_can('edit_posts'),
            ]);
        }
    }

    /**
     * Hooked to `graphql_register_types`.
     *
     * Empty meta resolves to null rather than an empty string, so the front end
     * can tell "the editor left this blank" from "the editor typed nothing", and
     * the optional price stays optional in the schema as well as in the UI.
     */
    public static function register_graphql_fields(): void {
        self::register_meta_description_field();

        if (!post_type_exists(self::SERVICE)) {
            return;
        }

        foreach ([
            'shortDescription' => self::META_SHORT_DESCRIPTION,
            'price'            => self::META_PRICE,
            'icon'             => self::META_ICON,
        ] as $field => $meta_key) {
            register_graphql_field('Service', $field, [
                'type'        => 'String',
                'description' => __('Service detail held as post meta.', 'headless-blocks'),
                'resolve'     => static function ($source) use ($meta_key): ?string {
                    $post_id = self::post_id($source);

                    if ($post_id <= 0) {
                        return null;
                    }

                    $value = (string) get_post_meta($post_id, $meta_key, true);

                    return $value === '' ? null : $value;
                },
            ]);
        }
    }

    /**
     * The description a search engine or a link preview should use.
     *
     * WPGraphQL exposes `excerpt` on Post but not on Page, so without this a
     * page could not carry a description at all - and the brief asks for the
     * title and meta description to come from the CMS, per page. Rather than
     * reach for the excerpt on one content type and something else on another,
     * every type gets one field with one meaning: the explicit SEO value when an
     * editor has set one, and the excerpt otherwise.
     *
     * `get_the_excerpt` generating a summary from the body when no excerpt
     * exists is a feature here, not a surprise: the tag is never empty, and an
     * editor who cares can always override it.
     */
    private static function register_meta_description_field(): void {
        foreach (['Page', 'Post', 'Service'] as $type) {
            register_graphql_field($type, 'metaDescription', [
                'type'        => 'String',
                'description' => __('The description to publish about this content.', 'headless-blocks'),
                'resolve'     => static function ($source): ?string {
                    $post_id = self::post_id($source);

                    if ($post_id <= 0) {
                        return null;
                    }

                    $explicit = (string) get_post_meta($post_id, self::META_DESCRIPTION, true);

                    if ($explicit !== '') {
                        return $explicit;
                    }

                    $excerpt = (string) get_the_excerpt($post_id);

                    return trim($excerpt) === '' ? null : $excerpt;
                },
            ]);
        }
    }

    /**
     * WPGraphQL hands resolvers its own model objects; `databaseId` is the
     * canonical accessor there, with `ID` kept as a fallback.
     */
    private static function post_id($source): int {
        if (is_object($source)) {
            return (int) ($source->databaseId ?? $source->ID ?? 0);
        }

        if (is_array($source)) {
            return (int) ($source['databaseId'] ?? $source['ID'] ?? 0);
        }

        return 0;
    }
}
