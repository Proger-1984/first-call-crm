import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  esbuild: {
    jsx: 'automatic',
    jsxImportSource: 'react'
  },
  server: {
    port: 3000,
    host: true,
    proxy: {
      '/api': {
        target: 'https://local.firstcall.com',
        changeOrigin: true,
        secure: false, // Игнорировать SSL ошибки для локальной разработки
      }
    }
  },
  resolve: {
    alias: {
      '@': '/src',
    },
  },
})
