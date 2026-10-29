import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

// Two apps in one build:
//   index.html -> client portal at /           (/login, /dashboard, /chats, /numbers, /templates)
//   admin.html -> admin panel at VITE_ADMIN_BASE (default /wab-control-7x)
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const adminBase = (env.VITE_ADMIN_BASE || '/wab-control-7x').replace(/\/+$/, '')
  const devRoutes = {
    name: 'dev-routes',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        const url = req.url.split('?')[0]
        if (url === adminBase || url.startsWith(`${adminBase}/`)) req.url = '/admin.html'
        else if (/^\/(login|dashboard|chats|numbers|templates|flows|instagram|number-requests|template-requests|billing|developer|template-stats\/[0-9]+)\/?$/.test(url)) req.url = '/index.html'
        next()
      })
    },
  }
  return {
    base: '/',
    plugins: [react(), devRoutes],
    build: { rollupOptions: { input: { portal: resolve(__dirname, 'index.html'), admin: resolve(__dirname, 'admin.html') } } },
    server: {
      host: '0.0.0.0', port: 5190, strictPort: true,
      proxy: { '/api': 'http://127.0.0.1:8020', '/storage': 'http://127.0.0.1:8020' },
    },
  }
})
