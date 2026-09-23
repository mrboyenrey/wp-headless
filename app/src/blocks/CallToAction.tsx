import type { BlockOf } from './schema';

/**
 * CallToActionBlock -> a button.
 *
 * `href` is a plain anchor, not a client-side router link. It has to be: this
 * application is server-rendered per URL, so following a link is a full page
 * load and WordPress owns the routing. Adding a router here would mean
 * duplicating that responsibility for no gain.
 *
 * `rel` is only set when the link opens in a new tab. Modern browsers imply
 * `noopener` for target="_blank", but older ones do not, and the cost of being
 * explicit is nothing.
 */
export function CallToAction({ block }: { block: BlockOf<'CallToActionBlock'> }) {
  const { label, url, opensInNewTab } = block;

  return (
    <section className="mx-auto max-w-3xl px-6 py-10 text-center">
      <a
        href={url}
        target={opensInNewTab ? '_blank' : undefined}
        rel={opensInNewTab ? 'noopener noreferrer' : undefined}
        className="inline-flex items-center justify-center rounded-full bg-brand-600 px-8 py-3 text-base font-semibold text-white transition-colors hover:bg-brand-500 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-400"
      >
        {label}
      </a>
    </section>
  );
}
