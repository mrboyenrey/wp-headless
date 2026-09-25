<?php
/**
 * Rebuilds the demo content.
 *
 *   docker compose run --rm wpcli eval-file /seed/seed.php
 *
 * The site this seeds is deliberately unremarkable: pages, posts and services
 * made of the block types the front end understands, plus one block type it
 * does not, so the "skipped blocks" behaviour can be seen working rather than
 * merely described.
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

/** Name of the navigation menu this script owns. */
const SEED_MENU = 'Primary';

/* -------------------------------------------------------------------------
 * Housekeeping
 * ---------------------------------------------------------------------- */

/**
 * Remove anything a previous run created.
 */
function seed_purge(): void {
    $ids = get_posts([
        'post_type'   => ['page', 'post', 'service', 'attachment'],
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

    /*
     * The menu is deleted rather than tagged: menu items are posts of their own
     * type, and deleting those directly leaves the menu itself behind as an
     * empty shell that then gets duplicated on the next run.
     */
    $menu = wp_get_nav_menu_object(SEED_MENU);

    if ($menu instanceof WP_Term) {
        wp_delete_nav_menu($menu->term_id);
        WP_CLI::log(sprintf('Deleted the "%s" menu from a previous run.', SEED_MENU));
    }
}

/**
 * Remove the pages WordPress creates on install.
 *
 * They are published, so they would otherwise turn up in the site navigation
 * and make a finished page look unfinished.
 */
function seed_remove_default_content(): void {
    // The pages WordPress creates on install, plus its sample post. All of them
    // are published, so they would otherwise turn up in an index or a menu and
    // make a finished site look unfinished.
    foreach ([['sample-page', 'page'], ['privacy-policy', 'page'], ['hello-world', 'post']] as [$slug, $type]) {
        $post = get_page_by_path($slug, OBJECT, $type);

        if ($post instanceof WP_Post) {
            wp_delete_post($post->ID, true);
            WP_CLI::log(sprintf('Removed the default "%s".', $post->post_title));
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

/**
 * A heading block.
 *
 * The level is written both into the attributes and into the element, because
 * that is what the editor itself does: the parser reads the markup, the editor
 * reads the attribute, and a mismatch between the two would be an invalid block.
 */
function block_heading(string $text, int $level = 2): string {
    return <<<HTML
<!-- wp:heading {"level":{$level}} -->
<h{$level} class="wp-block-heading">{$text}</h{$level}>
<!-- /wp:heading -->
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

/**
 * A page. The excerpt is the field the meta description is taken from, so a page
 * without one falls back to its first paragraph.
 */
function seed_page(string $slug, string $title, string $content, string $excerpt = ''): int {
    return seed_entry('page', $slug, $title, $content, $excerpt === '' ? [] : ['post_excerpt' => $excerpt]);
}

/**
 * Create or update any content type from its slug.
 *
 * Idempotent by slug rather than by id, so re-running updates the entry an
 * editor may since have edited instead of creating a second one beside it.
 *
 * @param array<string, mixed> $extra Extra post fields, e.g. post_excerpt.
 */
function seed_entry(string $post_type, string $slug, string $title, string $content, array $extra = []): int {
    $existing = get_page_by_path($slug, OBJECT, $post_type);

    $data = array_merge([
        'post_type'    => $post_type,
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $content,
        'post_status'  => 'publish',
    ], $extra);

    if ($existing instanceof WP_Post) {
        $data['ID'] = $existing->ID;
        $id         = wp_update_post($data, true);
    } else {
        $id = wp_insert_post($data, true);
    }

    if (is_wp_error($id)) {
        WP_CLI::error(sprintf('Could not save "%s": %s', $title, $id->get_error_message()));
    }

    update_post_meta((int) $id, SEED_META, '1');

    WP_CLI::log(sprintf('Saved %s "%s" at /%s (id %d).', $post_type, $title, $slug, $id));

    return (int) $id;
}

/**
 * A blog post: title, date, cover image, body and excerpt.
 *
 * The body is blocks, not a blob of HTML, so a post is rendered by the same
 * mapper as a page. That is the point worth making: the seam is a property of
 * the content model, not of one template.
 */
function seed_post(string $slug, string $title, array $image, string $excerpt, string $date, string $content): int {
    $id = seed_entry('post', $slug, $title, $content, [
        'post_excerpt' => $excerpt,
        'post_date'    => $date,
    ]);

    set_post_thumbnail($id, $image['id']);

    return $id;
}

/**
 * A service: the page fields plus its own.
 *
 * The meta keys come from the plugin class rather than being repeated as
 * strings here, so the writer and the GraphQL resolver cannot drift apart.
 */
function seed_service(string $slug, string $title, array $image, string $short, string $price, string $icon, string $content): int {
    $id = seed_entry('service', $slug, $title, $content, ['post_excerpt' => $short]);

    set_post_thumbnail($id, $image['id']);
    update_post_meta($id, \HeadlessBlocks\Content_Types::META_SHORT_DESCRIPTION, $short);
    update_post_meta($id, \HeadlessBlocks\Content_Types::META_ICON, $icon);

    // An empty price is meaningful: the field is optional, and leaving it out
    // is how the front end is shown handling a null rather than a blank string.
    if ($price !== '') {
        update_post_meta($id, \HeadlessBlocks\Content_Types::META_PRICE, $price);
    }

    return $id;
}

/**
 * The header navigation, as a real WordPress menu.
 *
 * The brief asks for navigation managed in WordPress rather than written in the
 * code, and a menu is the honest way to do that. Items pointing at WordPress
 * content carry the page id; the two archive routes are custom links because
 * they belong to the front end, not to WordPress.
 *
 * @param array<int, array{label: string, page_id?: int, url?: string}> $items
 */
function seed_menu(array $items): void {
    $menu_id = wp_create_nav_menu(SEED_MENU);

    if (is_wp_error($menu_id)) {
        WP_CLI::error('Could not create the menu: ' . $menu_id->get_error_message());
    }

    foreach ($items as $item) {
        $args = [
            'menu-item-title'  => $item['label'],
            'menu-item-status' => 'publish',
        ];

        if (isset($item['page_id'])) {
            $args['menu-item-object-id'] = $item['page_id'];
            $args['menu-item-object']    = 'page';
            $args['menu-item-type']      = 'post_type';
        } else {
            $args['menu-item-url']  = (string) $item['url'];
            $args['menu-item-type'] = 'custom';
        }

        wp_update_nav_menu_item((int) $menu_id, 0, $args);
    }

    /*
     * Assign it to the location the plugin declares. Without this the menu
     * exists but WPGraphQL will not expose it, and the front end quietly sees
     * no navigation at all.
     */
    set_theme_mod('nav_menu_locations', [\HeadlessBlocks\Content_Types::MENU_LOCATION => (int) $menu_id]);

    WP_CLI::log(sprintf('Created the "%s" menu with %d items.', SEED_MENU, count($items)));
}

/* -------------------------------------------------------------------------
 * Run
 * ---------------------------------------------------------------------- */

seed_purge();
seed_remove_default_content();

update_option('blogname', 'Boien Reyes');
update_option('blogdescription', 'IT operations and web development, remote from the Philippines');

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
$work_image  = seed_image('seed-work.jpg', 'Abstract gradient, deep indigo to cyan', [12, 22, 52], [56, 189, 248], 1200, 900);

$home_id = seed_page('home', 'Home', implode("\n\n", [
    block_cover(
        'Boien Reyes',
        'IT operations and web development, working remotely from the Philippines.',
        $hero_image,
        62
    ),
    block_paragraph(
        'I build websites and I look after the servers they run on. That is an unusual pair of '
        . 'things to claim in one sentence, and it is the reason clients keep hiring me for both: '
        . 'the person who writes the front end is also the person who gets called when the '
        . 'database fills up at the weekend. Ten years of that work has been remote, for teams in '
        . 'Australia, the United States and Canada.'
    ),
    block_heading('What I work on'),
    block_paragraph(
        'Most of it is WordPress: custom builds, block libraries, and the admin experience around '
        . 'them. The rest is the part that WordPress does not cover, which is everything between '
        . 'the repository and a page someone can actually load.'
    ),
    block_media_text(
        $work_image,
        'Two halves of the same job',
        'On the web side: WordPress builds, block libraries shaped around how an editorial team '
        . 'already works, and React and TypeScript front ends that read the CMS over GraphQL. On '
        . 'the operations side: Linux servers, DNS and TLS, Proxmox, backups that have actually '
        . 'been restored rather than merely scheduled, and the automation that removes a manual '
        . 'step nobody should still be doing by hand.',
        'left'
    ),
    block_paragraph(
        'Everything below was written by an editor rather than a developer. These blocks came out '
        . 'of the standard WordPress block library, and the page you are reading was assembled from '
        . 'them and rendered on the server.'
    ),
    block_media_text(
        $about_image,
        'What this demonstrates',
        'WordPress stores the content, a small plugin restates it as a typed GraphQL union, and '
        . 'the React front end maps each type to exactly one component. Add a block in the editor '
        . 'and it appears here with no deploy.',
        'right'
    ),
    block_buttons('Get in touch', '/contact'),
    block_unsupported_list(),
]), 'IT operations and web development from the Philippines, for teams in Australia, the United States and Canada.');

$about_id = seed_page('about', 'About', implode("\n\n", [
    block_cover(
        'About',
        'Ten years of building sites, and being the one who keeps them up.',
        $hero_image,
        70
    ),
    block_paragraph(
        'I am Boien Reyes. I started out assembling computers and wiring up small office networks, '
        . 'moved into front-end development and design through years of freelancing, and then spent '
        . 'most of the decade after that as the web person inside companies that needed one of '
        . 'everything. The through-line is that I have rarely been only the developer or only the '
        . 'person on call. Most of my roles have been both at once, which is why the two halves of '
        . 'this page are not really two pages.'
    ),
    block_heading('How I got here'),
    block_paragraph(
        'A Computer Science degree from Cebu Institute of Technology University, then remote work '
        . 'for teams in Australia, the United States and Canada: Festoon House, Frazer Consultants '
        . 'and RuveneCo, plus Creen Business Management Services closer to home. Each one added a '
        . 'layer, from marketing pages to booking systems to the infrastructure underneath them. '
        . 'The stack grew the same way: PHP and WordPress first, then JavaScript and React, then '
        . 'Docker and the deployment scripts that put it all somewhere other than my laptop.'
    ),
    block_media_text(
        $work_image,
        'Why the operations half matters',
        'A website is a promise that it will still be there tomorrow, and that promise is kept by '
        . 'backups, monitoring, and knowing which cron job to look at first. I have restored a '
        . 'production site from a backup at an hour I would rather not name, and it permanently '
        . 'changed how carefully I write things.',
        'right'
    ),
    block_heading('On the seam between the two systems'),
    block_paragraph(
        'The interesting part of a project like this one is not the blocks themselves but the '
        . 'contract between them: how a block chosen in an editor becomes a component with the '
        . 'right props, and what happens when the content stops matching that contract. That seam '
        . 'tends to fail quietly rather than loudly, which is the argument for building tools that '
        . 'report the mismatch instead of tools that hide it.'
    ),
    '<!-- wp:quote -->' . "\n" . '<blockquote class="wp-block-quote"><p>The front end should not have to guess what it is being given.</p></blockquote>' . "\n" . '<!-- /wp:quote -->',
    block_buttons('Get in touch', '/contact'),
]), 'IT operations and web development, remotely, for teams that need both halves of the job done by one person.');

$contact_id = seed_page('contact', 'Contact', implode("\n\n", [
    block_cover('Contact', 'Say hello.', $hero_image, 74),
    block_paragraph(
        'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Replace this copy in wp-admin '
        . 'and reload the page: the change is live immediately, because the front end reads WordPress '
        . 'on every request rather than being rebuilt.'
    ),
    block_buttons('Email me', 'mailto:mrboyenrey@gmail.com'),
]), 'Say hello, and what you would like to talk about.');

/* -------------------------------------------------------------------------
 * Posts and services
 *
 * The two content types beyond pages. Their bodies are blocks, not blobs of
 * HTML, so a post is rendered by the same mapper as a page: the seam is a
 * property of the content model rather than of one template.
 * ---------------------------------------------------------------------- */

$post_covers = [
    seed_image('seed-post-1.jpg', 'Abstract gradient, indigo', [24, 16, 58], [99, 102, 241], 1200, 800),
    seed_image('seed-post-2.jpg', 'Abstract gradient, emerald', [10, 40, 34], [16, 185, 129], 1200, 800),
    seed_image('seed-post-3.jpg', 'Abstract gradient, amber', [52, 32, 8], [245, 158, 11], 1200, 800),
];

seed_post(
    'reading-wordpress-as-a-database',
    'Reading WordPress as a database',
    $post_covers[0],
    'post_content is not data. It is HTML with the structure hidden in comments, which is the problem this project exists to solve.',
    '2026-09-08 09:00:00',
    implode("\n\n", [
        block_heading('The content is HTML, the structure is a comment'),
        block_paragraph(
            'Handed post_content directly, a front end has two options and both are bad: echo it '
            . 'and give up on components, or scrape it and give up on the CMS. There is a third, '
            . 'which is to read the block tree WordPress has already parsed and republish it as '
            . 'real fields.'
        ),
        block_heading('What that buys', 3),
        block_paragraph(
            'A typed union on the far side, a mapper on this one, and a contract that can be '
            . 'inspected rather than agreed in a wiki page.'
        ),
    ])
);

seed_post(
    'what-a-graphql-union-buys',
    'What a GraphQL union buys you',
    $post_covers[1],
    'One loose block type with every field optional moves the problem to the consumer. A union settles it in the schema.',
    '2026-09-12 09:00:00',
    implode("\n\n", [
        block_heading('Settled by the schema, not by the component'),
        block_paragraph(
            'With a single Block type, every component has to decide for itself which fields it '
            . 'can trust. With a union, that question is already answered by the time the data '
            . 'arrives, and the renderer can prove that every member is handled.'
        ),
        block_paragraph(
            'The union mirrors the discriminated union on the front end member for member, which '
            . 'is what makes the exhaustiveness check in the renderer worth having.'
        ),
    ])
);

seed_post(
    'two-addresses-for-one-container',
    'Two addresses for one container',
    $post_covers[2],
    'The application fetches WordPress over the Docker network, the browser fetches images over localhost. Swap them and every image breaks.',
    '2026-09-18 09:00:00',
    implode("\n\n", [
        block_heading('A server address and a browser address'),
        block_paragraph(
            'Inside the network the application talks to wordpress:80. The browser cannot resolve '
            . 'that name, so images are served from localhost:8080 instead. Being fetched '
            . 'server-side is also why the application never has to think about CORS.'
        ),
        block_paragraph(
            'Getting those two the wrong way round is the classic way to break a containerised '
            . 'server-rendered app: it works locally, where everything is localhost, and then every '
            . 'image 404s once it is containerised.'
        ),
    ])
);

$service_image = seed_image('seed-service.jpg', 'Abstract gradient, rose', [46, 16, 32], [244, 114, 182], 1000, 1000);

seed_service(
    'custom-wordpress-builds',
    'Custom WordPress builds',
    $service_image,
    'Block libraries, content models and admin experiences built around how a team already works.',
    'From 1800',
    '⚙',
    implode("\n\n", [
        block_heading('Built around the editorial workflow'),
        block_paragraph(
            'A block library is a design decision as much as a technical one. The useful question '
            . 'is not how many blocks a site can have but how few an editor can be trusted with.'
        ),
    ])
);

seed_service(
    'headless-front-ends',
    'Headless front ends',
    $service_image,
    'React and TypeScript front ends that read WordPress over GraphQL and render on the server.',
    'From 2400',
    '◈',
    implode("\n\n", [
        block_heading('Content in the CMS, rendering in the application'),
        block_paragraph(
            'Headless is worth its cost when more than one thing needs the content, or when the '
            . 'front end has to do something a theme cannot. Otherwise a theme is not a limitation, '
            . 'it is leverage.'
        ),
    ])
);

seed_service(
    'operations-and-automation',
    'Operations and automation',
    $service_image,
    'Deployment, observability and the unglamorous work that keeps a site up at three in the morning.',
    '',
    '⟳',
    implode("\n\n", [
        block_heading('The part nobody demos'),
        block_paragraph(
            'Reproducible environments, health checks, and knowing what happens when the CMS is '
            . 'unreachable. This is the third service, and it is the one without a price on it, '
            . 'because the field is optional and the front end has to cope with that.'
        ),
    ])
);

/* -------------------------------------------------------------------------
 * Navigation
 * ---------------------------------------------------------------------- */

seed_menu([
    ['label' => 'Home', 'page_id' => $home_id],
    ['label' => 'Blog', 'url' => '/blog'],
    ['label' => 'Services', 'url' => '/services'],
    ['label' => 'About', 'page_id' => $about_id],
    ['label' => 'Contact', 'page_id' => $contact_id],
]);

// The front page is a real WordPress page, not a special case in the theme.
update_option('show_on_front', 'page');
update_option('page_on_front', $home_id);

WP_CLI::success('Seeded 4 pages, 3 posts, 3 services and the navigation menu.');
WP_CLI::log(sprintf('Page ids - home: %d, about: %d, contact: %d', $home_id, $about_id, $contact_id));
