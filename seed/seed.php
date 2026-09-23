<?php
/**
 * Rebuilds the demo content.
 *
 *   docker compose run --rm wpcli eval-file /seed/seed.php
 *
 * The site this seeds is deliberately unremarkable: three pages made of the
 * four block types the front end understands, plus one block type it does not,
 * so the "skipped blocks" behaviour can be seen working rather than merely
 * described.
 *
 * The script is idempotent. Everything it creates is tagged with the
 * `_headless_seed` meta key and deleted at the start of the next run, so
 * re-running never leaves duplicates behind.
 *
 * WHY GENERATE IMAGES INSTEAD OF SHIPPING THEM
 * --------------------------------------------
 * Binary assets in a repository are awkward to review and easy to end up
 * shipping by accident. These are flat gradients drawn with GD, which costs
 * nothing, needs no licence, and keeps the repository free of binaries. Swap
 * them for real photographs by importing media in wp-admin; nothing in the
 * parser or the front end cares where the image came from.
 *
 * @package HeadlessBlocks
 */

// No `declare(strict_types=1)` in this file: `wp eval-file` runs it through
// eval(), which rejects a declare statement outright. The plugin classes are
// included normally and do carry it.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this with: docker compose run --rm wpcli eval-file /seed/seed.php\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/image.php';

const SEED_META = '_headless_seed';

/* -------------------------------------------------------------------------
 * Housekeeping
 * ---------------------------------------------------------------------- */

/**
 * Remove anything a previous run created.
 */
function seed_purge(): void {
    $ids = get_posts([
        'post_type'   => ['page', 'attachment'],
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_key'    => SEED_META,
        'meta_value'  => '1',
    ]);

    foreach ($ids as $id) {
        // Attachments only go away for real with the force flag.
        wp_delete_post((int) $id, true);
    }

    WP_CLI::log(sprintf('Purged %d item(s) from a previous run.', count($ids)));
}

/**
 * Remove the pages WordPress creates on install.
 *
 * They are published, so they would otherwise turn up in the site navigation
 * and make a finished page look unfinished.
 */
function seed_remove_default_content(): void {
    foreach (['sample-page', 'privacy-policy'] as $slug) {
        $page = get_page_by_path($slug);

        if ($page instanceof WP_Post) {
            wp_delete_post($page->ID, true);
            WP_CLI::log(sprintf('Removed the default "%s" page.', $page->post_title));
        }
    }
}

/* -------------------------------------------------------------------------
 * Images
 * ---------------------------------------------------------------------- */

/**
 * Draw a placeholder image and register it in the media library.
 *
 * @param array{0:int,1:int,2:int} $from Top colour, RGB 0-255.
 * @param array{0:int,1:int,2:int} $to   Bottom colour, RGB 0-255.
 *
 * @return array{id: int, url: string, alt: string}
 */
function seed_image(string $filename, string $alt, array $from, array $to, int $width, int $height): array {
    $uploads = wp_upload_dir();

    if (!empty($uploads['error'])) {
        WP_CLI::error('Uploads directory is not writable: ' . $uploads['error']);
    }

    $path = $uploads['path'] . '/' . $filename;

    $image = imagecreatetruecolor($width, $height);

    // Vertical gradient, drawn one scanline at a time. Slow but obvious, and
    // these run once.
    for ($y = 0; $y < $height; $y++) {
        $ratio = $height > 1 ? $y / ($height - 1) : 0.0;

        $colour = imagecolorallocate(
            $image,
            (int) round($from[0] + ($to[0] - $from[0]) * $ratio),
            (int) round($from[1] + ($to[1] - $from[1]) * $ratio),
            (int) round($from[2] + ($to[2] - $from[2]) * $ratio)
        );

        imageline($image, 0, $y, $width, $y, $colour);
    }

    // Two soft translucent discs, so the placeholder reads as a deliberate
    // graphic rather than a failed image load.
    $glow = imagecolorallocatealpha($image, 255, 255, 255, 116);
    imagefilledellipse($image, (int) ($width * 0.72), (int) ($height * 0.28), (int) ($width * 0.55), (int) ($height * 0.75), $glow);
    $glow_2 = imagecolorallocatealpha($image, 255, 255, 255, 122);
    imagefilledellipse($image, (int) ($width * 0.18), (int) ($height * 0.82), (int) ($width * 0.42), (int) ($height * 0.62), $glow_2);

    imagejpeg($image, $path, 85);
    imagedestroy($image);

    $check = wp_check_filetype($filename);

    $attachment_id = wp_insert_attachment(
        [
            'guid'           => $uploads['url'] . '/' . $filename,
            'post_mime_type' => $check['type'],
            'post_title'     => $alt,
            'post_status'    => 'inherit',
        ],
        $path
    );

    if (is_wp_error($attachment_id)) {
        WP_CLI::error('Could not register image: ' . $attachment_id->get_error_message());
    }

    // Generates the size metadata. This is what makes `wp_get_attachment_image_src`
    // on the WordPress side, and therefore the width/height in GraphQL, real.
    wp_update_attachment_metadata(
        $attachment_id,
        wp_generate_attachment_metadata($attachment_id, $path)
    );

    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    update_post_meta($attachment_id, SEED_META, '1');

    WP_CLI::log(sprintf('Created image %s (attachment %d).', $filename, $attachment_id));

    return [
        'id'  => (int) $attachment_id,
        'url' => (string) wp_get_attachment_image_url($attachment_id, 'full'),
        'alt' => $alt,
    ];
}

