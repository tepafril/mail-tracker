# syntax=docker/dockerfile:1.7
#
# Web tier. Build context = repo root (needs packages/core + packages/addin-outlook).
#   1. Builds the shared core package, then the Outlook add-in, hosted under /addin.
#   2. Runs Caddy as the TLS-terminating reverse proxy in front of the php-fpm 'app'.
#
# Build args bake the add-in's compile-time config (see src/config.ts):
#   BACKEND_BASE_URL  origin of the Laravel API (add-in appends /api/v1)
#   ENTRA_CLIENT_ID   multi-tenant Entra app id
#   API_SCOPE         optional; defaults to api://<client-id>/access_as_user

############################################
# 1. Build core + Outlook add-in (-> /addin)
############################################
FROM node:22-bookworm-slim AS addin
WORKDIR /repo

# Workspace manifests first for a cached install layer.
COPY package.json package-lock.json ./
COPY packages/core/package.json packages/core/package.json
COPY packages/addin-outlook/package.json packages/addin-outlook/package.json
COPY packages/addin-gmail/package.json packages/addin-gmail/package.json
RUN npm ci

# Sources, then build the shared contract and the add-in bundle.
COPY packages/core packages/core
COPY packages/addin-outlook packages/addin-outlook
RUN npm run build:core

ARG BACKEND_BASE_URL
ARG ENTRA_CLIENT_ID
ARG API_SCOPE
ENV BACKEND_BASE_URL=$BACKEND_BASE_URL \
    ENTRA_CLIENT_ID=$ENTRA_CLIENT_ID \
    API_SCOPE=$API_SCOPE \
    PUBLIC_PATH=/addin/
RUN npm run build --workspace packages/addin-outlook

############################################
# 2. Caddy: serve /addin static + proxy API
############################################
FROM caddy:2-alpine AS web
# Laravel document root (index.php is executed by the 'app' php-fpm container; Caddy
# only needs the path + any static public assets to exist here).
COPY packages/backend/public /var/www/html/public
# The compiled add-in, served under /addin/*.
COPY --from=addin /repo/packages/addin-outlook/dist /srv/addin
COPY packages/backend/docker/Caddyfile /etc/caddy/Caddyfile
