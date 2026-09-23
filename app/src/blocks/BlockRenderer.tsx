import type { Block } from './schema';
import { CallToAction } from './CallToAction';
import { Hero } from './Hero';
import { ImageText } from './ImageText';
import { RichText } from './RichText';

/**
 * The single place where a block becomes a component.
 *
 * A `switch` rather than a lookup table, because TypeScript narrows a
 * discriminated union inside a switch: in the `'HeroBlock'` case, `block` is
 * known to be a hero and its props are known exactly. A table of components
 * keyed by type name looks tidier, but the call site cannot be typed without a
 * cast, and a cast is precisely the thing that would let a wrong prop slip
 * through unnoticed.
 *
 * The `default` branch is unreachable today. It exists so that adding a member
 * to the block union without handling it here is a compile error rather than a
 * silently blank space on the page.
 */
export function BlockRenderer({ block }: { block: Block }) {
  switch (block.__typename) {
    case 'HeroBlock':
      return <Hero block={block} />;

    case 'RichTextBlock':
      return <RichText block={block} />;

    case 'ImageTextBlock':
      return <ImageText block={block} />;

    case 'CallToActionBlock':
      return <CallToAction block={block} />;

    default: {
      // Assigning to `never` is what performs the exhaustiveness check: if any
      // block type above were missed, `block` would not be `never` and this
      // line would fail to compile.
      const unhandled: never = block;
      void unhandled;

      return null;
    }
  }
}
