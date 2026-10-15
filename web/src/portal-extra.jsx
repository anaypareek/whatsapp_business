// Client portal: number requests, template requests, template stats and per-template send details.
import { useEffect, useState } from 'react'
import { PhoneCall, Plus, FileText, Send, Phone, CalendarDays, CalendarRange, BarChart3, ArrowLeft, Search, ChevronLeft, ChevronRight, CheckCheck, Check, AlertTriangle, Eye, Clock } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, useToast, useLoad, Skeleton } from './ui'
import { TemplateForm, blankForm, Preview } from './templates'

const n = (v) => (v ?? 0).toLocaleString('en-IN')
const CATS = ['Retail', 'E-commerce', 'Manufacturing', 'Education', 'Healthcare', 'Finance', 'Travel', 'Food & beverage', 'Services', 'Other']

export function NumberRequestsPage() {
  const [rows, reload, loading] = useLoad('/client/number-requests'); const toast = useToast()
  const blank = { phone_number: '', display_name: '', business_name: '', category: '', notes: '' }
  const [f, setF] = useState(blank); const [busy, setBusy] = useState(false)
  const submit = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await api('/client/number-requests', { method: 'POST', body: f }); toast.success('Request sent. We will review it shortly.'); setF(blank); reload() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  return (<div className="page"><PageHeader icon={PhoneCall} title="Number requests" subtitle="Request a new WhatsApp Business phone number" />
    <div className="grid-form">
      <Card title="Request a phone number" icon={Plus}>
        <form className="form" onSubmit={submit}>
          <Field label="Phone number" icon={Phone} hint="With country code, e.g. +91 98765 43210"><input value={f.phone_number} onChange={(e) => setF({ ...f, phone_number: e.target.value })} required /></Field>
          <Field label="Display name" hint="Name shown to customers on WhatsApp"><input maxLength={120} value={f.display_name} onChange={(e) => setF({ ...f, display_name: e.target.value })} required /></Field>
          <Field label="Business name"><input maxLength={160} value={f.business_name} onChange={(e) => setF({ ...f, business_name: e.target.value })} required /></Field>
          <Field label="Category"><select value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })}><option value="">Select…</option>{CATS.map((c) => <option key={c}>{c}</option>)}</select></Field>
          <Field label="Notes (optional)"><textarea rows={3} maxLength={2000} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
          <Button type="submit" variant="primary" icon={Send} loading={busy}>Submit request</Button>
        </form>
      </Card>
      <Card pad={false} title="My requests" icon={FileText}>
        <div className="table-wrap"><table className="table"><thead><tr><th>Number</th><th>Business</th><th>Status</th><th>Requested</th></tr></thead>
          {loading ? <TableSkeleton cols={4} /> : rows.length ? <tbody>{rows.map((r) => <tr key={r.id}>
            <td><b className="nowrap">{r.phone_number}</b><small className="block muted">{r.display_name}</small></td>
            <td>{r.business_name}<small className="block muted">{r.category || '—'}</small></td>
            <td><Badge s={r.status} />{r.status === 'approved' && r.registration_status === 'registered' && <small className="block muted">Active on WhatsApp</small>}
              {r.status === 'approved' && r.registration_status === 'failed' && <small className="block muted">Setup in progress</small>}
              {r.rejection_reason && <small className="block err-text">Reason: {r.rejection_reason}</small>}</td>
            <td className="muted nowrap">{fmtTime(r.created_at)}</td></tr>)}</tbody>
            : <tbody><tr><td colSpan={4}><Empty icon={PhoneCall} title="No requests yet">Use the form to request a WhatsApp number.</Empty></td></tr></tbody>}
        </table></div></Card>
    </div></div>)
}

const reqForm = (r) => ({ header: r.form?.header ? { format: r.form.header.format || 'NONE', text: r.form.header.text || '' } : { format: 'NONE' }, body: r.form?.body || { text: '' }, footer: r.form?.footer || '', buttons: r.form?.buttons || [] })

