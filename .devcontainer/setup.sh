#!/usr/bin/env bash
#
# Brings the whole project up from nothing, in one command.
#
# Run automatically when a GitHub Codespace is created (see devcontainer.json).
# Safe to run by hand locally too, and safe to run twice: it will not reinstall
# WordPress if it is already there, and the seed script rebuilds its own content
# from scratch.
#
#     bash .devcontainer/setup.sh
#
# THE ONE NON-OBVIOUS PART
# -----------------------
# WordPress has to advertise its *public* address, not localhost.
#
# A Codespace forwards ports to <name>-<port>.app.github.dev. When the page is
# viewed through that address, the visitor's browser resolves `localhost` to
# their own machine - not to this one. If WordPress believes it lives at
# http://localhost:8080, every image URL it emits points at a machine with
# nothing on port 8080, and the site renders with broken images.
#
# So WP_URL is set to the forwarded https address before WordPress is
# installed, and the install and the seed content inherit it.

set -euo pipefail

# Git Bash on Windows rewrites arguments that look like Unix paths before Docker
# ever sees them, so `/var/www/html/wp-config.php` arrives inside the container
# as `C:/Program Files/Git/var/www/html/wp-config.php`. Any check against a path
# inside a container then fails - silently, and forever, if it is inside a wait
# loop. Exporting this disables the rewriting. It is ignored on Linux, macOS and
# in a Codespace, so it is safe to set unconditionally.
export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/.."

# Codespaces sets both of these. Anywhere else they are absent and we fall back.
if [ -n "${CODESPACE_NAME:-}" ] && [ -n "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]; then
  WP_URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
  echo "Codespace detected - WordPress will publish at ${WP_URL}"
else
  WP_URL="http://localhost:8080"
  echo "No Codespace detected - using ${WP_URL}"
fi

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

if grep -q '^WP_URL=' .env; then
  sed -i "s|^WP_URL=.*|WP_URL=${WP_URL}|" .env
else
  echo "WP_URL=${WP_URL}" >> .env
fi

echo "Starting containers and building the application image..."
# --build so the image reflects the current source. The application compiles
# itself inside the image, which is why no Node installation is needed on this
# machine - the whole project runs in Docker.
docker compose up -d --build

# Wait on the healthcheck rather than on a fixed sleep, so this works on a slow
# machine and does not waste a minute on a fast one.
echo "Waiting for MySQL to become healthy..."
until [ "$(docker inspect --format '{{.State.Health.Status}}' wph-db 2>/dev/null || echo none)" = "healthy" ]; do
  sleep 2
done

# The CLI container needs wp-config.php, which the WordPress container writes on
# first start. A short wait avoids racing it.
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; do
  sleep 1
done

if docker compose run --rm --no-deps wpcli core is-installed >/dev/null 2>&1; then
  echo "WordPress is already installed - refreshing plugins and content only."
else
  echo "Installing WordPress..."
  docker compose run --rm --no-deps wpcli core install \
    --url="${WP_URL}" \
    --title="Boien Reyes" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.com \
    --skip-email
fi

echo "Installing plugins..."
docker compose run --rm wpcli plugin install wp-graphql --activate
docker compose run --rm wpcli plugin activate headless-blocks

echo "Seeding demo content..."
docker compose run --rm wpcli eval-file /seed/seed.php

echo "Waiting for WordPress to answer..."
#
# Bounded on purpose. An `until` loop around a network check with no attempt
# limit hangs forever the moment the check is wrong - which is exactly what the
# first version of this script did. A setup script should fail loudly, not sit
# there looking busy.
#
# Note the redirect: `> /dev/null` is shell redirection, handled by bash. It must
# NOT be written as curl's `-o /dev/null`, because MSYS_NO_PATHCONV=1 is set
# above and that is the very thing which stops Git Bash translating `/dev/null`
# into the Windows null device. As a curl argument it arrives literally and curl
# fails on every attempt.
#
# There is also no `curl -f` here: the question is only whether the server is up
# and routing, so any HTTP response counts.
attempts=0
until curl -s --max-time 5 http://localhost:8080/graphql > /dev/null 2>&1; do
  attempts=$((attempts + 1))

  if [ "$attempts" -ge 45 ]; then
    echo "WordPress did not answer after 90 seconds." >&2
    echo "Try:  docker compose logs wordpress" >&2
    exit 1
  fi

  sleep 2
done

echo "Waiting for the application to answer..."
attempts=0
until curl -s --max-time 5 http://localhost:3000/ > /dev/null 2>&1; do
  attempts=$((attempts + 1))

  if [ "$attempts" -ge 45 ]; then
    echo "The application did not answer after 90 seconds." >&2
    echo "Try:  docker compose logs app" >&2
    exit 1
  fi

  sleep 2
done

cat <<EOF

  ------------------------------------------------------------------
  Everything is running. Open:

      http://localhost:3000

  ------------------------------------------------------------------
    The site          http://localhost:3000
    WordPress admin   ${WP_URL}/wp-admin          admin / admin
    GraphiQL          ${WP_URL}/graphql
    phpMyAdmin        http://localhost:8081       root / root

  The React application, WordPress, MySQL and phpMyAdmin all run in Docker.
  There is nothing to start afterwards, and no Node installation is required.

  To develop with hot reloading instead, stop the app container and run the
  app on the host:

      docker compose stop app
      cd app && npm run dev

  These credentials are throwaway values for a development container. They are
  not secrets, and they are not for anything reachable from the internet.
  ------------------------------------------------------------------

EOF
