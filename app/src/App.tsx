import { SiteFooter } from './components/SiteFooter';
import { SiteHeader } from './components/SiteHeader';
// `import type` is load-bearing, not stylistic: it is what stops the data
// layer (and its `process.env` access) being pulled into the browser bundle.
import type { SiteContent, View } from './wp/queries';
import { BlockList } from './views/BlockList';
import { NotFound } from './views/NotFound';
import { PostIndex } from './views/PostIndex';
import { PostView } from './views/PostView';
import { ServiceIndex } from './views/ServiceIndex';
import { ServiceView } from './views/ServiceView';

/**
 * The site, from validated content to markup.
 *
 * This component is the same code on the server and in the browser; the only
 * difference is that the server uses it to produce a string and the browser
 * uses it to adopt the markup already on screen.
 *
 * The loader decides which view this is, and nothing else does. There is no
 * router library and no list of pages: the loader asks WordPress what lives at
 * the requested path and hands back a `View`, which the switch narrows.
 */
export function App({ content }: { content: SiteContent }) {
  const { navigation, pathname, view } = content;

  return (
    <div className="flex min-h-screen flex-col">
      <SiteHeader navigation={navigation} currentPath={pathname} />

      <main id="main" className="flex-1">
        <ViewSwitch view={view} pathname={pathname} />
      </main>

      <SiteFooter />
    </div>
  );
}

function ViewSwitch({ view, pathname }: { view: View; pathname: string }) {
  switch (view.kind) {
    case 'page':
      return <BlockList report={view} />;

    case 'post':
      return <PostView view={view} />;

    case 'service':
      return <ServiceView view={view} />;

    case 'postIndex':
      return <PostIndex view={view} />;

    case 'serviceIndex':
      return <ServiceIndex view={view} />;

    case 'notFound':
      return <NotFound pathname={pathname} />;

    default: {
      /*
       * The same exhaustiveness check the block renderer uses: assigning to
       * `never` is what makes a seventh route kind a compile error rather than
       * a blank space on a page.
       */
      const unhandled: never = view;
      void unhandled;

      return null;
    }
  }
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
