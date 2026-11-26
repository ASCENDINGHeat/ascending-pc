# syntax=docker/dockerfile:1.7

FROM node:22-alpine AS builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

ARG PUBLIC_API_URL=http://localhost:8080/api
ARG PUBLIC_STORAGE_URL=http://localhost:8080
ENV PUBLIC_API_URL=${PUBLIC_API_URL}
ENV PUBLIC_STORAGE_URL=${PUBLIC_STORAGE_URL}

RUN npx svelte-kit sync \
    && npm run build \
    && npm prune --omit=dev \
    && mkdir -p /app/node_modules

# -----------------------------------------------------------------------------
# Stage 2: Minimal non-root SvelteKit Node runtime
# -----------------------------------------------------------------------------
FROM node:22-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000 \
    BODY_SIZE_LIMIT=26214400

COPY --from=builder --chown=node:node /app/package.json ./package.json
COPY --from=builder --chown=node:node /app/node_modules ./node_modules
COPY --from=builder --chown=node:node /app/build ./build

USER node

EXPOSE 3000

HEALTHCHECK --interval=20s --timeout=5s --start-period=15s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:3000/ || exit 1

CMD ["node", "build/index.js"]
