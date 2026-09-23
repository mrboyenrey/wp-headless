import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],

  server: {
    // Sits beside the WordPress container (8080) rather than on Vite's
    // default 5173, so the two origins are easy to tell apart.
    port: 3000,
    strictPort: true,
  },

  build: {
    // The production build is two separate bundles: a browser bundle under
    // dist/client and an SSR bundle under dist/server. Both are produced by
    // `npm run build`; see package.json.
    outDir: 'dist/client',
    emptyOutDir: true,
  },
});
