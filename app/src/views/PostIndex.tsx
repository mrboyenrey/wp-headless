import type { View } from '../wp/queries';
import { formatDate } from './format';

type PostIndex = Extract<View, { kind: 'postIndex' }>;

/**
 * The blog index.
 *
 * The entries are a list, so the markup is a list: headings and dates in order,
 * each linking to the post itself. The date comes from WordPress rather than
 * from the order of the array, because the order can be changed and the date
 * is a fact about the post.
 *
 * The cover image is a thumbnail rather than a full-width banner, because the
 * index is a reading list and a wall of banners buries the titles. It is wrapped
 * in the same link as the title with `tabIndex={-1}`, so the picture is
 * clickable without becoming a second tab stop on every row.
 */
export function PostIndex({ view }: { view: PostIndex }) {
  return (
    <section className="mx-auto max-w-3xl px-6 py-16">
      <h1 className="font-display text-3xl font-semibold tracking-tight text-white sm:text-4xl">Writing</h1>

      {view.entries.length === 0 ? (
        <p className="mt-6 text-slate-400">
          Nothing published yet. Add a post in wp-admin and it appears here on the next request.
        </p>
      ) : (
        <ul className="mt-10 space-y-12">
          {view.entries.map((entry) => {
            const date = formatDate(entry.meta);

            return (
              <li key={entry.href} className="flex gap-5">
                {entry.image ? (
                  <a href={entry.href} tabIndex={-1} className="shrink-0">
                    <img
                      src={entry.image.url}
                      alt={entry.image.alt ?? ''}
                      width={entry.image.width ?? undefined}
                      height={entry.image.height ?? undefined}
                      loading="lazy"
                      className="h-20 w-32 rounded-lg object-cover transition-opacity hover:opacity-80 sm:h-24 sm:w-40"
                    />
                  </a>
                ) : null}

                <div className="min-w-0">
                  {date ? (
                    <time
                      dateTime={entry.meta ?? undefined}
                      className="font-mono text-xs uppercase tracking-widest text-brand-400"
                    >
                      {date}
                    </time>
                  ) : null}

                  <h2 className="mt-2 font-display text-2xl font-semibold tracking-tight text-white">
                    <a href={entry.href} className="transition-colors hover:text-brand-300">
                      {entry.title}
                    </a>
                  </h2>

                  {entry.description ? (
                    <p className="mt-3 leading-relaxed text-slate-300">{entry.description}</p>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
