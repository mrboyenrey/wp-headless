import { BlockRenderer } from './blocks/BlockRenderer';
import { ContentNotice } from './components/ContentNotice';
import { SiteFooter } from './components/SiteFooter';
import { SiteHeader } from './components/SiteHeader';
// `import type` is load-bearing, not stylistic: it is what stops the data
// layer (and its `process.env` access) being pulled into the browser bundle.
import type { SiteContent } from './wp/queries';

/**
 * The page, from validated content to markup.
 *
 * This component is the same code on the server and in the browser; the only
 * difference is that the server uses it to produce a string and the browser
 * uses it to adopt the markup already on screen.
 */
export function App({ content }: { content: SiteContent }) {
  const { page, navigation, pathname } = content;

  return (
    <div className="flex min-h-screen flex-col">
      <SiteHeader navigation={navigation} currentPath={pathname} />

      <main id="main" className="flex-1">
        {page ? (
          page.blocks.map((block, index) => (
            /*
              Index keys are usually a smell, but they are correct here and
              only here: the list is fixed for a given render, blocks have no
              identity of their own, and nothing can be reordered underneath
              React. Using the block type as the key would be worse, since a
              page may legitimately contain two paragraphs.
            */
            <BlockRenderer key={index} block={block} />
          ))
        ) : (
          <NotFound pathname={pathname} />
        )}

        {page ? (
          <ContentNotice skipped={page.skipped} omitted={page.omitted} dropped={page.dropped} />
        ) : null}
      </main>

      <SiteFooter />
    </div>
  );
}

function NotFound({ pathname }: { pathname: string }) {
  return (
    <section className="mx-auto max-w-2xl px-6 py-24 text-center">
      <p className="font-mono text-sm uppercase tracking-widest text-brand-400">404</p>

      <h1 className="mt-4 font-display text-3xl font-semibold tracking-tight text-white sm:text-4xl">
        No page here
      </h1>

      <p className="mt-4 text-slate-300">
        WordPress has no published page at{' '}
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
