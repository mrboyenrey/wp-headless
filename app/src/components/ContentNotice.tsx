/**
 * What the page did not manage to render.
 *
 * This panel exists because the interesting failure in a headless setup is not
 * a crash, it is quiet omission: an editor adds a block, the front end has no
 * component for it, and the content simply is not there. Nobody notices for a
 * month.
 *
 * So the two ways content can go missing are reported instead of hidden:
 *
 *   skipped - a block type this front end has no component for. The content is
 *             fine; the library is incomplete. Expected, and worth seeing.
 *   dropped - a block that claimed to be a known type but did not match the
 *             schema. This is the interesting one: it means WordPress and this
 *             application disagree about what a block is.
 *
 * In a real project this would be a build-time check or a CI failure. Rendering
 * it keeps the seam visible while the thing is being built, which is what this
 * exercise is about.
 */
export function ContentNotice({ skipped, dropped }: { skipped: string[]; dropped: string[] }) {
  if (skipped.length === 0 && dropped.length === 0) {
    return null;
  }

  return (
    <aside className="mx-auto max-w-3xl px-6 pb-16">
      <div className="rounded-lg border border-dashed border-slate-700 bg-slate-900/60 p-4 text-sm text-slate-400">
        <p className="font-mono text-xs uppercase tracking-widest text-slate-500">Content notice</p>

        {skipped.length > 0 ? (
          <p className="mt-2">
            {skipped.length} block{skipped.length === 1 ? '' : 's'} in WordPress{' '}
            {skipped.length === 1 ? 'has' : 'have'} no component here:{' '}
            <code className="font-mono text-slate-300">{skipped.join(', ')}</code>. The content is
            intact; this front end has nothing to draw it with.
          </p>
        ) : null}

        {dropped.length > 0 ? (
          <p className="mt-2">
            {dropped.length} block{dropped.length === 1 ? '' : 's'} failed validation and{' '}
            {dropped.length === 1 ? 'was' : 'were'} dropped:{' '}
            <code className="font-mono text-slate-300">{dropped.join(', ')}</code>. WordPress and
            this application disagree about the shape of that block.
          </p>
        ) : null}
      </div>
    </aside>
  );
}
