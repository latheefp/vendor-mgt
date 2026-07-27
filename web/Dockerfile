# syntax=docker/dockerfile:1
####################################################################
# React SPA
#
#   target dev  : Vite dev server with HMR, source bind-mounted
#   target prod : static build served by nginx (no Node at runtime)
####################################################################

########################  dev  #####################################
FROM node:22-alpine AS dev

WORKDIR /app

ENV IN_DOCKER=true

# Installed into the image so the bind mount can't shadow them away;
# compose keeps /app/node_modules on its own volume.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .

EXPOSE 5173
CMD ["npm", "run", "dev"]

########################  build  ###################################
FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

########################  prod  ####################################
FROM nginx:1.27-alpine AS prod

COPY --from=build /app/dist /usr/share/nginx/html

# SPA history fallback: every unknown path is a client route, but
# /api must never be swallowed here — Traefik routes it away first.
RUN printf '%s\n' \
    'server {' \
    '  listen 80;' \
    '  root /usr/share/nginx/html;' \
    '  index index.html;' \
    '  gzip on;' \
    '  gzip_types application/javascript text/css application/json image/svg+xml;' \
    '  location /assets/ {' \
    '    expires 1y;' \
    '    add_header Cache-Control "public, immutable";' \
    '  }' \
    '  location / {' \
    '    try_files $uri $uri/ /index.html;' \
    '  }' \
    '}' > /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
