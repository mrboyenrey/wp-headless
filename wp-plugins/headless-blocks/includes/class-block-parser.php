<?php
/**
 * Gutenberg block tree -> normalised, typed block list.
 *
 * @package HeadlessBlocks
 */

declare(strict_types=1);

namespace HeadlessBlocks;

/**
 * Translates `post_content` into an array of block payloads.
 *
 * The output of this class is deliberately shaped like the GraphQL response:
 * every array carries the name of the GraphQL type it should be resolved as,
 * so the schema layer never has to guess.
 */
final class Block_Parser {

    /**
     * The key that tells the schema layer which GraphQL type a block resolves
     * to. Kept internal: it is never declared as a GraphQL field, it only
     * exists so `resolveType` is a lookup rather than a chain of conditions.
     */
    public const TYPE_KEY = '__type';

    /**
     * The complete library of blocks this site's front end can render.
     *
     * Block names are WordPress's own (`namespace/block`). Anything not listed
     * here is reported as skipped rather than being rendered as raw HTML, which
     * is what keeps an unrecognised block from leaking untyped markup into a
     * typed component tree.
     *
     * @var array<string, string> block name => parser method
     */
    private const HANDLERS = [
        'core/cover'      => 'parse_hero',
        'core/heading'    => 'parse_heading',
        'core/paragraph'  => 'parse_rich_text',
        'core/media-text' => 'parse_image_text',
        'core/buttons'    => 'parse_call_to_action',
        'core/video'      => 'parse_video',
    ];

    /**
     * Memoised parse results, keyed by post id + content hash.
     *
     * `contentBlocks`, `skippedBlocks` and `omittedBlocks` are separate
     * GraphQL fields on the same page, so without this the content would be
     * parsed three times per request for no gain.
     *
     * @var array<string, array{blocks: array<int, array<string, mixed>>, skipped: array<int, string>, omitted: array<int, string>}>
     */
    private static array $cache = [];

    /**
     * Parse a post's content into typed blocks.
     *
     * @return array{blocks: array<int, array<string, mixed>>, skipped: array<int, string>, omitted: array<int, string>}
     */
    public static function parse(int $post_id, string $content): array {
        $cache_key = $post_id . ':' . md5($content);

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $blocks  = [];
        $skipped = [];
        $omitted = [];

        foreach (parse_blocks($content) as $block) {
            $name = $block['blockName'] ?? null;

            /*
             * A null block name means content that is not inside block comments
             * at all. That is either whitespace sitting between blocks, or a
             * block the editor converted to freeform (classic) content.
             *
             * Whitespace is noise and is ignored. Freeform content is real
             * writing, and discarding it without a word is precisely the
             * failure this plugin exists to prevent, so it is reported.
             */
            if ($name === null) {
                if (trim(strip_tags((string) ($block['innerHTML'] ?? ''))) !== '') {
                    $skipped[] = 'core/freeform';
                }

                continue;
            }

            if (!isset(self::HANDLERS[$name])) {
                $skipped[] = $name;
                continue;
            }

            $method = self::HANDLERS[$name];
            $parsed = self::$method($block);

            /*
             * A handler returns null for a block the front end understands but
             * that has nothing in it to draw: a button with no label or target,
             * a paragraph with no words, a cover with no heading.
             *
             * Dropping it is right - there is nothing to render. Dropping it
             * silently is not. To the editor the block is still sitting there
             * in WordPress, so a component that quietly fails to appear reads
             * as content destroyed on save.
             */
            if ($parsed === null) {
                $omitted[] = $name;
                continue;
            }

            $blocks[] = $parsed;
        }

        return self::$cache[$cache_key] = [
            'blocks'  => $blocks,
            'skipped' => $skipped,
            'omitted' => $omitted,
        ];
    }

    /**
     * The block names this front end can render.
     *
     * Exposed so the editor's block picker can be restricted to the same list.
     * Deriving one from the other is what stops the two drifting: adding a
     * parser method here makes the block insertable there, in the same edit.
     *
     * @return array<int, string>
     */
    public static function supported_block_names(): array {
        return array_keys(self::HANDLERS);
    }

