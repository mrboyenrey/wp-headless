import { z } from 'zod';
import { blockSchema, type Block } from '../blocks/schema';
import { wpQuery } from './client';

/**
 * Loading a page: query WordPress, validate everything, hand back something the
 * components can trust.
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

/**
 * The query.
 *
 * Note what this asks for and what it does not. It never asks for `content`,
 * WordPress's blob of rendered HTML: the whole point of the plugin is that the
 * page arrives as structure. The inline fragments are how a GraphQL union is
 * queried, and they mirror the TypeScript discriminated union member for member.
 */
const PAGE_QUERY = /* GraphQL */ `
  query PageContent($uri: ID!) {
    page(id: $uri, idType: URI) {
      title
      skippedBlocks
      contentBlocks {
        __typename
        ... on HeroBlock {
          heading
          subheading
          overlayOpacity
          image {
            url
            alt
            width
            height
          }
        }
        ... on RichTextBlock {
          html
        }
        ... on ImageTextBlock {
          heading
          bodyHtml
          mediaPosition
          image {
            url
            alt
            width
            height
          }
        }
        ... on CallToActionBlock {
          label
          url
          opensInNewTab
        }
      }
    }

    pages(first: 50) {
      nodes {
        title
        uri
      }
    }
  }
`;

/**
 * The envelope, validated loosely on purpose.
 *
 * `contentBlocks` is typed as `unknown[]` here because each block is validated
 * against the full union individually. Parsing them as a batch would mean one
 * bad block discards the entire response.
 */
const responseSchema = z.object({
  page: z
    .object({
      title: z.string(),
      skippedBlocks: z.array(z.string()).default([]),
      contentBlocks: z.array(z.unknown()).default([]),
    })
    .nullable(),
  pages: z
    .object({
      nodes: z.array(z.object({ title: z.string(), uri: z.string() })),
    })
    .nullable(),
});

export interface NavigationItem {
  title: string;
  href: string;
}

export interface Page {
  title: string;
  /** Blocks that validated against the schema. The only ones rendered. */
  blocks: Block[];
  /** Block names WordPress sent that have no component here. */
  skipped: string[];
  /** Block names that failed validation and were discarded. */
  dropped: string[];
}

export interface SiteContent {
  /** The path that was requested, so the navigation can mark itself current. */
  pathname: string;
  navigation: NavigationItem[];
  /** Null when WordPress has no published page at this path. */
  page: Page | null;
}

/**
 * WordPress addresses a page by its canonical URI, which always has a leading
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

function describeBlock(raw: unknown): string {
  if (typeof raw === 'object' && raw !== null && '__typename' in raw) {
    const typename = (raw as { __typename?: unknown }).__typename;

    if (typeof typename === 'string') {
      return typename;
    }
  }

  return 'unrecognised block';
}

export async function loadSiteContent(pathname: string): Promise<SiteContent> {
  const data = await wpQuery<unknown>(PAGE_QUERY, { uri: toWordPressUri(pathname) });
  const parsed = responseSchema.safeParse(data);

  if (!parsed.success) {
    throw new Error(`WordPress returned an unexpected response shape: ${parsed.error.message}`);
  }

  const { page, pages } = parsed.data;

  const navigation = (pages?.nodes ?? [])
    .map((node) => ({ title: node.title, href: toHref(node.uri) }))
    // WordPress reports the front page twice under some permalink setups: once
    // as "/" and once under its own slug. Dedupe by the link we would render.
    .filter((item, index, all) => all.findIndex((other) => other.href === item.href) === index);

  if (page === null) {
    return { pathname, navigation, page: null };
  }

  const blocks: Block[] = [];
  const dropped: string[] = [];

  for (const raw of page.contentBlocks) {
    const result = blockSchema.safeParse(raw);

    if (result.success) {
      blocks.push(result.data);
    } else {
      dropped.push(describeBlock(raw));
    }
  }

  if (dropped.length > 0) {
    // Reported as well as surfaced in the UI, because a page that renders but
    // is quietly missing a section is the hardest kind of bug to notice.
    console.warn(`[content] dropped ${dropped.length} block(s) that failed validation: ${dropped.join(', ')}`);
  }

  return {
    pathname,
    navigation,
    page: {
      title: page.title,
      blocks,
      skipped: page.skippedBlocks,
      dropped,
    },
  };
}
