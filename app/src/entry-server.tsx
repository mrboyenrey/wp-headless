import { renderToString } from 'react-dom/server';
import { App } from './App';
import { SITE_NAME } from './config';
import { loadSiteContent, type SiteContent, type View } from './wp/queries';

/**
 * The server entry point.
 *
 * Everything that needs the database — or the network — happens here, before a
 * single character is sent to the browser. The result is a complete HTML
 * document, not an empty shell waiting for JavaScript. That matters for two
 * reasons beyond the obvious: the <head> is correct before anything renders
 * (so link previews and crawlers see real content), and the first paint does
 * not wait on a round trip to WordPress from the visitor's machine.
 */

export interface RenderResult {
  status: number;
  /** Markup for the document <head>. */
  head: string;
  /** The rendered application, as HTML. */
  html: string;
  /** The validated data, so the browser can hydrate without re-fetching. */
  content: SiteContent;
}

const FALLBACK_DESCRIPTION =
  'A personal site built on headless WordPress and rendered on the server by React.';

export async function render(pathname: string): Promise<RenderResult> {
  const content = await loadSiteContent(pathname);

  return {
    // A path WordPress does not have is a real 404 with a real status code.
    // Rendering a friendly "not found" page with a 200 is worse than useless:
    // it tells search engines the page exists.
    status: content.view.kind === 'notFound' ? 404 : 200,
    head: buildHead(content),
    html: renderToString(<App content={content} />),
    content,
  };
}

/**
 * Build the document head.
 *
 * This is a capability that only exists because rendering happens on the
 * server: the title, the description and the preview image are all derived
 * from content that the browser has not seen yet.
 */
function buildHead(content: SiteContent): string {
  const { view } = content;

  const tags = [
    `<title>${escapeHtml(pageTitle(view))}</title>`,
    `<meta name="description" content="${escapeHtml(describeView(view))}" />`,
  ];

  const preview = previewImage(view);

  if (preview !== null) {
    tags.push(
      `<meta property="og:image" content="${escapeHtml(preview)}" />`,
      `<meta name="twitter:card" content="summary_large_image" />`,
    );
  }

  return tags.join('\n    ');
}

/**
 * The document title.
 *
 * An archive is not a WordPress entry, so it cannot take its title from one,
 * and a 404 has no title at all. Every other route does, which is why the title
 * stays the CMS's to control rather than the template's.
 */
function pageTitle(view: View): string {
  switch (view.kind) {
    case 'page':
    case 'post':
    case 'service':
      return `${view.title} | ${SITE_NAME}`;

    case 'postIndex':
      return `Writing | ${SITE_NAME}`;

    case 'serviceIndex':
      return `Services | ${SITE_NAME}`;

    case 'notFound':
      return `Page not found | ${SITE_NAME}`;
  }
}

/**
 * The meta description.
 *
 * The excerpt from WordPress when the editor wrote one, and the first paragraph
 * of the body when they did not. That order matters: the field exists so an
 * editor can control what a search result says, and deriving it when they have
 * not filled it in keeps the tag from being empty.
 */
function describeView(view: View): string {
  if (view.kind === 'postIndex') {
    return `Writing from ${SITE_NAME}.`;
  }

  if (view.kind === 'serviceIndex') {
    return `Services offered by ${SITE_NAME}.`;
  }

  if (view.kind === 'notFound') {
    return FALLBACK_DESCRIPTION;
  }

  if (view.description !== null) {
    return clamp(view.description);
  }

  const richText = view.blocks.find((block) => block.__typename === 'RichTextBlock');

  if (richText === undefined || richText.__typename !== 'RichTextBlock') {
    return FALLBACK_DESCRIPTION;
  }

  // Strip tags for the meta tag, and collapse the whitespace the block editor
  // leaves behind.
  return clamp(richText.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim());
}

/** Search results cut off at about 155 characters, so the tag does it first. */
function clamp(text: string): string {
  return text.length > 155 ? `${text.slice(0, 152).trimEnd()}...` : text;
}

/**
 * The image a link preview should use.
 *
 * A post or a service has an image an editor chose, so that is the answer. A
 * page does not, so its hero block is the nearest equivalent. An archive has
 * neither and goes without, rather than borrowing the first card's image and
 * misrepresenting the page.
 */
function previewImage(view: View): string | null {
  if (view.kind === 'post' || view.kind === 'service') {
    return view.image?.url ?? null;
  }

  if (view.kind !== 'page') {
    return null;
  }

  const hero = view.blocks.find((block) => block.__typename === 'HeroBlock');

  return hero !== undefined && hero.__typename === 'HeroBlock' && hero.image !== null ? hero.image.url : null;
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
