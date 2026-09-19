# Build context: the REPOSITORY ROOT (the service needs binder-shared and the built editor).
#   docker build -f binder-render-service/Dockerfile -t binder-render-service .
#
# NOT built or run on the machine this was written on (no Docker there): the same
# steps were verified by hand (production bundle started, rendered and downloaded a
# PDF). Build it once on the host and check GET /healthz before pointing WordPress at it.

FROM node:22-bookworm AS build
WORKDIR /app
COPY binder-shared ./binder-shared
COPY binder-editor ./binder-editor
COPY binder-render-service ./binder-render-service
RUN cd binder-shared && npm ci
RUN cd binder-editor && npm ci && npm run build:print
RUN cd binder-render-service && npm ci && npm run build

# Keep this tag equal to the "playwright" version in binder-render-service/package.json,
# so the Chromium in the image is the one the library expects.
FROM mcr.microsoft.com/playwright:v1.63.0-noble
RUN apt-get update && apt-get install -y --no-install-recommends ghostscript && rm -rf /var/lib/apt/lists/*
WORKDIR /app

COPY --from=build /app/binder-render-service/package.json /app/binder-render-service/package-lock.json ./
RUN npm ci --omit=dev

COPY --from=build /app/binder-render-service/dist ./dist
COPY --from=build /app/binder-editor/dist ./editor-dist
COPY binder-shared/templates ./templates
# The ICC profile is NOT committed to the repository (licence). Put the ECI file
# "ISOcoated_v2_300_eci.icc" at binder-render-service/assets/icc/FOGRA39.icc before building.
COPY binder-render-service/assets/icc ./assets/icc

ENV NODE_ENV=production \
    PORT=8787 \
    EDITOR_DIST=/app/editor-dist \
    TEMPLATES_DIR=/app/templates \
    ICC_PROFILE=/app/assets/icc/FOGRA39.icc \
    GS_BIN=/usr/bin/gs \
    OUTPUT_DIR=/data/output \
    CHROMIUM_NO_SANDBOX=1

# Files are short-lived (the WordPress plugin copies them out immediately), so no volume is required.
EXPOSE 8787
HEALTHCHECK --interval=30s --timeout=5s CMD node -e "fetch('http://127.0.0.1:'+process.env.PORT+'/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "dist/server.mjs"]
