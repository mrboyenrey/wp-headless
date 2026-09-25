import type { View } from '../wp/queries';

type ServiceIndex = Extract<View, { kind: 'serviceIndex' }>;

/**
 * The services index.
 *
 * Written as a card grid, which is worth noting: the brief's example block
 * library included a "grid of cards", and a card grid turned out to be a view
 * over structured content rather than a block an editor places by hand. The
 * same markup would work as a block if one were wanted; it would just need the
 * same fields to come from somewhere.
 *
 * The price is optional and simply does not render when it is absent, which is
 * the reason the GraphQL field resolves empty meta to null rather than "".
 *
 * The cover image sits at the top of the card. The card has no padding of its
 * own so the image can reach the rounded corners; the padding moved to an inner
 * element, which also carries `flex-1` so the price stays pinned to the bottom
 * of cards whose descriptions differ in length.
 */
export function ServiceIndex({ view }: { view: ServiceIndex }) {
  return (
    <section className="mx-auto max-w-6xl px-6 py-16">
      <h1 className="font-display text-3xl font-semibold tracking-tight text-white sm:text-4xl">Services</h1>

      {view.entries.length === 0 ? (
        <p className="mt-6 text-slate-400">
          Nothing published yet. Add a service in wp-admin and it appears here on the next request.
        </p>
      ) : (
        <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {view.entries.map((entry) => (
            <article
              key={entry.href}
              className="flex flex-col overflow-hidden rounded-xl border border-slate-800 bg-slate-900/50 transition-colors hover:border-brand-700"
            >
              {entry.image ? (
                <a href={entry.href} tabIndex={-1} className="block shrink-0">
                  <img
                    src={entry.image.url}
                    alt={entry.image.alt ?? ''}
                    width={entry.image.width ?? undefined}
                    height={entry.image.height ?? undefined}
                    loading="lazy"
                    className="aspect-[8/5] w-full object-cover transition-opacity hover:opacity-80"
                  />
                </a>
              ) : null}

              <div className="flex flex-1 flex-col p-6">
                {entry.icon ? (
                  <span className="text-2xl leading-none" aria-hidden="true">
                    {entry.icon}
                  </span>
                ) : null}

                <h2 className="mt-4 font-display text-xl font-semibold tracking-tight text-white">
                  <a href={entry.href} className="transition-colors hover:text-brand-300">
                    {entry.title}
                  </a>
                </h2>

                {entry.description ? (
                  <p className="mt-3 flex-1 text-sm leading-relaxed text-slate-400">{entry.description}</p>
                ) : null}

                {entry.meta ? (
                  <p className="mt-5 font-mono text-xs uppercase tracking-widest text-brand-400">{entry.meta}</p>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
