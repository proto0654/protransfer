import { defineConfig, loadEnv } from 'vite'
import tailwindcss from '@tailwindcss/vite'

const WP_ORIGIN = 'https://protransfer.weblaba.ru'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const wpOrigin = env.VITE_WP_ORIGIN || WP_ORIGIN

  return {
    // относительные пути к ассетам — сборка работает и локально, и на GitHub Pages (/protransfer/)
    base: './',
    plugins: [tailwindcss()],
    server: {
      port: 5173,
      open: false,
      // Как в weblaba-nuxt / eastagro: localhost ходит в WP через proxy (без CORS).
      proxy: {
        '/wp-json': {
          target: wpOrigin,
          changeOrigin: true,
          secure: true,
        },
        '/wp-content/uploads': {
          target: wpOrigin,
          changeOrigin: true,
          secure: true,
        },
      },
    },
    build: {
      rollupOptions: {
        input: {
          main: 'index.html',
          preview: 'preview.html',
          legal: 'legal.html',
        },
      },
    },
  }
})
