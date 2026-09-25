import { z } from 'zod';

/**
 * THE CONTRACT BETWEEN WORDPRESS AND THIS APPLICATION.
 *
 * WordPress sends JSON over GraphQL. JSON has no types, and the schema in
 * WordPress can drift from the components here without either side noticing:
 * a block can gain a field, lose one, or arrive with a value nobody expected.
 * So the data is not trusted. It is parsed, once, at the boundary, and only
 * what survives is allowed to reach a component.
 *
 * Each block type is declared separately and then joined with
 * `discriminatedUnion`. That choice is what makes everything downstream
 * exhaustive: given a `Block`, switching on `__typename` narrows it to exactly
 * one set of props, and TypeScript will refuse to compile a renderer that has
 * forgotten one of them.
 *
 * ADDING A BLOCK TYPE
 * -------------------
 * 1. Register the type in the WordPress plugin (class-schema.php).
 * 2. Add it to `blockSchema` below.
 * 3. Add a component and a case in BlockRenderer.tsx.
 *
 * Step 3 is enforced by the compiler, not by discipline: the `never` guard in
 * the renderer fails to compile until every member of the union is handled.
 */

/**
 * An image, as the WordPress plugin reports one.
 *
 * `alt` is nullable because WordPress genuinely stores an empty string for
 * images the editor never described, and pretending otherwise would mean
 * inventing alt text. `width`/`height` are nullable because an image that was
 * pasted in by URL was never uploaded, so no dimensions exist for it. They are
 * passed to the <img> when present, which is what stops the layout shifting as
 * images load.
 */
export const blockImageSchema = z.object({
  url: z.string().min(1),
  alt: z.string().nullable(),
  width: z.number().int().positive().nullable(),
  height: z.number().int().positive().nullable(),
});

/** A full-width cover, used as the page hero. */
export const heroBlockSchema = z.object({
  __typename: z.literal('HeroBlock'),
  heading: z.string().min(1),
  subheading: z.string().nullable(),
  /** Scrim strength over the background image, straight from the block's dimRatio. */
  overlayOpacity: z.number().min(0).max(100),
  image: blockImageSchema.nullable(),
});

/**
 * A paragraph of rich text.
 *
 * The HTML is kept rather than flattened to text so bold, italic and links
 * survive the round trip. It is rendered with dangerouslySetInnerHTML, which
 * is safe only because this content is authored by a logged-in editor and has
 * already passed WordPress's own KSES sanitisation on save. It is a trust
 * boundary worth being explicit about: nothing user-submitted ever reaches it.
 */
export const richTextBlockSchema = z.object({
  __typename: z.literal('RichTextBlock'),
  html: z.string().min(1),
});

/** An image paired with a block of text. */
export const imageTextBlockSchema = z.object({
  __typename: z.literal('ImageTextBlock'),
  heading: z.string().nullable(),
  bodyHtml: z.string().nullable(),
  /**
   * An enum rather than a string. WordPress stores this as free text; narrowing
   * it here means the component receives one of two known values and never has
   * to defend against a third.
   */
  mediaPosition: z.enum(['left', 'right']),
  image: blockImageSchema.nullable(),
});

/**
 * A standalone heading.
 *
 * `level` is narrowed to the six values HTML actually has, so the component
 * picks a tag without needing a fallback branch. `html` holds only the inline
 * markup: the wrapper tag is the component's decision, which is what stops the
 * heading being nested inside a second heading.
 */
export const headingBlockSchema = z.object({
  __typename: z.literal('HeadingBlock'),
  level: z.number().int().min(1).max(6),
  html: z.string().min(1),
});

/** A call-to-action button. */
export const callToActionBlockSchema = z.object({
  __typename: z.literal('CallToActionBlock'),
  label: z.string().min(1),
  url: z.string().min(1),
  opensInNewTab: z.boolean(),
});

/**
 * The block library, as the front end understands it.
 *
 * The order here is not meaningful; the discriminant is `__typename`.
 */
export const blockSchema = z.discriminatedUnion('__typename', [
  heroBlockSchema,
  headingBlockSchema,
  richTextBlockSchema,
  imageTextBlockSchema,
  callToActionBlockSchema,
]);

/** A fully validated block. The only kind of block a component ever receives. */
export type Block = z.infer<typeof blockSchema>;

/** The set of block type names, e.g. 'HeroBlock' | 'RichTextBlock' | ... */
export type BlockType = Block['__typename'];

/**
 * Look up one member of the union by its type name.
 *
 * This is what lets a component say "give me the hero block" and get the
 * hero's props, rather than the union's, without a cast.
 */
export type BlockOf<T extends BlockType> = Extract<Block, { __typename: T }>;
