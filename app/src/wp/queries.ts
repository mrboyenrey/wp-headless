import { z } from 'zod';
import { blockImageSchema, blockSchema, type Block } from '../blocks/schema';
import { wpQuery } from './client';

/**
 * Loading content: ask WordPress, validate everything, hand back something the
 * components can trust.
 *
 * Four route families, and only four:
 *
 *   /blog       the post index
 *   /services   the service index
 *   anything else is looked up by URI, and WordPress decides what it is
 *
 * The two archive paths are the only addresses this application invents. Every
 * other route is a slug WordPress owns, which is what keeps the router free of
 * a hardcoded list of pages.
 *
 * Three things can be wrong with what WordPress returns, and each is handled
 * differently:
 *
 *   1. The whole response is malformed. That is a server fault: throw, and let
 *      the 500 handler deal with it.
 *   2. One block does not match its type. That block is dropped and reported.
 *      One bad block must not take the page down with it, but it must not be
 *      silently swallowed either.
 *   3. A block is a type this front end has no component for. The plugin
 *      already reports those separately as `skippedBlocks`.
 */

/** The two archive routes, declared once. */
const POST_INDEX_PATH = '/blog';
const SERVICE_INDEX_PATH = '/services';

/**
 * The block selection, shared by every content type that can hold blocks.
 *
 * Written once and interpolated into each query rather than copied three times,
 * because a fourth block type has to reach all of them, or posts quietly start
 * rendering differently from pages.
 */
const BLOCK_SELECTION = /* GraphQL */ `
  contentBlocks {
    __typename
    ... on HeroBlock {
      heading
      subheading
      overlayOpacity
      image { url alt width height }
    }
    ... on HeadingBlock {
      level
      html
    }
    ... on RichTextBlock {
      html
    }
    ... on ImageTextBlock {
      heading
      bodyHtml
      mediaPosition
      image { url alt width height }
    }
    ... on CallToActionBlock {
      label
      url
      opensInNewTab
    }
  }
  skippedBlocks
  omittedBlocks
`;

/**
 * The navigation.
 *
 * Queried alongside the content rather than in a request of its own, so a page
 * costs one round trip to WordPress instead of two. It comes from the menu an
 * editor maintains, not from a list in the code.
 */
const MENU_SELECTION = /* GraphQL */ `
  menus {
    nodes {
      menuItems {
        nodes {
          label
          url
        }
      }
    }
  }
`;

/**
 * The featured image shape, used by posts and services.
 *
 * Dimensions live under mediaDetails rather than on the media item itself, and
 * they are worth the extra hop: they are what lets the card reserve space for
 * the image instead of shifting once it loads.
 */
const IMAGE_SELECTION = /* GraphQL */ `
  featuredImage {
    node {
      sourceUrl
      altText
      mediaDetails { width height }
    }
  }
`;

/** One page, post or service, resolved by the URI WordPress gave it. */
const NODE_QUERY = /* GraphQL */ `
  query Node($uri: String!) {
    ${MENU_SELECTION}

    nodeByUri(uri: $uri) {
      __typename
      ... on Page {
        title
        metaDescription
        ${BLOCK_SELECTION}
      }
      ... on Post {
        title
        metaDescription
        date
        ${IMAGE_SELECTION}
        ${BLOCK_SELECTION}
      }
      ... on Service {
        title
        metaDescription
        shortDescription
        price
        icon
        ${IMAGE_SELECTION}
        ${BLOCK_SELECTION}
      }
    }
  }
`;

const POST_INDEX_QUERY = /* GraphQL */ `
  query PostIndex {
    ${MENU_SELECTION}

    posts(first: 20) {
      nodes {
        title
        uri
        metaDescription
        date
        ${IMAGE_SELECTION}
      }
    }
  }
`;

const SERVICE_INDEX_QUERY = /* GraphQL */ `
  query ServiceIndex {
    ${MENU_SELECTION}

    services(first: 20) {
      nodes {
        title
        uri
        shortDescription
        price
        icon
        ${IMAGE_SELECTION}
      }
    }
  }
`;

