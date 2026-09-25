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
              className="flex flex-col rounded-xl border border-slate-800 bg-slate-900/50 p-6 transition-colors hover:border-brand-700"
            >
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
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
