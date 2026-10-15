// Admin panel base path. Change via VITE_ADMIN_BASE at build time (e.g. VITE_ADMIN_BASE=/wab-control-7x npm run build).
export const ADMIN_BASE = (import.meta.env.VITE_ADMIN_BASE || '/wab-control-7x').replace(/\/+$/, '')
