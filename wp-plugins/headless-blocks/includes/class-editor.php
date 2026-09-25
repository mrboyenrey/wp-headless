<?php
/**
 * Editor constraints: only offer blocks the front end can draw.
 *
 * @package HeadlessBlocks
 */

declare(strict_types=1);

namespace HeadlessBlocks;

/**
 * Narrows the block inserter to the renderable library.
 *
 * The Content notice reports unrenderable blocks after the fact. That is
 * genuinely useful while the seam is being built, but it is the wrong place to
 * catch the problem: by the time a block shows up in that notice the editor has
 * already published a page with a hole in it.
 *
 * Restricting the inserter makes the state unreachable instead. An editor
 * cannot insert a block the front end will not draw, so there is nothing to
 * report - the notice becomes a safety net for blocks that predate this
 * restriction rather than a permanent fixture of every page.
 *
 * The list is derived from the parser's own handlers rather than written out a
 * second time. One list, two consumers: adding a parser method makes a block
 * insertable in the same edit, so the picker and the renderer cannot drift.
 */
final class Editor {

    /**
     * Blocks the inserter must keep available even though nothing renders them
     * directly.
     *
     * `core/button` is the case that matters. A button is a child of
     * `core/buttons`, and the front end reads it out of that parent rather than
     * giving it a type of its own. Blocking it would leave the buttons block
     * unable to contain anything, turning the one usable call-to-action into an
     * empty box an editor cannot fill.
     *
     * @var array<int, string>
     */
    private const CHILD_BLOCKS = [
        'core/button',
    ];

    /**
     * Hooked to `allowed_block_types_all`.
     *
     * @param bool|array<int, string>  $allowed The block types the editor may insert.
     * @param \WP_Block_Editor_Context $context Which editor is asking.
     * @return bool|array<int, string>
     */
    public static function restrict_block_picker($allowed, $context) {
        if (!$context instanceof \WP_Block_Editor_Context) {
            return $allowed;
        }

        /*
         * Only the post editor.
         *
         * The site editor and the widget editor edit templates and sidebars,
         * which have nothing to do with this front end's block library. The
         * WordPress theme is unreachable anyway (the headless-redirect plugin
         * redirects it), and narrowing those screens would only break them.
         */
        if ($context->name !== 'core/edit-post') {
            return $allowed;
        }

        /*
         * Only the post types this front end actually renders.
         *
         * A reusable block, or a post type served by something else, is left
         * alone. Guessing there is how an admin screen quietly stops working -
         * and failing open is the safe direction, because the worst case is
         * that an editor inserts something the notice will report.
         */
        $post_type = self::editor_post_type($context);

        if (!in_array($post_type, ['page', 'post', 'service'], true)) {
            return $allowed;
        }

        return array_merge(Block_Parser::supported_block_names(), self::CHILD_BLOCKS);
    }

    /**
     * Which post type the editor is editing.
     *
     * The post is never null in the post editor: WordPress builds an auto-draft
     * to hold the edit even for a page that does not exist yet
     * (`get_default_post_to_edit()` in wp-admin/edit-form-blocks.php), so a
     * brand new page is covered by this as much as an existing one.
     *
     * If it ever were absent this returns an empty string, which fails the
     * check above and leaves the editor unrestricted - the safe direction.
     *
     * There is deliberately no fallback to `$context->post_type`:
     * `WP_Block_Editor_Context` has no such property as of WordPress 7.1,
     * which was confirmed against the running container rather than assumed.
     */
    private static function editor_post_type(\WP_Block_Editor_Context $context): string {
        return $context->post instanceof \WP_Post ? (string) $context->post->post_type : '';
    }
}