export function TemplateRequestsPage() {
  const [rows, reload, loading] = useLoad('/client/template-requests'); const toast = useToast()
  const [creating, setCreating] = useState(false); const [view, setView] = useState(null)
  if (creating) return <TemplateForm initial={blankForm()} title="Request new template" subtitle="Your administrator reviews it before it is submitted to Meta"
    submitLabel="Submit request" submit={(body) => api('/client/template-requests', { method: 'POST', body })}
    note={<div className="alert info"><FileText size={16} /> Use lowercase_with_underscores for the name. Variables like {'{{1}}'} need an example value.</div>}
    onDone={() => { toast.success('Template request submitted'); setCreating(false); reload() }} onCancel={() => setCreating(false)} />
  return (<div className="page"><PageHeader icon={FileText} title="Template requests" subtitle="Request new message templates and track their review">
    <Button variant="primary" icon={Plus} onClick={() => setCreating(true)}>Request new template</Button></PageHeader>
    <div className={view ? 'grid-2-1' : ''}>
      <Card pad={false}><div className="table-wrap"><table className="table"><thead><tr><th>Template</th><th>Category</th><th>Status</th><th>Meta status</th><th>Requested</th></tr></thead>
        {loading ? <TableSkeleton cols={5} /> : rows.length ? <tbody>{rows.map((r) => <tr key={r.id} className={`clickable ${view?.id === r.id ? 'sel' : ''}`} onClick={() => setView(r)}>
          <td><b>{r.name}</b><small className="block muted">{r.language}</small></td><td>{r.category}</td>
          <td><Badge s={r.status} />{r.rejection_reason && <small className="block err-text">Reason: {r.rejection_reason}</small>}</td>
          <td>{r.template ? <><Badge s={r.template.status} />{r.template.rejected_reason && <small className="block err-text">{r.template.rejected_reason}</small>}</> : <span className="muted">—</span>}</td>
          <td className="muted nowrap">{fmtTime(r.created_at)}</td></tr>)}</tbody>
          : <tbody><tr><td colSpan={5}><Empty icon={FileText} title="No template requests">Click “Request new template” to design one.</Empty></td></tr></tbody>}
      </table></div></Card>
      {view && <div className="sticky"><Card title={view.name} icon={Eye} actions={<button className="link" onClick={() => setView(null)}>Close</button>}>
        <div className="row wrap gap8"><Badge s={view.status} />{view.template && <Badge s={view.template.status}>Meta: {view.template.status}</Badge>}<Badge>{view.language}</Badge></div>
        {view.rejection_reason && <div className="alert error">Rejected: <b>{view.rejection_reason}</b></div>}
        <Preview form={reqForm(view)} /></Card></div>}
    </div></div>)
}

function Bars({ series }) {
  const W = 640, H = 220, P = { l: 34, r: 10, t: 14, b: 28 }
  const max = Math.max(4, ...series.map((d) => d.sent)); const nice = Math.ceil(max / 4) * 4
  const bw = (W - P.l - P.r) / series.length
  const y = (v) => H - P.b - (v / nice) * (H - P.t - P.b)
  return (<div className="chart"><svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none">
    {[0, 1, 2, 3, 4].map((i) => { const v = (nice / 4) * i; return <g key={i}><line x1={P.l} x2={W - P.r} y1={y(v)} y2={y(v)} className="grid" /><text x={P.l - 8} y={y(v) + 4} textAnchor="end" className="axis">{v}</text></g> })}
    {series.map((d, i) => <g key={d.date}><rect x={P.l + i * bw + bw * 0.22} y={y(d.sent)} width={bw * 0.56} height={Math.max(0, y(0) - y(d.sent))} rx={6} fill="#F97316"><title>{`${d.date}: ${d.sent} sent`}</title></rect>
      {d.sent > 0 && <text x={P.l + i * bw + bw / 2} y={y(d.sent) - 5} textAnchor="middle" className="axis">{d.sent}</text>}
      <text x={P.l + i * bw + bw / 2} y={H - 8} textAnchor="middle" className="axis">{d.label}</text></g>)}
  </svg></div>)
}

export function StatsSection({ go }) {
  const [s, setS] = useState(null); const toast = useToast()
  useEffect(() => { api('/client/stats').then(setS).catch((e) => toast.error(e.message)) }, []) // eslint-disable-line
  const card = (Icon, tone, label, v) => <div className={`stat tone-${tone}`}><div className="stat-top"><span className="stat-ico"><Icon size={20} /></span></div>
    {s ? <div className="stat-val">{n(v)}</div> : <Skeleton h={28} w="40%" style={{ margin: '10px 0 6px' }} />}<div className="stat-label">{label}</div></div>
  return (<>
    <div className="stats">{card(Send, 'orange', 'Total messages sent', s?.total_sent)}{card(Phone, 'blue', 'Phone numbers', s?.numbers)}
      {card(CalendarDays, 'green', 'Sent today', s?.sent_today)}{card(CalendarRange, 'red', 'Sent this month', s?.sent_month)}</div>
    <Card title="Messages sent · last 7 days" icon={BarChart3} actions={s && <span className="muted small">{n(s.series.reduce((a, d) => a + d.sent, 0))} in 7 days</span>}>
      {s ? <Bars series={s.series} /> : <Skeleton h={220} />}</Card>
    <Card pad={false} title="Template performance" icon={FileText}>
      <div className="table-wrap"><table className="table"><thead><tr><th>Template</th><th>Language</th><th className="right">Sent</th><th className="right">Delivered</th><th className="right">Read</th><th className="right">Failed</th><th>Last sent</th></tr></thead>
        {!s ? <TableSkeleton cols={7} rows={3} /> : s.templates.length ? <tbody>{s.templates.map((t) => <tr key={t.template_id} className="clickable" onClick={() => go(`template-stats/${t.template_id}`)}>
          <td><b className="link-text">{t.name}</b></td><td>{t.language || '—'}</td><td className="right"><b>{n(t.sent)}</b></td>
          <td className="right">{n(t.delivered)}</td><td className="right">{n(t.read)}</td><td className="right">{t.failed ? <span className="err-text">{n(t.failed)}</span> : 0}</td>
          <td className="muted nowrap">{fmtTime(t.last_sent)}</td></tr>)}</tbody>
          : <tbody><tr><td colSpan={7}><Empty icon={FileText} title="No template messages yet">Template sends from your numbers appear here.</Empty></td></tr></tbody>}
      </table></div></Card>
  </>)
}

