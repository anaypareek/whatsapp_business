import { useCallback, useEffect, useMemo, useState } from 'react'
import { FileText, Plus, RefreshCw, Pencil, Trash2, Send, Search, X, Lock, Eye, History, Phone, Hash, Link2, Reply, PhoneCall, FlaskConical, CheckCircle2, AlertCircle, Briefcase } from 'lucide-react'
import { api, fmtTime } from './api'
import { ClientSelect } from './clients'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Modal, Spinner, Badge as UiBadge, useToast } from './ui'

export const LANGS = ['en_US', 'en', 'en_GB', 'hi', 'mr', 'gu', 'bn', 'ta', 'te', 'kn', 'ml', 'pa', 'ur', 'ar', 'es', 'fr', 'de', 'pt_BR', 'id']
const STATUSES = ['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL']
const fill = (s, vals) => (s || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => vals?.[n - 1] || m)
const countVars = (s) => Math.max(0, ...[...(s || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map((m) => +m[1]))
const fmtWa = (s) => (s || '').split(/(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g).map((p, i) =>
  /^\*.+\*$/.test(p) ? <b key={i}>{p.slice(1, -1)}</b> : /^_.+_$/.test(p) ? <i key={i}>{p.slice(1, -1)}</i> : /^~.+~$/.test(p) ? <s key={i}>{p.slice(1, -1)}</s> : p)

export const Badge = ({ s }) => <UiBadge s={s} />

/** WhatsApp-style preview from a form object {header, body, footer, buttons} with variable values. */
export function Preview({ form, headerVal, bodyVals, buttonVals }) {
  const h = form.header || {}
  const fmt = h.format || 'NONE'
  return (<div className="wa-preview"><div className="wa-preview-top"><span className="avatar sm">FO</span><b>Fineoutput</b></div><div className="wa-preview-body"><div className="bubble out tpl">
    {fmt === 'TEXT' && <div className="tpl-h">{fill(h.text, [headerVal ?? h.example])}</div>}
    {fmt === 'IMAGE' && (headerVal && /^https?:/.test(headerVal) ? <img src={headerVal} alt="" /> : <div className="tpl-media">🖼 Image</div>)}
    {fmt === 'VIDEO' && <div className="tpl-media">🎬 Video</div>}
    {fmt === 'DOCUMENT' && <div className="tpl-media">📄 Document</div>}
    <div className="text">{fmtWa(fill(form.body?.text, bodyVals ?? form.body?.examples)) || <span className="muted">Body text…</span>}</div>
    {form.footer && <div className="tpl-f">{form.footer}</div>}
    <div className="meta">{fmtTime(new Date().toISOString())}</div>
    {(form.buttons || []).map((b, i) => <div key={i} className="tpl-btn">{b.type === 'URL' ? <Link2 size={14} /> : b.type === 'PHONE_NUMBER' ? <PhoneCall size={14} /> : <Reply size={14} />}{b.text || 'Button'}
      {b.type === 'URL' && countVars(b.url) > 0 && <small className="muted"> {fill(b.url, [buttonVals?.[i] ?? b.example?.replace(/.*\//, '')])}</small>}</div>)}
  </div></div></div>)
}

/** Meta components -> form */
export function toForm(t) {
  const c = (type) => (t.components || []).find((x) => (x.type || '').toUpperCase() === type)
  const H = c('HEADER'), B = c('BODY'), F = c('FOOTER'), BT = c('BUTTONS')
  return {
    name: t.name, language: t.language, category: t.category,
    header: H ? { format: H.format || 'TEXT', text: H.text || '', example: H.example?.header_text?.[0] || H.example?.header_handle?.[0] || '' } : { format: 'NONE', text: '', example: '' },
    body: { text: B?.text || '', examples: B?.example?.body_text?.[0] || [] },
    footer: F?.text || '',
    buttons: (BT?.buttons || []).map((b) => ({ type: b.type, text: b.text, url: b.url || '', phone_number: b.phone_number || '', example: b.example?.[0] || '' })),
  }
}
export const blankForm = () => ({ name: '', language: 'en_US', category: 'UTILITY', header: { format: 'NONE', text: '', example: '' }, body: { text: '', examples: [] }, footer: '', buttons: [] })

/** Full template builder with live preview. Reused by the admin (Template Master, Template Requests) and the client portal.
 *  submit(body) overrides the default POST/PUT to /templates; title/subtitle/submitLabel/note customise the header. */
export function TemplateForm({ initial, editing, onDone, onCancel, submit, title, subtitle, submitLabel, note, extraActions, clients }) {
  const [f, setF] = useState(initial)
  const [clientId, setClientId] = useState(editing?.client_id ? String(editing.client_id) : '')
  const [busy, setBusy] = useState(false); const toast = useToast()
  const n = countVars(f.body.text)
  const set = (patch) => setF({ ...f, ...patch })
  const setH = (patch) => set({ header: { ...f.header, ...patch } })
  const setBtn = (i, patch) => set({ buttons: f.buttons.map((b, j) => (j === i ? { ...b, ...patch } : b)) })
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    const body = { ...f, header: f.header.format === 'NONE' ? null : f.header, body: { text: f.body.text, examples: f.body.examples.slice(0, n) }, footer: f.footer || null, ...(clients ? { client_id: clientId || null } : {}) }
    try { if (submit) await submit(body); else await api(editing ? `/templates/${editing.id}` : '/templates', { method: editing ? 'PUT' : 'POST', body }); onDone() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  return (<div className="page">
    <PageHeader icon={editing ? Pencil : Plus} title={title || (editing ? `Edit ${editing.name}` : 'New template')} subtitle={subtitle || (editing ? `${editing.language} · ${editing.category}` : 'Submitted to Meta for review')}>
      {extraActions}<Button icon={X} onClick={onCancel}>Cancel</Button></PageHeader>
    <div className="grid-2-1">
      <Card title="Template content" icon={FileText}>
        <form className="form" onSubmit={save}>
          {note}
          {editing && <div className="alert info"><Lock size={16} /> Name and language can't be changed. Approved templates keep their category and allow 1 edit per 24h and 10 per 30 days. Edited templates go back to review.</div>}
          <div className="form-3">
            <Field label="Name" hint="lowercase_with_underscores"><input disabled={!!editing} value={f.name} onChange={(e) => set({ name: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} required /></Field>
            <Field label="Language"><select disabled={!!editing} value={f.language} onChange={(e) => set({ language: e.target.value })}>{LANGS.map((l) => <option key={l}>{l}</option>)}</select></Field>
            <Field label="Category"><select disabled={editing?.status === 'APPROVED'} value={f.category} onChange={(e) => set({ category: e.target.value })}><option>UTILITY</option><option>MARKETING</option><option>AUTHENTICATION</option></select></Field>
          </div>
          {clients && <Field label="Client" icon={Briefcase} hint="Optional. The template appears in this client's portal once approved."><ClientSelect clients={clients} value={clientId} onChange={setClientId} placeholder="None / Internal" /></Field>}
          <div className="form-3">
            <Field label="Header"><select value={f.header.format} onChange={(e) => setH({ format: e.target.value })}>{['NONE', 'TEXT', 'IMAGE', 'DOCUMENT', 'VIDEO'].map((x) => <option key={x}>{x}</option>)}</select></Field>
            {f.header.format === 'TEXT' && <Field label="Header text" hint="Max 60, one {{1}}" className="span2"><input maxLength={60} value={f.header.text} onChange={(e) => setH({ text: e.target.value })} /></Field>}
            {['IMAGE', 'DOCUMENT', 'VIDEO'].includes(f.header.format) && <Field label="Sample header_handle" hint="From Meta's Resumable Upload API" className="span2"><input value={f.header.example} onChange={(e) => setH({ example: e.target.value })} /></Field>}
          </div>
          {f.header.format === 'TEXT' && countVars(f.header.text) > 0 && <Field label="Header {{1}} example"><input value={f.header.example} onChange={(e) => setH({ example: e.target.value })} /></Field>}
          <Field label={`Body (${f.body.text.length}/1024)`} hint="Use {{1}}, {{2}}… and *bold* _italic_ ~strike~"><textarea rows={6} maxLength={1024} value={f.body.text} onChange={(e) => set({ body: { ...f.body, text: e.target.value } })} required /></Field>
          {n > 0 && <div className="form-3">{Array.from({ length: n }, (_, i) => <Field key={i} label={`Example {{${i + 1}}}`}><input value={f.body.examples[i] || ''}
            onChange={(e) => { const ex = [...f.body.examples]; ex[i] = e.target.value; set({ body: { ...f.body, examples: ex } }) }} /></Field>)}</div>}
          <Field label="Footer (optional, max 60)"><input maxLength={60} value={f.footer} onChange={(e) => set({ footer: e.target.value })} /></Field>
          <div className="row between"><span className="field-label">Buttons ({f.buttons.length}/10)</span>
            <Button size="sm" icon={Plus} disabled={f.buttons.length >= 10} onClick={() => set({ buttons: [...f.buttons, { type: 'QUICK_REPLY', text: '', url: '', phone_number: '', example: '' }] })}>Add button</Button></div>
          {f.buttons.map((b, i) => <div className="btn-row" key={i}>
            <select value={b.type} onChange={(e) => setBtn(i, { type: e.target.value })}><option>QUICK_REPLY</option><option>URL</option><option>PHONE_NUMBER</option></select>
            <input maxLength={25} placeholder="Button text" value={b.text} onChange={(e) => setBtn(i, { text: e.target.value })} />
            {b.type === 'URL' && <input className="grow" placeholder="https://… (optional {{1}} at end)" value={b.url} onChange={(e) => setBtn(i, { url: e.target.value })} />}
            {b.type === 'URL' && countVars(b.url) > 0 && <input placeholder="Example full URL" value={b.example} onChange={(e) => setBtn(i, { example: e.target.value })} />}
            {b.type === 'PHONE_NUMBER' && <input placeholder="+919876543210" value={b.phone_number} onChange={(e) => setBtn(i, { phone_number: e.target.value })} />}
            <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => set({ buttons: f.buttons.filter((_, j) => j !== i) })} /></div>)}
          <div><Button type="submit" variant="primary" icon={Send} loading={busy}>{submitLabel || (editing ? 'Submit edit to Meta' : 'Submit for review')}</Button></div>
        </form>
      </Card>
      <div className="sticky"><Card title="Live preview" icon={Eye}><Preview form={f} /></Card></div>
    </div>
  </div>)
}

export function Templates() {
  const [clients, setClients] = useState([])
  useEffect(() => { api('/clients-list').then(setClients).catch(() => {}) }, [])
  const [rows, setRows] = useState(null); const [flt, setFlt] = useState({ status: '', category: '', language: '', q: '', client_id: '' })
  const [testing, setTesting] = useState(null)
  const [mode, setMode] = useState(null); const toast = useToast()
  const load = useCallback(() => { const qs = new URLSearchParams(Object.entries(flt).filter(([, v]) => v)).toString(); return api(`/templates?${qs}`).then(setRows).catch((e) => toast.error(e.message)) }, [flt]) // eslint-disable-line
  useEffect(() => { load() }, [load])
  useEffect(() => { const t = setInterval(load, 15000); return () => clearInterval(t) }, [load])
  const sync = async () => { try { const r = await api('/templates-sync', { method: 'POST' }); toast.success(`Synced ${r.synced} template(s)${r.removed ? `, removed ${r.removed}` : ''}`); load() } catch (e) { toast.error(e.message) } }
  const del = async (t) => {
    if (!confirm(t.meta_id ? `Delete template ${t.name} (${t.language}) on Meta? The name can't be reused for 30 days.` : `Delete template ${t.name} (${t.language})? It was never created on Meta.`)) return
    const done = (msg) => { toast.success(msg); if (mode?.view?.id === t.id) setMode(null); load() }
    try { const r = await api(`/templates/${t.id}`, { method: 'DELETE' }); done(r?.message || 'Template deleted') } catch (e) {
      toast.error(e.message)
      if (!e.data?.can_remove_locally || !confirm(`Meta refused to delete "${t.name}":\n\n${e.data.message}\n\nRemove it from this portal only? (It stays on Meta and comes back on the next "Sync from Meta".)`)) return
      try { const r = await api(`/templates/${t.id}?local_only=1`, { method: 'DELETE' }); done(r?.message || 'Removed from this portal') } catch (e2) { toast.error(e2.message) }
    }
  }
  const edit = (t) => { if (!t.edit_check.allowed) { toast.error(`Edit not allowed: ${t.edit_check.reason}`); return } setMode({ edit: t }) }
  const view = async (t) => { try { setMode({ view: await api(`/templates/${t.id}`) }) } catch (e) { toast.error(e.message) } }
  const all = rows || []
  const langs = useMemo(() => [...new Set(all.map((r) => r.language))], [rows]) // eslint-disable-line
  const counts = useMemo(() => all.reduce((a, t) => ({ ...a, [t.status]: (a[t.status] || 0) + 1 }), {}), [rows]) // eslint-disable-line

  if (mode?.new || mode?.edit) return <TemplateForm clients={clients} initial={mode.edit ? toForm(mode.edit) : blankForm()} editing={mode.edit}
    onDone={() => { toast.success(mode.edit ? 'Edit submitted; template is back in review' : 'Template submitted to Meta for review'); setMode(null); load() }} onCancel={() => setMode(null)} />

  return (<div className="page">
    <PageHeader icon={FileText} title="Template Master" subtitle="Create, review and sync WhatsApp message templates">
      <Button icon={RefreshCw} onClick={sync}>Sync from Meta</Button><Button variant="primary" icon={Plus} onClick={() => setMode({ new: true })}>New template</Button>
    </PageHeader>
    <div className="status-tabs">{['', ...STATUSES].map((s) => <button key={s || 'all'} className={flt.status === s ? 'on' : ''} onClick={() => setFlt({ ...flt, status: s })}>
      {s ? s.replace('_', ' ').toLowerCase() : 'all'}<em>{s ? counts[s] || 0 : all.length}</em></button>)}</div>
    <div className={mode?.view ? 'grid-2-1' : ''}>
      <Card pad={false} title={<div className="filters">
        <div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search name" value={flt.q} onChange={(e) => setFlt({ ...flt, q: e.target.value })} /></div>
        <ClientSelect allowAll clients={clients} value={flt.client_id} onChange={(v) => setFlt({ ...flt, client_id: v })} />
        <select value={flt.category} onChange={(e) => setFlt({ ...flt, category: e.target.value })}><option value="">All categories</option><option>UTILITY</option><option>MARKETING</option><option>AUTHENTICATION</option></select>
        <select value={flt.language} onChange={(e) => setFlt({ ...flt, language: e.target.value })}><option value="">All languages</option>{langs.map((l) => <option key={l}>{l}</option>)}</select></div>}>
        <div className="table-wrap"><table className="table"><thead><tr><th>Template</th><th>Category</th><th>Status</th><th>Quality</th><th>Vars</th><th /></tr></thead>
          {rows === null ? <TableSkeleton cols={6} /> : all.length ? <tbody>{all.map((t) => <tr key={t.id} className={`clickable ${mode?.view?.id === t.id ? 'sel' : ''}`} onClick={() => view(t)}>
            <td><b>{t.name}</b><small className="block muted">{t.language}{t.client ? ` · ${t.client.name}` : ''}</small>{t.rejected_reason && <small className="block err-text">Rejected: {t.rejected_reason}</small>}</td>
            <td>{t.category}{t.previous_category && <small className="block muted">was {t.previous_category}</small>}</td>
            <td><Badge s={t.status} /></td><td>{t.quality_score ? <span className={`q ${t.quality_score.toLowerCase()}`}>● {t.quality_score}</span> : <span className="muted">—</span>}</td>
            <td>{t.body_variable_count}{t.header_format && t.header_format !== 'TEXT' ? ` + ${t.header_format.toLowerCase()}` : ''}{t.header_variable_count ? ' + hdr' : ''}</td>
            <td className="right nowrap" onClick={(e) => e.stopPropagation()}>
              <span className="tip-wrap" data-tip={t.status === 'APPROVED' ? null : `Only APPROVED templates can be tested (this one is ${t.status})`}>
                <Button size="sm" variant="ghost" icon={FlaskConical} disabled={t.status !== 'APPROVED'} title={t.status === 'APPROVED' ? 'Send a test with demo values' : undefined} onClick={() => setTesting(t)}>Test</Button></span>
              <Button size="sm" variant="ghost" icon={t.edit_check.allowed ? Pencil : Lock} title={t.edit_check.reason || 'Edit'} onClick={() => edit(t)} />
              <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => del(t)} /></td></tr>)}</tbody>
            : <tbody><tr><td colSpan={6}><Empty icon={FileText} title="No templates">Click “Sync from Meta” or create a new one.</Empty></td></tr></tbody>}
        </table></div>
      </Card>
      {testing && <TestSendModal template={testing} onClose={() => setTesting(null)} />}
    {mode?.view && <div className="sticky"><Card title={mode.view.name} icon={Eye} actions={<button className="icon-btn" onClick={() => setMode(null)}><X size={16} /></button>}>
        <div className="row wrap gap8"><Badge s={mode.view.status} /><UiBadge tone="orange">{mode.view.category}</UiBadge><UiBadge>{mode.view.language}</UiBadge>{mode.view.quality_score && <UiBadge>quality {mode.view.quality_score}</UiBadge>}</div>
        {mode.view.rejected_reason && <div className="alert error">Rejection reason: <b>{mode.view.rejected_reason}</b></div>}
        {!mode.view.edit_check.allowed && <div className="alert info"><Lock size={14} /> {mode.view.edit_check.reason}</div>}
        <Preview form={toForm(mode.view)} />
        <div className="row"><Button size="sm" icon={Pencil} onClick={() => edit(mode.view)} disabled={!mode.view.edit_check.allowed}>Edit</Button></div>
        <Field label="Client" hint="Shown in that client's portal and counted on the Clients page"><ClientSelect clients={clients} value={mode.view.client_id ? String(mode.view.client_id) : ''}
          onChange={async (v) => { try { const t = await api(`/templates/${mode.view.id}/client`, { method: 'PUT', body: { client_id: v || null } }); setMode({ view: { ...mode.view, client_id: t.client_id, client: t.client } }); toast.success('Client updated'); load() } catch (e) { toast.error(e.message) } }} /></Field>
        <h4 className="sub-h"><History size={14} /> Status history</h4>
        <ul className="timeline">{(mode.view.events || []).map((ev) => <li key={ev.id}><b>{ev.event}</b> <span className="muted">{ev.field.replace('message_template_', '').replace(/_/g, ' ')}</span>{ev.reason && <div className="small">{ev.reason}</div>}<small className="muted">{fmtTime(ev.created_at)}</small></li>)}</ul>
        {!mode.view.events?.length && <p className="muted small">No webhook events yet.</p>}
      </Card></div>}
    </div>
  </div>)
}

export function TemplateSender({ numbers }) {
  const [tpls, setTpls] = useState(null); const [from, setFrom] = useState(''); const [to, setTo] = useState('')
  const [tid, setTid] = useState(''); const [vals, setVals] = useState([]); const [hv, setHv] = useState(''); const [bv, setBv] = useState({})
  const [busy, setBusy] = useState(false); const toast = useToast()
  useEffect(() => { api('/templates?status=APPROVED').then(setTpls).catch(() => setTpls([])) }, [])
  const t = (tpls || []).find((x) => String(x.id) === String(tid))
  const form = t ? toForm(t) : null
  const urlBtns = form ? form.buttons.map((b, i) => [b, i]).filter(([b]) => b.type === 'URL' && countVars(b.url) > 0) : []
  const send = async (e) => {
    e.preventDefault(); setBusy(true)
    try { const m = await api('/messages/send-template', { method: 'POST', body: { phone_number_id: from, to, template_id: tid, params: vals.slice(0, t?.body_variable_count || 0), header_param: hv || null, button_params: bv } }); toast.success(`Template sent${m.wamid ? '' : ` (${m.status})`}`) }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  return (<div className="grid-2-1">
    <Card title="Send template" icon={FileText}>
      <form className="form" onSubmit={send}>
        <div className="form-2">
          <Field label="Sender number" icon={Phone}><select value={from} onChange={(e) => setFrom(e.target.value)} required><option value="">Select sender…</option>{numbers.map((n) => <option key={n.id} value={n.id}>{n.name ? `${n.name} — ` : ''}{n.display_number}</option>)}</select></Field>
          <Field label="Recipient" icon={Hash}><input placeholder="919876543210" value={to} onChange={(e) => setTo(e.target.value)} required /></Field>
        </div>
        <Field label="Approved template" hint={tpls && !tpls.length ? 'No approved templates. Sync them in Template Master.' : null}>
          <select value={tid} onChange={(e) => { setTid(e.target.value); setVals([]); setHv(''); setBv({}) }} required><option value="">{tpls === null ? 'Loading…' : 'Select template…'}</option>
            {(tpls || []).map((x) => <option key={x.id} value={x.id}>{x.name} ({x.language}) · {x.category}</option>)}</select></Field>
        {t && (t.header_variable_count > 0 || ['IMAGE', 'DOCUMENT', 'VIDEO'].includes(t.header_format)) &&
          <Field label={t.header_format === 'TEXT' ? 'Header {{1}}' : `Header ${t.header_format.toLowerCase()} URL or media id`}><input value={hv} onChange={(e) => setHv(e.target.value)} required /></Field>}
        {t && t.body_variable_count > 0 && <div className="form-2">{Array.from({ length: t.body_variable_count }, (_, i) => <Field key={i} label={`Body {{${i + 1}}}`}><input value={vals[i] || ''} onChange={(e) => { const v = [...vals]; v[i] = e.target.value; setVals(v) }} required /></Field>)}</div>}
        {urlBtns.map(([b, i]) => <Field key={i} label={`Button “${b.text}” URL suffix`}><input value={bv[i] || ''} onChange={(e) => setBv({ ...bv, [i]: e.target.value })} required /></Field>)}
        <div><Button type="submit" variant="primary" icon={Send} loading={busy} disabled={!t}>Send template</Button></div>
      </form>
    </Card>
    <Card title="Preview" icon={Eye}>{form ? <Preview form={form} headerVal={hv || undefined} bodyVals={vals.length ? vals : undefined} buttonVals={bv} /> : <Empty icon={FileText} title="Pick a template">Its preview appears here.</Empty>}</Card>
  </div>)
}

/** "Test" a template: pick FROM (connected number) and TO, preview the auto-filled demo values, send via Meta.
 *  base = '' (admin) or '/client' (client portal: own templates/numbers only). */
export function TestSendModal({ template, onClose, base = '', numbersPath = '/phone-numbers' }) {
  const toast = useToast()
  const [info, setInfo] = useState(null); const [numbers, setNumbers] = useState(null)
  const [from, setFrom] = useState(''); const [to, setTo] = useState('')
  const [busy, setBusy] = useState(false); const [result, setResult] = useState(null)
  useEffect(() => {
    api(`${base}/templates/${template.id}/test`).then(setInfo).catch((e) => { toast.error(e.message); onClose() })
    api(numbersPath).then((n) => { const list = (n.data || n).filter((x) => x.phone_number_id); setNumbers(list); if (list.length === 1) setFrom(String(list[0].id)) }).catch(() => setNumbers([]))
  }, [template.id]) // eslint-disable-line
  const send = async (e) => {
    e.preventDefault(); setBusy(true); setResult(null)
    try { const r = await api(`${base}/templates/${template.id}/test`, { method: 'POST', body: { phone_number_id: +from, to } }); setResult({ ok: true, ...r }); toast.success('Test message accepted by Meta') }
    catch (err) { setResult({ ok: false, message: err.data?.message || err.message, code: err.data?.error_code, log_id: err.data?.log_id, meta: err.data?.meta, errors: err.data?.errors }) }
    finally { setBusy(false) }
  }
  const p = info?.params
  const form = toForm(template)
  const bodyVals = p ? (Array.isArray(p.body) ? p.body : null) : null
  const named = p && !Array.isArray(p.body) ? Object.entries(p.body) : null
  const namedFill = (s) => named ? (s || '').replace(/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/gi, (m, k) => p.body[k] ?? m) : s
  const pform = named ? { ...form, body: { ...form.body, text: namedFill(form.body.text) }, header: { ...form.header, text: namedFill(form.header.text) } } : form
  const rows = p ? [
    ...(p.header ? [[`Header (${(info.template.header_format || 'text').toLowerCase()})`, p.header]] : []),
    ...(named ? named.map(([k, v]) => [`Body {{${k}}}`, v]) : (bodyVals || []).map((v, i) => [`Body {{${i + 1}}}`, v])),
    ...Object.entries(p.buttons || {}).map(([i, v]) => [`Button ${+i + 1} (${form.buttons[i]?.type?.toLowerCase().replace('_', ' ') || 'button'})`, v]),
  ] : []
  return (<Modal wide title={<span className="row gap8"><FlaskConical size={18} /> Test “{template.name}” <UiBadge>{template.language}</UiBadge></span>} onClose={onClose}
    footer={<><Button onClick={onClose}>Close</Button><Button type="submit" form="tpl-test-form" variant="primary" icon={Send} loading={busy} disabled={!info?.can_send || !from || !to}>Send test</Button></>}>
    {!info ? <div className="test-loading"><Spinner size={22} /></div> : <div className="test-grid">
      <form id="tpl-test-form" className="form" onSubmit={send}>
        <Field label="From number" icon={Phone} hint="Connected number from Number Master">{numbers === null ? <Spinner /> : numbers.length ? <select required value={from} onChange={(e) => setFrom(e.target.value)}>
          <option value="">Select a number…</option>{numbers.map((n) => <option key={n.id} value={n.id}>{n.display_number}{n.name ? ` · ${n.name}` : ''}</option>)}</select>
          : <div className="alert error">No connected numbers available.</div>}</Field>
        <Field label="To number" icon={Hash} hint="With country code, e.g. +91 98765 43210"><input required inputMode="tel" placeholder="+91 98765 43210" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
        <div><span className="field-label">Demo values that will be sent</span>
          {rows.length ? <table className="table kv-table"><tbody>{rows.map(([k, v]) => <tr key={k}><td className="muted nowrap">{k}</td><td className="break">{/^https?:/.test(v) ? <a href={v} target="_blank" rel="noreferrer">{v}</a> : <code>{v}</code>}</td></tr>)}</tbody></table>
            : <p className="muted small">This template has no variables; it is sent as-is.</p>}</div>
        {info.error && <div className="alert error"><AlertCircle size={16} /> {info.error}</div>}
        {!info.can_send && !info.error && <div className="alert info"><Lock size={14} /> Only APPROVED templates can be tested.</div>}
        <p className="muted small">This sends a real WhatsApp message through Meta and is logged in Chat. Meta may charge for it like any template message.</p>
        {result && (result.ok
          ? <div className="alert success"><CheckCircle2 size={16} /><div><b>Accepted by Meta.</b> Message id: <code className="break">{result.wamid}</code><div className="small muted">Sent from {result.from} to +{result.to}. Delivery status will appear in Chat.</div></div></div>
          : <div className="alert error"><AlertCircle size={16} /><div><b>Meta rejected the message{result.code ? ` (${result.code})` : ''}.</b><div className="break">{result.message}</div>
            {result.meta?.error_user_msg && result.meta.error_user_msg !== result.message && <div className="small">{result.meta.error_user_msg}</div>}
            {result.errors && <div className="small">{Object.values(result.errors).flat().join(' ')}</div>}
            {result.log_id && <div className="small muted">Error log ref #{result.log_id}</div>}</div></div>)}
      </form>
      <div><span className="field-label">Preview</span><Preview form={pform} headerVal={p?.header} bodyVals={bodyVals || undefined} buttonVals={p?.buttons} /></div>
    </div>}
  </Modal>)
}
