import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // The API allow-lists this exact origin in CORS_ALLOWED_ORIGINS (and FRONTEND_URL points here),
    // so the dev server must not drift to another port.
    port: 3000,
    strictPort: true,
  },
})