/**
 * The envelope, validated loosely on purpose.
 *
 * `contentBlocks` is typed as `unknown[]` because each block is validated
 * against the full union individually. Parsing them as a batch would mean one
 * bad block discards the entire response.
 *
 * The node itself is validated per type, in `readNode`, because the three types
 * carry different fields and a single schema with everything optional is the
 * design this project argues against.
 */
const blockReportFields = {
  contentBlocks: z.array(z.unknown()).default([]),
  skippedBlocks: z.array(z.string()).default([]),
  omittedBlocks: z.array(z.string()).default([]),
};

const navigationSchema = z.object({
  menus: z
    .object({
      nodes: z.array(
        z.object({
          menuItems: z
            .object({ nodes: z.array(z.object({ label: z.string(), url: z.string() })) })
            .nullable(),
        }),
      ),
    })
    .nullable(),
});

const featuredImageSchema = z
  .object({
    node: z
      .object({
        sourceUrl: z.string().min(1),
        altText: z.string().nullable(),
        mediaDetails: z.object({ width: z.number().nullable(), height: z.number().nullable() }).nullable(),
      })
      .nullable(),
  })
  .nullable();

const pageNodeSchema = z.object({
  __typename: z.literal('Page'),
  title: z.string(),
  metaDescription: z.string().nullable(),
  ...blockReportFields,
});

const postNodeSchema = z.object({
  __typename: z.literal('Post'),
  title: z.string(),
  metaDescription: z.string().nullable(),
  date: z.string(),
  featuredImage: featuredImageSchema,
  ...blockReportFields,
});

const serviceNodeSchema = z.object({
  __typename: z.literal('Service'),
  title: z.string(),
  metaDescription: z.string().nullable(),
  shortDescription: z.string().nullable(),
  price: z.string().nullable(),
  icon: z.string().nullable(),
  featuredImage: featuredImageSchema,
  ...blockReportFields,
});

const postSummarySchema = z.object({
  title: z.string(),
  uri: z.string(),
  metaDescription: z.string().nullable(),
  date: z.string(),
  featuredImage: featuredImageSchema,
});

const serviceSummarySchema = z.object({
  title: z.string(),
  uri: z.string(),
  shortDescription: z.string().nullable(),
  price: z.string().nullable(),
  icon: z.string().nullable(),
  featuredImage: featuredImageSchema,
});

export interface NavigationItem {
  title: string;
  href: string;
}

/** The image shape the block components already expect. */
export interface SiteImage {
  url: string;
  alt: string | null;
  width: number | null;
  height: number | null;
}

/**
 * What a page, post or service has to report about its own content.
 *
 * Shared by all three because all three are rendered by the same mapper, so all
 * three can fail in the same three ways.
 */
export interface BlockReport {
  /** Blocks that validated against the schema. The only ones rendered. */
  blocks: Block[];
  /** Block names WordPress sent that have no component here. */
  skipped: string[];
  /**
   * Block names the front end understands, but which had nothing in them to
   * draw - an empty button, a paragraph with no words. The block still looks
   * present in the editor, so this has to be reported or it reads as content
   * destroyed on save.
   */
  omitted: string[];
  /** Block names that failed validation and were discarded. */
  dropped: string[];
}

/** One row of an index: enough to draw a card, and nothing more. */
export interface IndexEntry {
  title: string;
  href: string;
  description: string | null;
  /** The date for a post, the price for a service. */
  meta: string | null;
  icon: string | null;
  image: SiteImage | null;
}

/**
 * What a route resolved to.
 *
 * A discriminated union rather than one type with optional fields, for the same
 * reason the blocks are: `view.kind` narrows the type, so a component that
 * handles a post cannot accidentally read a service's price, and adding a
 * seventh kind is a compile error everywhere it needs handling rather than a
 * blank space at runtime.
 */
export type View =
  | ({ kind: 'page'; title: string; description: string | null } & BlockReport)
  | ({
      kind: 'post';
      title: string;
      description: string | null;
      date: string;
      image: SiteImage | null;
    } & BlockReport)
  | ({
      kind: 'service';
      title: string;
      description: string | null;
      shortDescription: string | null;
      price: string | null;
      icon: string | null;
      image: SiteImage | null;
    } & BlockReport)
  | { kind: 'postIndex'; entries: IndexEntry[] }
  | { kind: 'serviceIndex'; entries: IndexEntry[] }
  | { kind: 'notFound' };