/* -------------------------------------------------------------------------
 * Block markup builders
 *
 * These emit the same HTML WordPress's block editor emits, comment delimiters
 * included, because that is precisely what parse_blocks() on the far side
 * expects to find. Writing them by hand is the honest way to seed a block
 * editor's content without driving a browser.
 * ---------------------------------------------------------------------- */

function block_cover(string $heading, string $subheading, array $image, int $dim_ratio = 60): string {
    $attrs = wp_json_encode([
        'url'          => $image['url'],
        'id'           => $image['id'],
        'dimRatio'     => $dim_ratio,
        'minHeight'    => 560,
        'minHeightUnit' => 'px',
        'align'        => 'full',
    ]);

    $alt      = esc_attr($image['alt']);
    $heading  = esc_html($heading);
    $subheading = esc_html($subheading);

    return <<<HTML
<!-- wp:cover {$attrs} -->
<div class="wp-block-cover alignfull" style="min-height:560px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-{$dim_ratio} has-background-dim"></span><img class="wp-block-cover__image-background" alt="{$alt}" src="{$image['url']}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><h1 class="wp-block-heading has-text-align-center">{$heading}</h1><p class="has-text-align-center">{$subheading}</p></div></div>
<!-- /wp:cover -->
HTML;
}

function block_paragraph(string $html): string {
    return <<<HTML
<!-- wp:paragraph -->
<p>{$html}</p>
<!-- /wp:paragraph -->
HTML;
}

function block_media_text(array $image, string $heading, string $body, string $position = 'left'): string {
    $attrs = wp_json_encode([
        'mediaId'       => $image['id'],
        'mediaType'     => 'image',
        'mediaUrl'      => $image['url'],
        'mediaAlt'      => $image['alt'],
        'mediaPosition' => $position,
    ]);

    $alt     = esc_attr($image['alt']);
    $heading = esc_html($heading);

    return <<<HTML
<!-- wp:media-text {$attrs} -->
<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="{$image['url']}" alt="{$alt}" class="wp-image-{$image['id']} size-full"/></figure><div class="wp-block-media-text__content"><h2 class="wp-block-heading">{$heading}</h2><p>{$body}</p></div></div>
<!-- /wp:media-text -->
HTML;
}

function block_buttons(string $label, string $url): string {
    $attrs = wp_json_encode([
        'layout' => ['type' => 'flex', 'justifyContent' => 'center'],
    ]);

    $button_attrs = wp_json_encode(['url' => $url]);
    $label        = esc_html($label);
    $url          = esc_url($url);

    return <<<HTML
<!-- wp:buttons {$attrs} -->
<div class="wp-block-buttons"><!-- wp:button {$button_attrs} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{$url}">{$label}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
HTML;
}

/**
 * A block the front end has no component for.
 *
 * Included on purpose. It is the difference between "unknown content is
 * silently dropped" and "unknown content is reported", and the only way to
 * demonstrate the latter is to actually put one in the content.
 */
