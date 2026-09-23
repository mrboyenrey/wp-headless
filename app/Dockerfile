# The React SSR application.
#
# Two stages, because the things needed to BUILD the app are not the things
# needed to RUN it. Vite, Tailwind and TypeScript are several hundred megabytes
# of tooling that has no business in a production image; only the compiled
# output and the three runtime dependencies ship.

# ---------------------------------------------------------------- build ----
FROM node:22-alpine AS build

WORKDIR /app

# Manifests copied first, on their own. Docker caches this layer, so editing a
# component does not trigger a fresh dependency install - only editing the
# manifests does.
COPY package.json package-lock.json ./

RUN npm ci --no-audit --no-fund

COPY . .

# Produces dist/client (browser bundle) and dist/server (SSR bundle).
RUN npm run build

# -------------------------------------------------------------- runtime ----
FROM node:22-alpine AS runtime

WORKDIR /app

ENV NODE_ENV=production

COPY package.json package-lock.json ./

# Runtime dependencies only: react, react-dom and zod. Nothing else is needed,
# because the SSR bundle resolves those from node_modules at run time.
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force

# The compiled output, and the server that serves it. server.js reads its
# template from dist/client/index.html, so both halves of dist must come across.
COPY --from=build /app/dist ./dist
COPY --from=build /app/server.js ./server.js

# Run as the image's built-in unprivileged user rather than root.
USER node

EXPOSE 3000

# --prod tells server.js to serve the built output instead of starting Vite.
CMD ["node", "server.js", "--prod"]
