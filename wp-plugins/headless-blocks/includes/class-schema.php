<?php
/**
 * GraphQL schema: the block union and the fields that expose it.
 *
 * @package HeadlessBlocks
 */

declare(strict_types=1);

namespace HeadlessBlocks;

/**
 * Registers the typed block API with WPGraphQL.
 *
 * The shape mirrors the TypeScript discriminated union on the front end
 * one-for-one: one GraphQL object type per renderable block, joined by a union
 * so a list of mixed blocks stays exhaustively type-checkable.
 */
final class Schema {

    /**
     * The union type name the front end queries against.
     */
    public const UNION = 'ContentBlock';

    /**
     * Post types the block fields are attached to.
     *
     * @var array<int, string>
     */
    private const POST_TYPES = ['Page', 'Post'];

    /**
     * Hooked to `graphql_register_types`.
     */
    public static function register_types(): void {
        self::register_object_types();
        self::register_union();
        self::register_fields();
    }

    /* ---------------------------------------------------------------------
     * Types
     * ------------------------------------------------------------------ */

    private static function register_object_types(): void {
        register_graphql_object_type('BlockImage', [
            'description' => __('An image referenced by a block.', 'headless-blocks'),
            'fields'      => [
                'url'    => [
                    'type'        => 'String',
                    'description' => __('The full-size image URL.', 'headless-blocks'),
                ],
                'alt'    => [
                    'type'        => 'String',
                    'description' => __('Alternative text, empty when the editor supplied none.', 'headless-blocks'),
                ],
                'width'  => [
                    'type'        => 'Int',
                    'description' => __('Natural width in pixels, null when unknown.', 'headless-blocks'),
                ],
                'height' => [
                    'type'        => 'Int',
                    'description' => __('Natural height in pixels, null when unknown.', 'headless-blocks'),
                ],
            ],
        ]);

        register_graphql_object_type('HeroBlock', [
            'description' => __('A full-width cover, used as the page hero.', 'headless-blocks'),
            'fields'      => [
                'heading'        => [
                    'type'        => 'String',
                    'description' => __('The hero headline.', 'headless-blocks'),
                ],
                'subheading'     => [
                    'type'        => 'String',
                    'description' => __('Optional standfirst beneath the headline.', 'headless-blocks'),
                ],
                'overlayOpacity' => [
                    'type'        => 'Float',
                    'description' => __('Scrim strength over the image, 0-100, from the block dimRatio.', 'headless-blocks'),
                ],
                'image'          => [
                    'type'        => 'BlockImage',
                    'description' => __('Background image, null when the cover has none.', 'headless-blocks'),
                ],
            ],
        ]);

        register_graphql_object_type('RichTextBlock', [
            'description' => __('A paragraph of rich text.', 'headless-blocks'),
            'fields'      => [
                'html' => [
                    'type'        => 'String',
                    'description' => __('The paragraph HTML, including inline marks and links.', 'headless-blocks'),
                ],
            ],
        ]);

        register_graphql_object_type('ImageTextBlock', [
            'description' => __('An image paired with a block of text.', 'headless-blocks'),
            'fields'      => [
                'heading'       => [
                    'type'        => 'String',
                    'description' => __('Optional heading above the body copy.', 'headless-blocks'),
                ],
                'bodyHtml'      => [
                    'type'        => 'String',
                    'description' => __('The text column HTML.', 'headless-blocks'),
                ],
                'mediaPosition' => [
                    'type'        => 'String',
                    'description' => __('Which side the image sits on: "left" or "right".', 'headless-blocks'),
                ],
                'image'         => [
                    'type'        => 'BlockImage',
                    'description' => __('The paired image.', 'headless-blocks'),
                ],
            ],
        ]);

        register_graphql_object_type('CallToActionBlock', [
            'description' => __('A single call-to-action button.', 'headless-blocks'),
            'fields'      => [
                'label'         => [
                    'type'        => 'String',
                    'description' => __('Button text.', 'headless-blocks'),
                ],
                'url'           => [
                    'type'        => 'String',
                    'description' => __('Button target.', 'headless-blocks'),
                ],
                'opensInNewTab' => [
                    'type'        => 'Boolean',
                    'description' => __('Whether the link was set to open in a new tab.', 'headless-blocks'),
                ],
            ],
        ]);
    }

    /**
     * Join the block types into a single union.
     *
     * A union rather than one loose type with a discriminator is what lets the
     * front end switch on `__typename` and get compile-time exhaustiveness: add
     * a block type here without updating the renderer and TypeScript says so.
     */
    private static function register_union(): void {
        register_graphql_union_type(self::UNION, [
            'description' => __('A single block of page content.', 'headless-blocks'),
            'typeNames'   => [
                'HeroBlock',
                'RichTextBlock',
                'ImageTextBlock',
                'CallToActionBlock',
            ],
            /**
             * The parser tags every block with the GraphQL type it should be
             * resolved as, so this stays a lookup instead of a chain of
             * instanceof checks that would need editing for each new block.
             */
            'resolveType' => static function ($value): ?string {
                return is_array($value) && isset($value[Block_Parser::TYPE_KEY])
                    ? (string) $value[Block_Parser::TYPE_KEY]
                    : null;
            },
        ]);
    }

    /* ---------------------------------------------------------------------
     * Fields
     * ------------------------------------------------------------------ */

    private static function register_fields(): void {
        foreach (self::POST_TYPES as $post_type) {
            register_graphql_field($post_type, 'contentBlocks', [
                'type'        => ['list_of' => self::UNION],
                'description' => __(
                    'The page content as a list of typed blocks, in editor order.',
                    'headless-blocks'
                ),
                'resolve'     => static function ($source): array {
                    return self::parsed($source)['blocks'];
                },
            ]);

            register_graphql_field($post_type, 'skippedBlocks', [
                'type'        => ['list_of' => 'String'],
                'description' => __(
                    'Block names present in the content that this front end cannot render. Surfaced so dropped content is visible rather than silent.',
                    'headless-blocks'
                ),
                'resolve'     => static function ($source): array {
                    return self::parsed($source)['skipped'];
                },
            ]);
        }
    }

    /**
     * Parse the post behind a GraphQL source model.
     *
     * WPGraphQL hands resolvers its own model objects. `databaseId` is the
     * canonical accessor there, with `ID` kept as a fallback.
     *
     * @return array{blocks: array<int, array<string, mixed>>, skipped: array<int, string>}
     */
    private static function parsed($source): array {
        $post_id = 0;

        if (is_object($source)) {
            $post_id = (int) ($source->databaseId ?? $source->ID ?? 0);
        } elseif (is_array($source)) {
            $post_id = (int) ($source['databaseId'] ?? $source['ID'] ?? 0);
        }

        $post = $post_id > 0 ? get_post($post_id) : null;

        if (!$post instanceof \WP_Post) {
            return ['blocks' => [], 'skipped' => []];
        }

        return Block_Parser::parse((int) $post->ID, (string) $post->post_content);
    }
}