    /**
     * core/cover -> HeroBlock.
     *
     * The cover block keeps its background image in attributes but leaves the
     * words in the rendered inner HTML, so the heading and standfirst are read
     * back out of the markup.
     */
    private static function parse_hero(array $block): ?array {
        $attrs    = $block['attrs'] ?? [];
        $document = self::load_html(self::rendered_html($block));

        if ($document === null) {
            return null;
        }

        $heading = self::text_of(self::first_element($document, ['h1', 'h2', 'h3']));

        // A hero with no words is not a hero. Better to skip it than to render
        // an empty band of background image.
        if ($heading === '') {
            return null;
        }

        return [
            self::TYPE_KEY   => 'HeroBlock',
            'heading'        => $heading,
            'subheading'     => self::text_of(self::first_element($document, ['p'])) ?: null,
            'overlayOpacity' => isset($attrs['dimRatio']) ? (float) $attrs['dimRatio'] : 50.0,
            'image'          => self::image_from_attrs($attrs),
        ];
    }

    /**
     * core/paragraph -> RichTextBlock.
     *
     * The paragraph's rendered HTML is passed through as-is so inline marks
     * (bold, links, italic) survive. The front end renders it with
     * dangerouslySetInnerHTML, which is safe here because this content has
     * already been through WordPress's own KSES sanitisation on save and is
     * authored by a logged-in editor, not by a visitor.
     */
    private static function parse_rich_text(array $block): ?array {
        $html = trim($block['innerHTML'] ?? '');

        if (trim(strip_tags($html)) === '') {
            return null;
        }

        return [
            self::TYPE_KEY => 'RichTextBlock',
            'html'         => $html,
        ];
    }

    /**
     * core/heading -> HeadingBlock.
     *
     * The heading's words live in the rendered markup rather than in attributes,
     * so the wrapper element is located first and only its inner HTML is kept.
     * Keeping the wrapper would nest a second <h2> inside the one the component
     * emits, because the front end owns the tag.
     *
     * The level is clamped rather than trusted: WordPress stores it as a free
     * integer, and there is no HTML element for a level outside 1-6.
     */
    private static function parse_heading(array $block): ?array {
        $attrs    = $block['attrs'] ?? [];
        $document = self::load_html($block['innerHTML'] ?? '');

        if ($document === null) {
            return null;
        }

        $element = self::first_element($document, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);

        if ($element === null) {
            return null;
        }

        $html = trim(self::inner_html($document, $element));

        // A heading with no words is not a heading.
        if (trim(strip_tags($html)) === '') {
            return null;
        }

        $level = isset($attrs['level']) ? (int) $attrs['level'] : 2;

        return [
            self::TYPE_KEY => 'HeadingBlock',
            'level'        => max(1, min(6, $level)),
            'html'         => $html,
        ];
    }

    /**
     * core/media-text -> ImageTextBlock.
     */
    private static function parse_image_text(array $block): ?array {
        $attrs    = $block['attrs'] ?? [];
        $document = self::load_html(self::rendered_html($block));

        if ($document === null) {
            return null;
        }

        $heading = self::text_of(self::first_element($document, ['h1', 'h2', 'h3', 'h4']));

        $body    = '';
        $content = self::element_by_class($document, 'wp-block-media-text__content');

        if ($content !== null) {
            // The heading lives inside the text column and is already returned
            // as its own field. Leaving it here would render it twice: once as
            // the heading, once again inside the body HTML.
            self::drop_first_of($content, ['h1', 'h2', 'h3', 'h4']);
            $body = self::inner_html($document, $content);
        }

        if ($heading === '' && $body === '') {
            return null;
        }

        // The block stores this as a free string; anything that is not exactly
        // "right" is treated as "left" so the front end only ever receives one
        // of two known values.
        $position = ($attrs['mediaPosition'] ?? 'left') === 'right' ? 'right' : 'left';

        return [
            self::TYPE_KEY  => 'ImageTextBlock',
            'heading'       => $heading ?: null,
            'bodyHtml'      => $body ?: null,
            'mediaPosition' => $position,
            'image'         => self::image_from_attrs($attrs),
        ];
    }

