import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const base = (env.VITE_ADMIN_BASE || '/wab-control-7x').replace(/\/+$/, '')
  return {
    base: `${base}/`,
    plugins: [react()],
    server: {
      host: '0.0.0.0', port: 5190, strictPort: true,
      proxy: { '/api': 'http://127.0.0.1:8020', '/storage': 'http://127.0.0.1:8020' },
    },
  }
})
