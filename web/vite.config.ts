import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const apiTarget = env.VITE_API_PROXY_TARGET ?? 'http://127.0.0.1:8000'

  return {
    plugins: [react(), tailwindcss()],

    resolve: {
      alias: {
        '@': path.resolve(import.meta.dirname, './src'),
      },
    },

    server: {
      port: 5173,
      // `host: true` expose le serveur sur le réseau local : indispensable
      // pour tester le web responsive depuis un vrai téléphone sur le même
      // Wi-Fi, ce qui est le cas d'usage réel du club.
      host: true,
      proxy: {
        // En développement, /api est relayé vers `php artisan serve`.
        // Le navigateur ne voit qu'une seule origine : aucun CORS, aucun
        // cookie tiers, et le code de production reste identique.
        '/api': {
          target: apiTarget,
          changeOrigin: true,
        },
        '/storage': {
          target: apiTarget,
          changeOrigin: true,
        },
      },
    },

    build: {
      outDir: 'dist',
      sourcemap: mode !== 'production',
      rollupOptions: {
        output: {
          // Sépare les grosses bibliothèques du code applicatif : la carte et
          // les graphiques ne sont pas chargés sur l'écran de connexion.
          manualChunks(id) {
            if (!id.includes('node_modules')) return
            if (/[\\/]node_modules[\\/](leaflet|react-leaflet)[\\/]/.test(id)) return 'map'
            if (/[\\/]node_modules[\\/](recharts|d3-[^\\/]+|victory-[^\\/]+)[\\/]/.test(id)) return 'charts'
            if (/[\\/]node_modules[\\/](react|react-dom|react-router|react-router-dom|scheduler)[\\/]/.test(id)) {
              return 'react'
            }
          },
        },
      },
    },
  }
})
