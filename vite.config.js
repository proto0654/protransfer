import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  // относительные пути к ассетам — сборка работает и локально, и на GitHub Pages (/protransfer/)
  base: './',
  plugins: [tailwindcss()],
  server: {
    port: 5173,
    open: false,
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
})