function block_unsupported_list(): string {
    return <<<HTML
<!-- wp:list -->
<ul class="wp-block-list"><li>This block has no React component.</li><li>It should be reported, not rendered.</li></ul>
<!-- /wp:list -->
HTML;
}

/* -------------------------------------------------------------------------
 * Pages
 * ---------------------------------------------------------------------- */

function seed_page(string $slug, string $title, string $content): int {
    $existing = get_page_by_path($slug);

    $data = [
        'post_type'    => 'page',
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $content,
        'post_status'  => 'publish',
    ];

    if ($existing instanceof WP_Post) {
        $data['ID'] = $existing->ID;
        $page_id    = wp_update_post($data, true);
    } else {
        $page_id = wp_insert_post($data, true);
    }

    if (is_wp_error($page_id)) {
        WP_CLI::error(sprintf('Could not save page "%s": %s', $title, $page_id->get_error_message()));
    }

    update_post_meta((int) $page_id, SEED_META, '1');

    WP_CLI::log(sprintf('Saved page "%s" at /%s (id %d).', $title, $slug, $page_id));

    return (int) $page_id;
}

/* -------------------------------------------------------------------------
 * Run
 * ---------------------------------------------------------------------- */

seed_purge();
seed_remove_default_content();

update_option('blogname', 'Boien Reyes');
update_option('blogdescription', 'A headless WordPress site rendered by React');

/*
 * Pretty permalinks.
 *
 * On the query-string default, every page's canonical URI is "/?page_id=13".
 * That gives the front end nothing to route on, and the navigation it derives
 * from page URIs would be a list of query strings rather than readable links.
 * Setting the structure and flushing rewrite rules is what makes "/about" a
 * real address again.
 */
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);

$hero_image  = seed_image('seed-hero.jpg', 'Abstract gradient, navy to blue', [8, 14, 30], [37, 99, 235], 1600, 900);
$about_image = seed_image('seed-about.jpg', 'Abstract gradient, slate to teal', [15, 32, 45], [13, 148, 136], 1200, 900);

$home_id = seed_page('home', 'Home', implode("\n\n", [
    block_cover('Boien Reyes', 'Web developer, IT operations, and automation.', $hero_image, 62),
    block_paragraph(
        'This page is assembled in WordPress and rendered by a React application. '
        . 'Nothing below was written by a developer: an editor picked these blocks from the standard '
        . 'block library, and the front end turned them into typed components.'
    ),
    block_media_text(
        $about_image,
        'What this demonstrates',
        'WordPress stores the content, a small plugin restates it as a typed GraphQL union, '
        . 'and the React front end maps each type to exactly one component. Add a block in the '
        . 'editor and it appears here with no deploy.',
        'left'
    ),
    block_buttons('Get in touch', '/contact'),
    block_unsupported_list(),
]));

$about_id = seed_page('about', 'About', implode("\n\n", [
    block_cover('About', 'Who is behind this, in the briefest possible terms.', $hero_image, 70),
    block_paragraph(
        'A short page, included mainly to prove that publishing is not a developer task. '
        . 'Creating this page meant filling in a title and clicking publish.'
    ),
    block_media_text(
        $about_image,
        'On the seam between the two systems',
        'The interesting part of this exercise is not the blocks themselves but the contract '
        . 'between them: how a block chosen in the editor becomes a component with the right props, '
        . 'and what happens when the content stops matching that contract.',
        'right'
    ),
    '<!-- wp:quote -->' . "\n" . '<blockquote class="wp-block-quote"><p>The front end should not have to guess what it is being given.</p></blockquote>' . "\n" . '<!-- /wp:quote -->',
]));

$contact_id = seed_page('contact', 'Contact', implode("\n\n", [
    block_cover('Contact', 'Say hello.', $hero_image, 74),
    block_paragraph(
        'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Replace this copy in wp-admin '
        . 'and reload the page: the change is live immediately, because the front end reads WordPress '
        . 'on every request rather than being rebuilt.'
    ),
    block_buttons('Email me', 'mailto:mrboyenrey@gmail.com'),
]));

// The front page is a real WordPress page, not a special case in the theme.
update_option('show_on_front', 'page');
update_option('page_on_front', $home_id);

WP_CLI::success('Seeded 3 pages: / (home), /about, /contact.');
WP_CLI::log(sprintf('Page ids - home: %d, about: %d, contact: %d', $home_id, $about_id, $contact_id));
