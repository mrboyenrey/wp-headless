import { StrictMode } from 'react';
import { hydrateRoot } from 'react-dom/client';
import { App } from './App';
import './index.css';
// Type-only import: the data layer stays on the server.
import type { SiteContent } from './wp/queries';

/**
 * The browser entry point.
 *
 * `hydrateRoot`, not `createRoot`. The markup is already on screen and correct;
 * hydrating adopts it and attaches event handlers, rather than throwing it away
 * and rendering again. That is the whole payoff of server rendering: the page
 * is interactive without a flash of empty space first.
 *
 * The data comes from the document rather than a second request, which is what
 * guarantees the browser renders exactly what the server rendered. Re-fetching
 * here would introduce a window in which the two could disagree, and React
 * would report a hydration mismatch.
 */

declare global {
  interface Window {
    /** Written into the document by server.js. */
    __SITE_CONTENT__: SiteContent;
  }
}

const container = document.getElementById('root');

if (container === null) {
  throw new Error('No #root element in the document; nothing to hydrate.');
}

hydrateRoot(
  container,
  <StrictMode>
    <App content={window.__SITE_CONTENT__} />
  </StrictMode>,
);
