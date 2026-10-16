import { useEffect, useState, useCallback } from 'react'
import { Bug, Search, CheckCircle2, RotateCcw, Trash2, X, RefreshCw, Copy } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Badge, Empty, Skeleton, useToast } from './ui'

const LEVEL_TONE = { emergency: 'red', alert: 'red', critical: 'red', error: 'red', warning: 'amber', notice: 'blue', info: 'gray', debug: 'gray' }
const SRC_TONE = { meta: 'blue', exception: 'red', frontend: 'orange', app: 'gray' }

export function ErrorLogs({ onChange: onCount }) {
  const toast = useToast()
  const [flt, setFlt] = useState({ level: '', source: '', from: '', to: '', q: '', status: 'open' })
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [sel, setSel] = useState(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { const t = setTimeout(() => setFlt((f) => (f.q === q ? f : { ...f, q })), 350); return () => clearTimeout(t) }, [q])
  useEffect(() => setPage(1), [flt])
  const load = useCallback(() => {
    const p = new URLSearchParams(Object.entries({ ...flt, page }).filter(([, v]) => v !== ''))
    return api(`/error-logs?${p}`).then(setData).catch((e) => toast.error(e.message))
  }, [flt, page]) // eslint-disable-line
  useEffect(() => { load() }, [load])
  const refreshAll = () => { load(); onCount?.() }
  const open = (row) => api(`/error-logs/${row.id}`).then(setSel).catch((e) => toast.error(e.message))
  const resolve = async (row, reopen = false) => {
    try { const r = await api(`/error-logs/${row.id}/resolve`, { method: 'POST', body: { reopen } }); if (sel?.id === row.id) setSel(r); toast.success(reopen ? 'Reopened' : 'Marked resolved'); refreshAll() } catch (e) { toast.error(e.message) }
  }
  const clear = async () => {
    const d = prompt('Delete log entries older than how many days? (0 = everything)', '7'); if (d === null || d === '' || isNaN(+d)) return
    setBusy(true)
    try { const r = await api(`/error-logs/clear?older_than_days=${+d}`, { method: 'DELETE' }); toast.success(`Deleted ${r.deleted} entr${r.deleted === 1 ? 'y' : 'ies'}`); refreshAll() } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const set = (k) => (e) => setFlt({ ...flt, [k]: e.target.value })
  const rows = data?.data

  return (<div className="page">
    <PageHeader title="Error Logs" subtitle="Server errors, failed Meta API calls and browser errors. Kept for 30 days." icon={Bug}>
      <Button icon={RefreshCw} onClick={refreshAll}>Refresh</Button>
      <Button icon={Trash2} variant="ghost-danger" loading={busy} onClick={clear}>Clear old</Button>
    </PageHeader>
    <Card pad={false} title={<div className="filters">
      <div className="input-ico" style={{ flex: 2 }}><Search size={16} className="ico" /><input placeholder="Search message, code, URL, user or #id" value={q} onChange={(e) => setQ(e.target.value)} /></div>
      <select value={flt.status} onChange={set('status')}><option value="open">Open</option><option value="resolved">Resolved</option><option value="all">All</option></select>
      <select value={flt.level} onChange={set('level')}><option value="">All levels</option>{['critical', 'error', 'warning'].map((l) => <option key={l}>{l}</option>)}</select>
      <select value={flt.source} onChange={set('source')}><option value="">All sources</option><option value="meta">Meta API</option><option value="exception">Exception</option><option value="frontend">Frontend</option><option value="app">App log</option></select>
      <input type="date" value={flt.from} onChange={set('from')} title="From" />
      <input type="date" value={flt.to} onChange={set('to')} title="To" />
    </div>}>
      {!rows ? <div className="card-body">{[0, 1, 2, 3].map((i) => <Skeleton key={i} h={34} style={{ marginBottom: 10 }} />)}</div>
        : rows.length === 0 ? <Empty icon={CheckCircle2} title="No errors here">Nothing matches these filters.</Empty>
        : <div className="table-wrap"><table className="table errlog-table"><thead><tr><th>#</th><th>When</th><th>Level</th><th>Source</th><th>Message</th><th>Where</th><th></th></tr></thead>
          <tbody>{rows.map((r) => <tr key={r.id} className={`clickable ${sel?.id === r.id ? 'sel' : ''} ${r.resolved_at ? 'dim' : ''}`} onClick={() => open(r)}>
            <td className="muted">{r.id}</td><td className="nowrap">{fmtTime(r.created_at)}</td>
            <td><Badge tone={LEVEL_TONE[r.level]}>{r.level}</Badge></td><td><Badge tone={SRC_TONE[r.source]}>{r.source}</Badge></td>
            <td className="errlog-msg"><b>{r.error_code}</b>{r.status_code ? <span className="muted"> · HTTP {r.status_code}</span> : null}<div>{r.message}</div></td>
            <td className="errlog-where muted">{r.method && <b>{r.method} </b>}{(r.url || '').replace(/^https?:\/\/[^/]+/, '')}{r.user_label && <div>{r.user_label}</div>}</td>
            <td onClick={(e) => e.stopPropagation()}>{r.resolved_at ? <Button size="sm" variant="ghost" icon={RotateCcw} onClick={() => resolve(r, true)} title="Reopen" />
              : <Button size="sm" variant="ghost" icon={CheckCircle2} onClick={() => resolve(r)} title="Mark resolved" />}</td></tr>)}</tbody></table></div>}
      {data && data.last_page > 1 && <div className="pager"><Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
        <span className="muted">Page {data.current_page} of {data.last_page} · {data.total} entries</span>
        <Button size="sm" disabled={page >= data.last_page} onClick={() => setPage(page + 1)}>Next</Button></div>}
    </Card>
    {sel && <ErrorDrawer log={sel} onClose={() => setSel(null)} onResolve={resolve} />}
  </div>)
}

function ErrorDrawer({ log, onClose, onResolve }) {
  const toast = useToast()
  useEffect(() => { const k = (e) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k) }, [onClose])
  const copy = () => navigator.clipboard?.writeText(JSON.stringify(log, null, 2)).then(() => toast.success('Copied'))
  const kv = [['Log ID', `#${log.id}`], ['Time', fmtTime(log.created_at)], ['Level', log.level], ['Source', log.source], ['Error code', log.error_code], ['HTTP status', log.status_code],
    ['Request', log.method ? `${log.method} ${log.url}` : log.url], ['File', log.file ? `${log.file}${log.line ? `:${log.line}` : ''}` : null], ['User', log.user_label], ['IP', log.ip],
    ['Resolved', log.resolved_at ? `${fmtTime(log.resolved_at)}${log.resolver ? ` by ${log.resolver.name}` : ''}` : null]].filter(([, v]) => v)
  return (<div className="drawer-bg" onMouseDown={onClose}><aside className="drawer" onMouseDown={(e) => e.stopPropagation()}>
    <header className="card-head"><h3>Error #{log.id}</h3><div className="row-gap">
      <Button size="sm" icon={Copy} onClick={copy}>Copy</Button>
      {log.resolved_at ? <Button size="sm" icon={RotateCcw} onClick={() => onResolve(log, true)}>Reopen</Button> : <Button size="sm" variant="primary" icon={CheckCircle2} onClick={() => onResolve(log)}>Mark resolved</Button>}
      <button className="icon-btn" onClick={onClose}><X size={18} /></button></div></header>
    <div className="drawer-body">
      <div className="errlog-title">{log.message}</div>
      <dl className="kv">{kv.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
      {log.context && Object.keys(log.context).length > 0 && <><h4>Context <span className="muted">(secrets redacted)</span></h4><pre className="code">{JSON.stringify(log.context, null, 2)}</pre></>}
      {log.trace && <><h4>Stack trace</h4><pre className="code trace">{log.trace}</pre></>}
    </div></aside></div>)
}

/** Report uncaught browser errors to /api/client-errors (max 5 per page load). */
export function installErrorReporter(prefix, app) {
  let sent = 0; const seen = new Set()
  const send = (p) => {
    const key = p.message + (p.line || ''); if (sent >= 5 || seen.has(key)) return; sent++; seen.add(key)
    fetch(`${prefix}/client-errors`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, keepalive: true,
      body: JSON.stringify({ app, url: location.href, user_agent: navigator.userAgent.slice(0, 500), ...p, message: String(p.message || 'Unknown error').slice(0, 2000), stack: p.stack ? String(p.stack).slice(0, 20000) : null }) }).catch(() => {})
  }
  window.addEventListener('error', (e) => { if (!e.message) return; send({ kind: 'error', message: e.message, source: e.filename?.slice(0, 500), line: e.lineno || null, column: e.colno || null, stack: e.error?.stack }) })
  window.addEventListener('unhandledrejection', (e) => { const r = e.reason; if (r?.data) return; /* API errors already logged server-side */ send({ kind: 'unhandled_rejection', message: r?.message || String(r), stack: r?.stack }) })
}
