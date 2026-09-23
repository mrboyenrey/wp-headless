import { renderToString } from 'react-dom/server';
import { App } from './App';
import { SITE_NAME } from './config';
import { loadSiteContent, type SiteContent } from './wp/queries';

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
    status: content.page ? 200 : 404,
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
  const title = content.page
    ? `${content.page.title} | ${SITE_NAME}`
    : `Page not found | ${SITE_NAME}`;

  const tags = [
    `<title>${escapeHtml(title)}</title>`,
    `<meta name="description" content="${escapeHtml(describePage(content))}" />`,
  ];

  const heroImage = firstHeroImage(content);

  if (heroImage !== null) {
    tags.push(
      `<meta property="og:image" content="${escapeHtml(heroImage)}" />`,
      `<meta name="twitter:card" content="summary_large_image" />`,
    );
  }

  return tags.join('\n    ');
}

/**
 * Derive a meta description from the page's own prose.
 *
 * WordPress has no dedicated field for this, and asking an editor to maintain
 * one separately from the copy is how descriptions end up stale. The first
 * paragraph is already a fair summary, so it is used directly.
 */
function describePage(content: SiteContent): string {
  const richText = content.page?.blocks.find((block) => block.__typename === 'RichTextBlock');

  if (richText === undefined || richText.__typename !== 'RichTextBlock') {
    return FALLBACK_DESCRIPTION;
  }

  // Strip tags for the meta tag, and collapse the whitespace the block editor
  // leaves behind.
  const text = richText.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

  return text.length > 155 ? `${text.slice(0, 152).trimEnd()}...` : text;
}

function firstHeroImage(content: SiteContent): string | null {
  const hero = content.page?.blocks.find((block) => block.__typename === 'HeroBlock');

  return hero !== undefined && hero.__typename === 'HeroBlock' && hero.image !== null
    ? hero.image.url
    : null;
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
