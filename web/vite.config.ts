import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import { execSync } from 'node:child_process'

/**
 * The production Docker build never has `.git` in the image — nothing
 * copies it in, so `git rev-parse` inside that build always fails. CI
 * passes the real commit in as a build-arg instead (see the root
 * Dockerfile and .github/workflows/deploy.yaml); this only falls back to
 * running git for local `npm run dev`/`build`, where `.git` is right
 * there on disk.
 */
function commitHash(): string {
  if (process.env.VITE_APP_COMMIT) return process.env.VITE_APP_COMMIT
  try {
    return execSync('git rev-parse --short HEAD', { cwd: __dirname }).toString().trim()
  } catch {
    return 'unknown'
  }
}

/**
 * Two supported dev modes:
 *
 *  1. Behind Traefik (docker compose up) — the browser talks to
 *     http://localhost:7007 and Traefik forwards /api to Cake and
 *     everything else here. HMR therefore has to dial back on the
 *     published port, not 5173. Compose passes it in.
 *
 *  2. Bare `npm run dev` on the host — nothing is in front of us,
 *     so we proxy /api to the published Traefik port ourselves.
 */
const inDocker = process.env.IN_DOCKER === 'true'
const hmrClientPort = Number(process.env.VITE_HMR_CLIENT_PORT ?? (inDocker ? 7007 : 5173))
const proxyTarget = process.env.VITE_PROXY_TARGET ?? (inDocker ? 'http://api-web' : 'http://localhost:7007')

export default defineConfig({
  plugins: [react(), tailwindcss()],

  define: {
    __APP_COMMIT__: JSON.stringify(commitHash()),
  },

  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      clientPort: hmrClientPort,
    },
    watch: {
      // Bind mounts on macOS/Windows don't reliably deliver inotify.
      usePolling: inDocker,
      interval: 300,
    },
    proxy: {
      '/api': {
        target: proxyTarget,
        changeOrigin: false, // keep Host intact so Cake's session cookie stays same-site
        cookieDomainRewrite: '',
      },
    },
  },

  build: {
    outDir: 'dist',
    sourcemap: true,
    rollupOptions: {
      output: {
        // Split the vendor libraries out so an app-code change does not
        // invalidate the whole bundle for a technician on a weak signal.
        manualChunks(id: string) {
          if (!id.includes('node_modules')) return
          if (/[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/.test(id)) return 'react'
          if (id.includes('@tanstack')) return 'tanstack'
        },
      },
    },
  },
})