    /**
     * core/buttons -> CallToActionBlock.
     *
     * Only the first button is used. A call to action with two competing
     * primary buttons is a design problem, so the extra ones are ignored
     * rather than surfaced.
     */
    private static function parse_call_to_action(array $block): ?array {
        $buttons = $block['innerBlocks'] ?? [];

        if ($buttons === []) {
            return null;
        }

        $button   = $buttons[0];
        $attrs    = $button['attrs'] ?? [];
        $document = self::load_html($button['innerHTML'] ?? '');

        $label = self::text_of(self::first_element($document, ['a', 'span']));
        $url   = (string) ($attrs['url'] ?? '');

        // Older button blocks stored the target on the anchor rather than in
        // attributes, so fall back to reading it out of the markup.
        if ($url === '') {
            $url = self::first_attribute($document, 'a', 'href');
        }

        if ($label === '' || $url === '') {
            return null;
        }

        return [
            self::TYPE_KEY     => 'CallToActionBlock',
            'label'            => $label,
            'url'              => $url,
            'opensInNewTab'    => !empty($attrs['linkTarget']),
        ];
    }

    /**
     * core/video -> VideoBlock.
     *
     * The block records an uploaded video as an attachment id and leaves the
     * file's address to WordPress, so the markup is rendered and the address is
     * read back off the tag. Reading `attrs['src']` alone would find nothing for
     * anything uploaded to the media library, which is the same trap the image
     * blocks have: the id is the real reference, the URL is a convenience.
     *
     * The four playback flags are read off the rendered tag rather than out of
     * the attributes, because WordPress only writes an attribute when it differs
     * from the block's own default. The tag is what the editor actually asked
     * for, defaults included.
     */
    private static function parse_video(array $block): ?array {
        $attrs    = $block['attrs'] ?? [];
        $document = self::load_html(self::rendered_html($block));

        if ($document === null) {
            return null;
        }

        $video = self::first_element($document, ['video']);

        $src = $video instanceof \DOMElement ? trim($video->getAttribute('src')) : '';

        // A video added by address rather than uploaded keeps its address on the
        // block itself, so it is still worth looking there.
        if ($src === '') {
            $src = trim((string) ($attrs['src'] ?? ''));
        }

        // A video with no address is not a video. Reported as omitted rather
        // than drawn as an empty player.
        if ($src === '') {
            return null;
        }

        $poster  = $video instanceof \DOMElement ? trim($video->getAttribute('poster')) : '';
        $caption = self::text_of(self::element_by_class($document, 'wp-element-caption'));

        $controls = $video instanceof \DOMElement && $video->hasAttribute('controls');
        $autoplay = $video instanceof \DOMElement && $video->hasAttribute('autoplay');
        $loop     = $video instanceof \DOMElement && $video->hasAttribute('loop');
        $muted    = $video instanceof \DOMElement && $video->hasAttribute('muted');

        return [
            self::TYPE_KEY => 'VideoBlock',
            'src'          => $src,
            'posterUrl'    => $poster !== '' ? $poster : null,
            'caption'      => $caption !== '' ? $caption : null,
            'controls'     => $controls,
            'autoplay'     => $autoplay,
            'loop'         => $loop,
            'muted'        => $muted,
        ];
    }

    /* ---------------------------------------------------------------------
     * Image resolution
     * ------------------------------------------------------------------ */

