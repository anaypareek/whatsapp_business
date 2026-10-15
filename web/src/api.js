const KEY = 'wab_token'
export const getToken = () => localStorage.getItem(KEY) || sessionStorage.getItem(KEY)
export const setToken = (t, remember = true) => {
  localStorage.removeItem(KEY); sessionStorage.removeItem(KEY)
  if (t) (remember ? localStorage : sessionStorage).setItem(KEY, t)
}

export async function api(path, { method = 'GET', body, form } = {}) {
  const headers = { Accept: 'application/json' }
  const t = getToken()
  if (t) headers.Authorization = `Bearer ${t}`
  let payload
  if (form) payload = form
  else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }
  const res = await fetch(`/api${path}`, { method, headers, body: payload })
  if (res.status === 401 && path !== '/auth/login') { setToken(null); window.location.reload() }
  if (res.status === 204) return null
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || data.error || `HTTP ${res.status}`
    const err = new Error(msg); err.data = data; throw err
  }
  return data
}

export const fmtTime = (s) => s ? new Date(s).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
export const fmtShort = (s) => {
  if (!s) return ''
  const d = new Date(s), now = new Date()
  const same = d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' }) === now.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' })
  return d.toLocaleString('en-IN', same ? { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit' } : { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' })
}
