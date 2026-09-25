import { SITE_NAME, SITE_TAGLINE } from '../config';
import type { NavigationItem } from '../wp/queries';

/**
 * The site header.
 *
 * The navigation is not hardcoded: it comes from a menu an editor maintains in
 * WordPress, fetched on the server. Re-arranging it in wp-admin changes the
 * header with no deploy, which is the point of the exercise.
 */
export function SiteHeader({
  navigation,
  currentPath,
}: {
  navigation: NavigationItem[];
  currentPath: string;
}) {
  return (
    <header className="border-b border-slate-800 bg-slate-950/95 backdrop-blur">
      {/*
        A skip link, visible only once focused. Without it a keyboard user has
        to tab through the whole navigation on every page load.
      */}
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
      >
        Skip to content
      </a>

      <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-8 gap-y-3 px-6 py-4">
        <a href="/" className="group flex items-center gap-3">
          {/*
            Part of the shell rather than a CMS media item, so it ships with the
            application. It is the only photograph on the site, and a personal
            site without a face on it is a strange thing to hand someone.

            `alt` is empty on purpose: the name sits immediately beside it, and
            a screen reader announcing "Boien Reyes" twice in a row is worse
            than not announcing the picture at all.
          */}
          <img
            src="/portrait.png"
            alt=""
            width={36}
            height={36}
            className="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-slate-700 transition-colors group-hover:ring-brand-500"
          />

          <span>
            <span className="font-display text-lg font-semibold tracking-tight text-white">
              {SITE_NAME}
            </span>
            <span className="block text-xs text-slate-400">{SITE_TAGLINE}</span>
          </span>
        </a>

        <nav aria-label="Primary">
          <ul className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            {navigation.map((item) => {
              const isCurrent = item.href === currentPath;

              return (
                <li key={item.href}>
                  <a
                    href={item.href}
                    // aria-current is what tells a screen reader this is the
                    // page you are already on; colour alone would not.
                    aria-current={isCurrent ? 'page' : undefined}
                    className={
                      isCurrent
                        ? 'font-medium text-brand-400'
                        : 'text-slate-300 transition-colors hover:text-white'
                    }
                  >
                    {item.title}
                  </a>
                </li>
              );
            })}
          </ul>
        </nav>
      </div>
    </header>
  );
}
