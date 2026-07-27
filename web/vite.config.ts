import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

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
