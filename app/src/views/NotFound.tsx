/**
 * The 404.
 *
 * Rendered with an actual 404 status rather than a 200 carrying the words "not
 * found". A friendly page that claims to exist tells search engines the URL is
 * real, and the brief asks for the status code to be honest.
 */
export function NotFound({ pathname }: { pathname: string }) {
  return (
    <section className="mx-auto max-w-2xl px-6 py-24 text-center">
      <p className="font-mono text-sm uppercase tracking-widest text-brand-400">404</p>

      <h1 className="mt-4 font-display text-3xl font-semibold tracking-tight text-white sm:text-4xl">
        No page here
      </h1>

      <p className="mt-4 text-slate-300">
        WordPress has no published page, post or service at{' '}
        <code className="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-sm text-slate-200">
          {pathname}
        </code>
        . Create one in wp-admin and it will appear here.
      </p>

      <a
        href="/"
        className="mt-8 inline-flex rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-500"
      >
        Back to the front page
      </a>
    </section>
  );
}