export interface SiteContent {
  /** The path that was requested, so the navigation can mark itself current. */
  pathname: string;
  navigation: NavigationItem[];
  view: View;
}

/**
 * WordPress addresses content by its canonical URI, which always has a leading
 * and trailing slash: "/about/". The browser asks for "/about".
 */
function toWordPressUri(pathname: string): string {
  const trimmed = pathname.replace(/^\/+|\/+$/g, '');

  return trimmed === '' ? '/' : `/${trimmed}/`;
}

/** The reverse direction, for links: "/about/" is reached at "/about". */
function toHref(uri: string): string {
  const trimmed = uri.replace(/\/+$/g, '');

  return trimmed === '' ? '/' : trimmed;
}

/**
 * A menu link, as a path this application can serve.
 *
 * WordPress hands back absolute URLs for links to its own content, and bare
 * paths for custom links. The front end only ever wants the path, because it is
 * served from a different origin to WordPress.
 */
function menuUrlToHref(url: string): string {
  let path = url;

  try {
    path = new URL(url).pathname;
  } catch {
    // Already a path, which is what a custom link looks like.
  }

  return toHref(path);
}

/**
 * An excerpt is rendered HTML. Titles and meta tags want plain text.
 *
 * Accepts undefined as well as null on purpose. This sits on the boundary with
 * another system, and the first version crashed the services index with a
 * `replace` of undefined because a field the query had not asked for arrives as
 * undefined rather than as null. Degrading to no description is the right
 * failure here; taking the page down is not.
 */
function textOf(html: string | null | undefined): string | null {
  if (!html) {
    return null;
  }

  const text = html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

  return text === '' ? null : text;
}

/** The image shape the block components expect, from what GraphQL returns. */
function toSiteImage(raw: z.infer<typeof featuredImageSchema>): SiteImage | null {
  const node = raw?.node;

  if (!node) {
    return null;
  }

  return {
    url: node.sourceUrl,
    alt: node.altText,
    width: node.mediaDetails?.width ?? null,
    height: node.mediaDetails?.height ?? null,
  };
}

function describeBlock(raw: unknown): string {
  if (typeof raw === 'object' && raw !== null && '__typename' in raw) {
    const typename = (raw as { __typename?: unknown }).__typename;

    if (typeof typename === 'string') {
      return typename;
    }
  }

  return 'unrecognised block';
}

/**
 * Turn what WordPress sent into validated blocks and a report of what did not
 * make it.
 */
function readBlockReport(raw: {
  contentBlocks: unknown[];
  skippedBlocks: string[];
  omittedBlocks: string[];
}): BlockReport {
  const blocks: Block[] = [];
  const dropped: string[] = [];

  for (const item of raw.contentBlocks) {
    const result = blockSchema.safeParse(item);

    if (result.success) {
      blocks.push(result.data);
    } else {
      dropped.push(describeBlock(item));
    }
  }

  if (dropped.length > 0) {
    // Reported as well as surfaced in the UI, because a page that renders but
    // is quietly missing a section is the hardest kind of bug to notice.
    console.warn(`[content] dropped ${dropped.length} block(s) that failed validation: ${dropped.join(', ')}`);
  }

  if (raw.omittedBlocks.length > 0) {
    console.warn(
      `[content] ${raw.omittedBlocks.length} block(s) had nothing to render: ${raw.omittedBlocks.join(', ')}`,
    );
  }

  return { blocks, skipped: raw.skippedBlocks, omitted: raw.omittedBlocks, dropped };
}

/**
 * The navigation, from the WordPress menu.
 *
 * A missing or unreadable menu is not fatal: the page still renders, it is just
 * unlinked. Taking the whole site down because an editor re-arranged the menu
 * would be a worse failure than having no menu.
 */
function readNavigation(data: unknown): NavigationItem[] {
  const parsed = navigationSchema.safeParse(data);

  if (!parsed.success) {
    console.warn(`[content] could not read the navigation menu: ${parsed.error.message}`);

    return [];
  }

  return (parsed.data.menus?.nodes ?? [])
    .flatMap((menu) => menu.menuItems?.nodes ?? [])
    .map((item) => ({ title: item.label, href: menuUrlToHref(item.url) }));
}

