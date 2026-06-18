import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  base: '/apps/govportal/',
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: false,
    cssCodeSplit: false,
    rollupOptions: {
      output: {
        // Single file output
        inlineDynamicImports: true,
        // ES module format - will be loaded with type="module"
        format: 'es',
        entryFileNames: 'govportal.js',
        assetFileNames: 'govportal.[ext]',
      },
    },
  },
  server: {
    port: 3000,
    proxy: {
      '/ocs': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
      },
      '/remote.php': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
      },
      '/apps/oauth2': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
