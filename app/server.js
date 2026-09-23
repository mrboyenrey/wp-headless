/**
 * The server.
 *
 * Deliberately plain Node `http`, with no framework. There are only four things
 * it has to do, and each is a couple of lines:
 *
 *   development  - hand most requests to Vite, and render documents with the
 *                  module loaded fresh, so editing a component is picked up
 *                  without a restart.
 *   production   - serve the built assets from disk and render documents with
 *                  the built SSR bundle.
 *   rendering    - fill the placeholders in index.html with the head, the
 *                  markup and the serialised data.
 *   errors       - a failed render is a 500 with a log line, never a blank page
 *                  and never a collapsed server.
 *
 * Two scripts, one file: `npm run dev` and `npm start`.
 */

import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const port = Number(process.env.PORT ?? 3000);
const isProduction = process.argv.includes('--prod') || process.env.NODE_ENV === 'production';

/**
 * Only these are ever read from disk. Everything else is treated as a page
 * route, which is what stops the un-rendered index.html from being served by
 * accident.
 */
const STATIC_PREFIXES = ['/assets/'];
const STATIC_FILES = ['/favicon.ico'];

const CONTENT_TYPES = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
};

/** Set to a Vite dev server in development, left null in production. */
let vite = null;

/**
 * Vite's middleware stack is a connect app: it either answers the request or
 * calls `next()`. This wraps that callback style in a promise so the caller can
 * tell which of the two happened.
 */
function runViteMiddleware(middleware, request, response) {
  return new Promise((resolve) => {
    let settled = false;

    const settle = (value) => {
      if (!settled) {
        settled = true;
        resolve(value);
      }
    };

    // Vite answered it: a module, a stylesheet, an asset, the HMR client.
    response.on('finish', () => settle(true));

    // Vite declined it, so it is ours to render.
    middleware(request, response, () => settle(false));
  });
}

async function serveStatic(pathname, response) {
  const allowed =
    STATIC_PREFIXES.some((prefix) => pathname.startsWith(prefix)) ||
    STATIC_FILES.includes(pathname);

  if (!allowed) {
    return false;
  }

  const clientRoot = path.join(root, 'dist', 'client');
  const filePath = path.join(clientRoot, pathname);

  // A request for something like `/assets/../../server.js` must not escape.
  if (!filePath.startsWith(clientRoot)) {
    return false;
  }

  try {
    const body = await fs.readFile(filePath);

    response.statusCode = 200;
    response.setHeader(
      'Content-Type',
      CONTENT_TYPES[path.extname(filePath)] ?? 'application/octet-stream',
    );
    response.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
    response.end(body);

    return true;
  } catch {
    // Not a file. Fall through so the app can decide whether it is a page.
    return false;
  }
}

/**
 * Serialise the page data for hydration.
 *
 * The `<` escape is not cosmetic: a string containing `</script>` would close
 * the tag early and break the document. Escaping the angle bracket keeps the
 * payload valid JSON while making that impossible.
 */
function serialiseForDocument(value) {
  return JSON.stringify(value).replace(/</g, '\\u003c');
}

async function renderDocument(url, pathname, response) {
  let template;
  let render;

  if (vite !== null) {
    template = await fs.readFile(path.join(root, 'index.html'), 'utf8');
    // Rewrites the template for development: injects the HMR client and
    // resolves the module entry, so the document is the same shape in both
    // modes and differences show up now rather than at deploy time.
    template = await vite.transformIndexHtml(url, template);

    /*
     * Give development a real stylesheet.
     *
     * Vite hands CSS to the browser as a JavaScript module in development, so
     * by default the document contains no stylesheet at all and the page is
     * completely unstyled until that module executes. For an application whose
     * whole premise is that the HTML is finished before JavaScript, that is the
     * wrong default: any script error, cache miss or slow connection shows the
     * visitor bare HTML, and there is a flash of unstyled content on every
     * load.
     *
     * `?direct` asks Vite for the compiled CSS rather than the module wrapper,
     * so the stylesheet is in the document and applies on first paint. The
     * module still loads for hot reloading, so nothing is lost.
     */
    template = template.replace(
      '</head>',
      '    <link rel="stylesheet" href="/src/index.css?direct" />\n  </head>',
    );

    ({ render } = await vite.ssrLoadModule('/src/entry-server.tsx'));
  } else {
    template = await fs.readFile(path.join(root, 'dist', 'client', 'index.html'), 'utf8');
    ({ render } = await import('./dist/server/entry-server.js'));
  }

  const result = await render(pathname);

  const document = template
    .replace('<!--ssr-head-->', result.head)
    .replace('<!--ssr-html-->', result.html)
    .replace(
      '<!--ssr-data-->',
      `<script>window.__SITE_CONTENT__=${serialiseForDocument(result.content)}</script>`,
    );

  response.statusCode = result.status;
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  response.end(document);
}

const server = http.createServer(async (request, response) => {
  const url = request.url ?? '/';

  try {
    const pathname = decodeURIComponent(new URL(url, 'http://localhost').pathname);

    if (vite !== null) {
      if (await runViteMiddleware(vite.middlewares, request, response)) {
        return;
      }
    } else if (await serveStatic(pathname, response)) {
      return;
    }

    await renderDocument(url, pathname, response);
  } catch (error) {
    if (vite !== null && error instanceof Error) {
      // Points stack frames at source files rather than the transformed
      // modules Vite is serving, which is the difference between a usable and
      // an unusable error in development.
      vite.ssrFixStacktrace(error);
    }

    console.error(`[ssr] ${url} could not be rendered:`, error);

    response.statusCode = 500;
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end('<h1>500</h1><p>This page could not be rendered. See the server log.</p>');
  }
});

if (!isProduction) {
  const { createServer } = await import('vite');

  vite = await createServer({
    root,
    // 'custom' stops Vite serving index.html itself, so every document goes
    // through our renderer.
    appType: 'custom',
    server: { middlewareMode: true },
  });
}

/*
 * A port clash is the single most likely way this process fails to start, and
 * Node's default behaviour is to throw an unhandled 'error' event and dump a
 * stack trace naming neither the cause nor the fix. Since the usual cause is
 * an earlier copy of this same server still running, say so.
 */
server.on('error', (error) => {
  if (error.code === 'EADDRINUSE') {
    console.error(`[ssr] port ${port} is already in use.`);
    console.error('[ssr] another server is listening there - stop it and try again,');
    console.error(`[ssr] or start this one elsewhere with:  PORT=3001 npm run dev`);
    process.exit(1);
  }

  throw error;
});

server.listen(port, () => {
  console.log(`[ssr] ${isProduction ? 'production' : 'development'} server listening on http://localhost:${port}`);
  console.log(`[ssr] content source: ${process.env.WP_GRAPHQL_URL ?? 'http://localhost:8080/graphql (default)'}`);
});