/**
 * Resolve a single item of content.
 *
 * Returns null when the URI is not one of the three types this front end
 * renders. That is not an error: WordPress may legitimately have a taxonomy or
 * an attachment at that address, and the honest answer is a 404.
 */
function readNode(data: unknown): View | null {
  const node = (data as { nodeByUri?: unknown }).nodeByUri;

  if (typeof node !== 'object' || node === null) {
    return null;
  }

  const typename = (node as { __typename?: unknown }).__typename;

  if (typename === 'Page') {
    const parsed = pageNodeSchema.safeParse(node);

    if (!parsed.success) {
      throw new Error(`WordPress sent a Page this front end cannot read: ${parsed.error.message}`);
    }

    return {
      kind: 'page',
      title: parsed.data.title,
      description: textOf(parsed.data.metaDescription),
      ...readBlockReport(parsed.data),
    };
  }

  if (typename === 'Post') {
    const parsed = postNodeSchema.safeParse(node);

    if (!parsed.success) {
      throw new Error(`WordPress sent a Post this front end cannot read: ${parsed.error.message}`);
    }

    return {
      kind: 'post',
      title: parsed.data.title,
      description: textOf(parsed.data.metaDescription),
      date: parsed.data.date,
      image: toSiteImage(parsed.data.featuredImage),
      ...readBlockReport(parsed.data),
    };
  }

  if (typename === 'Service') {
    const parsed = serviceNodeSchema.safeParse(node);

    if (!parsed.success) {
      throw new Error(`WordPress sent a Service this front end cannot read: ${parsed.error.message}`);
    }

    return {
      kind: 'service',
      title: parsed.data.title,
      description: textOf(parsed.data.metaDescription),
      shortDescription: parsed.data.shortDescription,
      price: parsed.data.price,
      icon: parsed.data.icon,
      image: toSiteImage(parsed.data.featuredImage),
      ...readBlockReport(parsed.data),
    };
  }

  return null;
}

function readIndex(data: unknown, kind: 'post' | 'service'): IndexEntry[] {
  const raw = (
    data as { posts?: { nodes?: unknown[] }; services?: { nodes?: unknown[] } }
  );

  const nodes = (kind === 'post' ? raw.posts?.nodes : raw.services?.nodes) ?? [];
  const entries: IndexEntry[] = [];

  for (const node of nodes) {
    const parsed = kind === 'post' ? postSummarySchema.safeParse(node) : serviceSummarySchema.safeParse(node);

    if (!parsed.success) {
      console.warn(`[content] skipped an unreadable ${kind} in the index: ${parsed.error.message}`);

      continue;
    }

    const entry = parsed.data as z.infer<typeof postSummarySchema> & z.infer<typeof serviceSummarySchema>;

    entries.push({
      title: entry.title,
      href: toHref(entry.uri),
      /*
       * A post's card shows the description an editor wrote, a service's shows
       * its short description. Each index queries only the fields it needs, so
       * reading the other kind's field would yield undefined rather than null.
       */
      description: kind === 'post' ? textOf(entry.metaDescription) : entry.shortDescription,
      meta: kind === 'post' ? entry.date : entry.price,
      icon: entry.icon ?? null,
      image: toSiteImage(entry.featuredImage),
    });
  }

  return entries;
}

export async function loadSiteContent(pathname: string): Promise<SiteContent> {
  const path = toHref(pathname) || '/';

  if (path === POST_INDEX_PATH || path === SERVICE_INDEX_PATH) {
    const kind = path === POST_INDEX_PATH ? 'post' : 'service';
    const data = await wpQuery<unknown>(kind === 'post' ? POST_INDEX_QUERY : SERVICE_INDEX_QUERY);

    return {
      pathname,
      navigation: readNavigation(data),
      view: kind === 'post'
        ? { kind: 'postIndex', entries: readIndex(data, 'post') }
        : { kind: 'serviceIndex', entries: readIndex(data, 'service') },
    };
  }

  const data = await wpQuery<unknown>(NODE_QUERY, { uri: toWordPressUri(path) });
  const node = readNode(data);

  return {
    pathname,
    navigation: readNavigation(data),
    view: node ?? { kind: 'notFound' },
  };
}
