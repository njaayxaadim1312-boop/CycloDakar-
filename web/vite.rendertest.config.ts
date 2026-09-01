import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': path.resolve(import.meta.dirname, 'src') } },
  define: { 'process.env.NODE_ENV': '"production"' },
  build: {
    outDir: 'dist-rendertest', emptyOutDir: true, cssCodeSplit: false,
    rollupOptions: {
      input: path.resolve(import.meta.dirname, 'src/main.tsx'),
      output: { format: 'iife', entryFileNames: 'app.js', inlineDynamicImports: true },
    },
  },
})
