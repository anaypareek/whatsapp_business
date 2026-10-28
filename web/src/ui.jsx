import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { CheckCircle2, AlertCircle, X, Loader2, Inbox } from 'lucide-react'
import { api } from './api'

export const Spinner = ({ size = 16, className = '' }) => <Loader2 size={size} className={`spin ${className}`} />

/** Button with built-in loading state. If onClick returns a promise, loading is automatic. */
export function Button({ loading, variant = 'secondary', icon: Icon, children, onClick, disabled, size, className = '', type = 'button', ...rest }) {
  const [busy, setBusy] = useState(false)
  const isLoading = loading ?? busy
  const handle = async (e) => {
    if (!onClick) return
    const r = onClick(e)
    if (r && typeof r.then === 'function' && loading === undefined) { setBusy(true); try { await r } finally { setBusy(false) } }
  }
  return (
    <button type={type} className={`btn btn-${variant} ${size ? `btn-${size}` : ''} ${className}`} disabled={disabled || isLoading} onClick={handle} {...rest}>
      {isLoading ? <Spinner size={size === 'sm' ? 14 : 16} /> : Icon ? <Icon size={size === 'sm' ? 14 : 16} /> : null}
      {children && <span>{children}</span>}
    </button>
  )
}

export const Card = ({ title, icon: Icon, actions, children, className = '', pad = true }) => (
  <section className={`card ${className}`}>
    {(title || actions) && <header className="card-head">
      <h3>{Icon && <span className="card-ico"><Icon size={16} /></span>}{title}</h3><div className="card-actions">{actions}</div></header>}
    <div className={pad ? 'card-body' : ''}>{children}</div>
  </section>
)

export const PageHeader = ({ title, subtitle, icon: Icon, children }) => (
  <div className="page-head">
    <div className="page-title">{Icon && <span className="page-ico"><Icon size={20} /></span>}<div><h1>{title}</h1>{subtitle && <p>{subtitle}</p>}</div></div>
    <div className="page-actions">{children}</div>
  </div>
)

export const Field = ({ label, hint, icon: Icon, children, className = '' }) => (
  <label className={`field ${className}`}>{label && <span className="field-label">{label}</span>}
    <div className={Icon ? 'input-ico' : ''}>{Icon && <Icon size={16} className="ico" />}{children}</div>
    {hint && <span className="field-hint">{hint}</span>}</label>
)

export const Skeleton = ({ h = 14, w = '100%', r = 6, style }) => <div className="skel" style={{ height: h, width: w, borderRadius: r, ...style }} />
export const TableSkeleton = ({ rows = 5, cols = 4 }) => (
  <tbody>{Array.from({ length: rows }, (_, i) => <tr key={i}>{Array.from({ length: cols }, (_, j) => <td key={j}><Skeleton w={j === 0 ? '70%' : '50%'} /></td>)}</tr>)}</tbody>
)
export const Empty = ({ icon: Icon = Inbox, title = 'Nothing here yet', children }) => (
  <div className="empty"><span className="empty-ico"><Icon size={26} /></span><b>{title}</b>{children && <p>{children}</p>}</div>
)

const STATUS_TONE = { approved: 'green', sent: 'blue', delivered: 'blue', read: 'green', received: 'gray', pending: 'amber', in_appeal: 'amber', rejected: 'red', failed: 'red', paused: 'orange', disabled: 'gray' }
export const Badge = ({ s, tone, children }) => <span className={`badge tone-${tone || STATUS_TONE[(s || '').toLowerCase()] || 'gray'}`}>{children ?? s}</span>

export const Toggle = ({ checked, onChange, disabled }) => (
  <button type="button" role="switch" aria-checked={checked} disabled={disabled} className={`toggle ${checked ? 'on' : ''}`} onClick={() => onChange(!checked)}><span /></button>
)

// ---- toasts ----
const ToastCtx = createContext(null)
export function ToastProvider({ children }) {
  const [items, setItems] = useState([])
  const push = useCallback((type, msg) => {
    const id = Math.random().toString(36).slice(2)
    setItems((x) => [...x, { id, type, msg }])
    setTimeout(() => setItems((x) => x.filter((t) => t.id !== id)), type === 'error' ? 6000 : 3500)
  }, [])
  const api = { success: (m) => push('success', m), error: (m) => push('error', m) }
  return (<ToastCtx.Provider value={api}>{children}
    <div className="toasts">{items.map((t) => <div key={t.id} className={`toast ${t.type}`}>
      {t.type === 'success' ? <CheckCircle2 size={18} /> : <AlertCircle size={18} />}<span>{t.msg}</span>
      <button onClick={() => setItems((x) => x.filter((y) => y.id !== t.id))}><X size={14} /></button></div>)}</div>
  </ToastCtx.Provider>)
}
export const useToast = () => useContext(ToastCtx)

/** Load JSON from the API with loading flag. */
export function useLoad(path, deps = []) {
  const [data, setData] = useState(null); const [loading, setLoading] = useState(true); const toast = useToast()
  const reload = useCallback(() => (path ? api(path) : Promise.resolve([])).then(setData).catch((e) => toast?.error(e.message)).finally(() => setLoading(false)), [path, ...deps]) // eslint-disable-line
  useEffect(() => { setLoading(true); reload() }, [reload])
  return [data ?? [], reload, loading, setData]
}

export function Modal({ title, onClose, children, footer, wide }) {
  useEffect(() => { const k = (e) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k) }, [onClose])
  return (<div className="modal-bg" onMouseDown={onClose}><div className={`modal ${wide ? 'wide' : ''}`} onMouseDown={(e) => e.stopPropagation()}>
    <header className="card-head"><h3>{title}</h3><button className="icon-btn" onClick={onClose}><X size={18} /></button></header>
    <div className="card-body">{children}</div>{footer && <footer className="modal-foot">{footer}</footer>}</div></div>)
}

export const initials = (s) => (s || '?').replace(/[^\p{L}\p{N} ]/gu, '').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '#'
const AV = ['#F97316', '#EA580C', '#FB923C', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899']
export const Avatar = ({ name, size = 40 }) => {
  const c = AV[[...(name || '')].reduce((a, ch) => a + ch.charCodeAt(0), 0) % AV.length]
  return <span className="avatar" style={{ width: size, height: size, background: `${c}22`, color: c, fontSize: size * 0.36 }}>{initials(name)}</span>
}