const ST_ICON = { read: CheckCheck, delivered: CheckCheck, sent: Check, failed: AlertTriangle, pending: Clock }

export function TemplateDetail({ id, go }) {
  const [q, setQ] = useState(''); const [dq, setDq] = useState(''); const [status, setStatus] = useState(''); const [page, setPage] = useState(1)
  const [d, setD] = useState(null); const [loading, setLoading] = useState(true); const toast = useToast()
  useEffect(() => { const t = setTimeout(() => { setDq(q); setPage(1) }, 300); return () => clearTimeout(t) }, [q])
  useEffect(() => {
    setLoading(true)
    const qs = new URLSearchParams({ page, per_page: 20, ...(dq && { q: dq }), ...(status && { status }) })
    api(`/client/stats/templates/${id}?${qs}`).then(setD).catch((e) => toast.error(e.message)).finally(() => setLoading(false))
  }, [id, dq, status, page]) // eslint-disable-line
  const p = d?.sends
  return (<div className="page">
    <PageHeader icon={FileText} title={d ? d.template.name : 'Template'} subtitle={d ? `${d.template.language} · ${d.template.category} · every send from your numbers` : 'Loading…'}>
      <Button icon={ArrowLeft} onClick={() => go('dashboard')}>Back to dashboard</Button></PageHeader>
    <div className="stats">{[['Sent', 'sent', 'orange', Send], ['Delivered', 'delivered', 'blue', CheckCheck], ['Read', 'read', 'green', Eye], ['Failed', 'failed', 'red', AlertTriangle]].map(([l, k, tone, Icon]) =>
      <div key={k} className={`stat tone-${tone}`}><div className="stat-top"><span className="stat-ico"><Icon size={20} /></span></div>{d ? <div className="stat-val">{n(d.totals[k])}</div> : <Skeleton h={28} w="40%" style={{ margin: '10px 0 6px' }} />}<div className="stat-label">{l}</div></div>)}</div>
    <Card pad={false} title={<div className="filters">
      <div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search recipient or sender number" value={q} onChange={(e) => setQ(e.target.value)} /></div>
      <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}><option value="">All statuses</option>{['sent', 'delivered', 'read', 'failed', 'pending'].map((s) => <option key={s}>{s}</option>)}</select></div>}>
      <div className="table-wrap"><table className="table"><thead><tr><th>Recipient</th><th>Sender number</th><th>Time</th><th>Status</th></tr></thead>
        {loading && !d ? <TableSkeleton cols={4} /> : p?.data.length ? <tbody>{p.data.map((m) => { const I = ST_ICON[m.status] || Check; return <tr key={m.id}>
          <td><b className="nowrap">+{m.contact_wa_id}</b></td>
          <td className="nowrap">{m.phone_number?.display_number || '—'}{m.phone_number?.name && <small className="block muted">{m.phone_number.name}</small>}</td>
          <td className="muted nowrap">{fmtTime(m.created_at)}</td>
          <td><Badge s={m.status}><I size={12} /> {m.status}</Badge>{m.error && <small className="block err-text" style={{ maxWidth: 420 }}>{m.error}</small>}</td></tr> })}</tbody>
          : <tbody><tr><td colSpan={4}><Empty icon={Search} title="No sends found">{dq || status ? 'Try a different search or status.' : 'This template has not been sent from your numbers yet.'}</Empty></td></tr></tbody>}
      </table></div>
      {p && p.total > 0 && <div className="pager"><span className="muted small">{p.from}–{p.to} of {n(p.total)}</span>
        <div className="row gap8"><Button size="sm" icon={ChevronLeft} disabled={page <= 1} onClick={() => setPage(page - 1)}>Prev</Button>
          <span className="small">Page {p.current_page} / {p.last_page}</span>
          <Button size="sm" disabled={page >= p.last_page} onClick={() => setPage(page + 1)}>Next <ChevronRight size={14} /></Button></div></div>}
    </Card>
  </div>)
}
