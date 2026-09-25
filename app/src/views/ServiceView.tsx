import type { View } from '../wp/queries';
import { BlockList } from './BlockList';

type Service = Extract<View, { kind: 'service' }>;

/**
 * A service: its own fields as a header, then the same blocks as everything
 * else.
 *
 * Price and icon are nullable on purpose rather than defaulted. An optional
 * price rendered as an empty string would put a stray label on the page, so the
 * absence is handled once here instead of in every caller.
 */
export function ServiceView({ view }: { view: Service }) {
  return (
    <article>
      <header className="mx-auto max-w-3xl px-6 pb-2 pt-16">
        {view.icon ? (
          <span className="block text-3xl leading-none" aria-hidden="true">
            {view.icon}
          </span>
        ) : null}

        <h1 className="mt-4 font-display text-3xl font-semibold tracking-tight text-white text-balance sm:text-4xl">
          {view.title}
        </h1>

        {view.shortDescription ? <p className="mt-4 text-lg text-slate-300">{view.shortDescription}</p> : null}

        {view.price ? (
          <p className="mt-6 font-mono text-sm uppercase tracking-widest text-brand-400">{view.price}</p>
        ) : null}
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
