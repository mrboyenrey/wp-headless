import type { BlockOf } from './schema';

/**
 * HeadingBlock -> a section heading.
 *
 * The tag is chosen from `level` at render time rather than being stored as
 * markup, so the document outline stays this component's decision and a block
 * cannot smuggle an arbitrary element into the page.
 *
 * Levels are clamped to 2-6 on purpose: the hero already owns the page's single
 * <h1>, and a second one is an accessibility fault rather than a styling
 * choice. A heading the editor set to "Level 1" therefore renders as an <h2>.
 *
 * The HTML is injected rather than parsed into React elements, for the same
 * reason as RichText: inline marks (links, emphasis) are the editor's, and the
 * content has already passed WordPress's own KSES sanitisation on save.
 */
const TAGS: Record<number, 'h2' | 'h3' | 'h4' | 'h5' | 'h6'> = {
  2: 'h2',
  3: 'h3',
  4: 'h4',
  5: 'h5',
  6: 'h6',
};

export function Heading({ block }: { block: BlockOf<'HeadingBlock'> }) {
  const level = Math.min(Math.max(block.level, 2), 6);
  const Tag = TAGS[level] ?? 'h2';

  return (
    <section className="mx-auto max-w-3xl px-6 pt-12 pb-2">
      <Tag
        className="font-display text-2xl font-semibold tracking-tight text-white text-balance sm:text-3xl [&_a]:text-brand-400 [&_a]:underline [&_a]:underline-offset-4"
        dangerouslySetInnerHTML={{ __html: block.html }}
      />
    </section>
  );
}
