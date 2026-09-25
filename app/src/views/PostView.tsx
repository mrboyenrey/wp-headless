import type { View } from '../wp/queries';
import { BlockList } from './BlockList';
import { formatDate } from './format';

type Post = Extract<View, { kind: 'post' }>;

/**
 * A blog post: a dated header, its cover image, then its blocks.
 *
 * The body is blocks rather than a blob of HTML, so it goes through the same
 * mapper as a page. That is the point worth demonstrating: the seam is a
 * property of the content model, not of one template.
 */
export function PostView({ view }: { view: Post }) {
  const date = formatDate(view.date);

  return (
    <article>
      <header className="mx-auto max-w-3xl px-6 pb-2 pt-16">
        {date ? (
          <time dateTime={view.date} className="font-mono text-xs uppercase tracking-widest text-brand-400">
            {date}
          </time>
        ) : null}

        <h1 className="mt-3 font-display text-3xl font-semibold tracking-tight text-white text-balance sm:text-4xl">
          {view.title}
        </h1>

        {view.description ? <p className="mt-4 text-lg text-slate-300">{view.description}</p> : null}
      </header>

      {view.image ? (
        <div className="mx-auto max-w-4xl px-6 py-8">
          <img
            src={view.image.url}
            alt={view.image.alt ?? ''}
            width={view.image.width ?? undefined}
            height={view.image.height ?? undefined}
            className="w-full rounded-xl object-cover"
          />
        </div>
      ) : null}

      <BlockList report={view} />
    </article>
  );
}
