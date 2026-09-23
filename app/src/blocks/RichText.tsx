import type { BlockOf } from './schema';

/**
 * RichTextBlock -> a paragraph of prose.
 *
 * The HTML is injected rather than parsed into React elements. That is a
 * deliberate trade-off: building a safe HTML-to-JSX converter is a project of
 * its own, and the content here is written by an editor in wp-admin and
 * sanitised by WordPress on save. The boundary is WordPress, not the visitor.
 *
 * Because the markup is the editor's, the styling has to be expressed as
 * descendant rules rather than as classes on elements we control.
 */
export function RichText({ block }: { block: BlockOf<'RichTextBlock'> }) {
  return (
    <section className="mx-auto max-w-3xl px-6 py-14">
      <div
        className="space-y-4 text-lg leading-relaxed text-slate-300 [&_a]:text-brand-400 [&_a]:underline [&_a]:underline-offset-4 [&_code]:rounded [&_code]:bg-slate-800 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-base [&_strong]:font-semibold [&_strong]:text-white"
        dangerouslySetInnerHTML={{ __html: block.html }}
      />
    </section>
  );
}
