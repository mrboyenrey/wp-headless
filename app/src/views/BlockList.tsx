import { BlockRenderer } from '../blocks/BlockRenderer';
import { ContentNotice } from '../components/ContentNotice';
import type { BlockReport } from '../wp/queries';

/**
 * The blocks of one piece of content, plus the report of what did not render.
 *
 * Extracted so pages, posts and services share a single path through the
 * mapper. Duplicating this loop three times is how three content types end up
 * behaving differently, with the fix only landing in one of them.
 */
export function BlockList({ report }: { report: BlockReport }) {
  return (
    <>
      {report.blocks.map((block, index) => (
        /*
          Index keys are usually a smell, but they are correct here and only
          here: the list is fixed for a given render, blocks have no identity of
          their own, and nothing can be reordered underneath React. Using the
          block type as the key would be worse, since a page may legitimately
          contain two paragraphs.
        */
        <BlockRenderer key={index} block={block} />
      ))}

      <ContentNotice skipped={report.skipped} omitted={report.omitted} dropped={report.dropped} />
    </>
  );
}