    /**
     * Resolve an image from block attributes.
     *
     * Media blocks normally record an attachment id, which is the better
     * source: it gives us the real dimensions (so the front end can reserve
     * space and avoid layout shift) and the alt text. The bare URL is only a
     * fallback for images that were pasted in without being uploaded.
     */
    private static function image_from_attrs(array $attrs): ?array {
        // Cover blocks record their attachment as `id`; media-text blocks use
        // `mediaId`. Missing the second one is what silently degrades an image
        // to a bare URL with no dimensions and no alt text.
        $id  = (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0);
        $url = (string) ($attrs['url'] ?? $attrs['mediaUrl'] ?? '');

        if ($id > 0) {
            $src = wp_get_attachment_image_src($id, 'full');

            if (is_array($src)) {
                return [
                    'url'    => (string) $src[0],
                    'width'  => (int) $src[1],
                    'height' => (int) $src[2],
                    'alt'    => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                ];
            }
        }

        if ($url !== '') {
            return [
                'url'    => $url,
                'width'  => null,
                'height' => null,
                'alt'    => isset($attrs['alt']) ? (string) $attrs['alt'] : null,
            ];
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * Small HTML helpers
     *
     * WordPress hands us rendered markup, not a field structure, so a few
     * reads against the DOM are unavoidable. They are kept in one place and
     * are non-fatal: a malformed fragment yields an empty string, never an
     * exception, because a parse failure here must not take down the page.
     * ------------------------------------------------------------------ */

    /**
     * The complete rendered markup for a block, nested blocks included.
     *
     * `$block['innerHTML']` is not that, and the difference is the whole reason
     * container blocks have to be read through this helper. For a block that
     * holds other blocks, WordPress keeps the nested content in `innerBlocks`
     * and `innerHTML` is only the markup *around* it. A media-text block
     * authored in the editor therefore arrives looking like an empty content
     * column, because the words live in a nested paragraph rather than in the
     * markup - and the parser concluded there was nothing to draw.
     *
     * The seed content hid this: it was written as hand-rolled HTML with the
     * heading and paragraph inline, so it exercised the one shape where
     * `innerHTML` happens to be complete.
     *
     * `render_block()` is what a theme would use, so it produces the markup the
     * block actually means. It is handed block data that has already been
     * parsed, so nothing is re-parsed, and `parse()` memoises the result.
     */
    private static function rendered_html(array $block): string {
        if (function_exists('render_block')) {
            $html = (string) render_block($block);

            if (trim($html) !== '') {
                return $html;
            }
        }

        // Fall back rather than fail: an empty read here is a missing section,
        // which is worse than slightly incomplete markup.
        return (string) ($block['innerHTML'] ?? '');
    }

    /**
     * The first element matching one of the given tags.
     *
     * @param array<int, string> $tags Tags in order of preference.
     */
    private static function first_element(?\DOMDocument $document, array $tags): ?\DOMElement {
        if ($document === null) {
            return null;
        }

        foreach ($tags as $tag) {
            $nodes = $document->getElementsByTagName($tag);

            if ($nodes->length > 0) {
                /** @var \DOMElement $element */
                $element = $nodes->item(0);

                return $element;
            }
        }

        return null;
    }

    /**
     * Trimmed text content of an element, or an empty string when there is none.
     */
    private static function text_of(?\DOMElement $element): string {
        return $element instanceof \DOMElement ? trim((string) $element->textContent) : '';
    }

    /**
     * A single attribute from the first matching element, or an empty string.
     */
    private static function first_attribute(?\DOMDocument $document, string $tag, string $attribute): string {
        $element = self::first_element($document, [$tag]);

        if (!$element instanceof \DOMElement) {
            return '';
        }

        return $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
    }

    /**
     * The first element carrying the given class.
     *
     * Matched on whitespace-delimited tokens, because `class` routinely holds
     * several values and an equality check would miss them.
     */
    private static function element_by_class(\DOMDocument $document, string $class): ?\DOMElement {
        $xpath = new \DOMXPath($document);
        $query = sprintf(
            '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
            $class
        );

        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        /** @var \DOMElement $element */
        $element = $nodes->item(0);

        return $element;
    }

    /**
     * Serialise everything inside an element, tags included.
     */
    private static function inner_html(\DOMDocument $document, \DOMNode $node): string {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    /**
     * Remove the first descendant matching one of the given tags.
     *
     * Used to lift a heading out of a container whose remaining HTML is
     * returned as a body field, so the heading is not rendered twice.
     *
     * @param array<int, string> $tags
     */
    private static function drop_first_of(\DOMElement $element, array $tags): void {
        foreach ($tags as $tag) {
            $nodes = $element->getElementsByTagName($tag);

            if ($nodes->length > 0) {
                $node = $nodes->item(0);

                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }

                return;
            }
        }
    }

    /**
     * Load a fragment of HTML into a DOM document.
     *
     * Returns null when the fragment is empty or unparseable.
     */
    private static function load_html(string $html): ?\DOMDocument {
        if (trim($html) === '') {
            return null;
        }

        $document = new \DOMDocument();

        // libxml reports every unknown tag as an error. We neither want those
        // printed nor to disturb whatever error handling is already in place,
        // so the flags are saved and restored around the call.
        $previous = libxml_use_internal_errors(true);

        // Without this hint loadHTML assumes ISO-8859-1 and mangles any
        // non-ASCII character in the content.
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
