import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, fmtTime } from './api'

export const LANGS = ['en_US', 'en', 'en_GB', 'hi', 'mr', 'gu', 'bn', 'ta', 'te', 'kn', 'ml', 'pa', 'ur', 'ar', 'es', 'fr', 'de', 'pt_BR', 'id']
const STATUSES = ['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL']
const fill = (s, vals) => (s || '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => vals?.[n - 1] || m)
const countVars = (s) => Math.max(0, ...[...(s || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map((m) => +m[1]))
const fmtWa = (s) => (s || '').split(/(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g).map((p, i) =>
  /^\*.+\*$/.test(p) ? <b key={i}>{p.slice(1, -1)}</b> : /^_.+_$/.test(p) ? <i key={i}>{p.slice(1, -1)}</i> : /^~.+~$/.test(p) ? <s key={i}>{p.slice(1, -1)}</s> : p)

export const Badge = ({ s }) => <span className={`tbadge ${(s || '').toLowerCase()}`}>{s}</span>

/** WhatsApp-style preview from a form object {header, body, footer, buttons} with variable values. */
export function Preview({ form, headerVal, bodyVals, buttonVals }) {
  const h = form.header || {}
  const fmt = h.format || 'NONE'
  return (<div className="wa-preview"><div className="bubble out tpl">
    {fmt === 'TEXT' && <div className="tpl-h">{fill(h.text, [headerVal ?? h.example])}</div>}
    {fmt === 'IMAGE' && (headerVal && /^https?:/.test(headerVal) ? <img src={headerVal} alt="" /> : <div className="tpl-media">🖼 Image</div>)}
    {fmt === 'VIDEO' && <div className="tpl-media">🎬 Video</div>}
    {fmt === 'DOCUMENT' && <div className="tpl-media">📄 Document</div>}
    <div className="text">{fmtWa(fill(form.body?.text, bodyVals ?? form.body?.examples)) || <span className="muted">Body text…</span>}</div>
    {form.footer && <div className="tpl-f">{form.footer}</div>}
    <div className="meta">{fmtTime(new Date().toISOString())}</div>
    {(form.buttons || []).map((b, i) => <div key={i} className="tpl-btn">{b.type === 'URL' ? '↗ ' : b.type === 'PHONE_NUMBER' ? '📞 ' : '↩ '}{b.text || 'Button'}
      {b.type === 'URL' && countVars(b.url) > 0 && <small className="muted"> {fill(b.url, [buttonVals?.[i] ?? b.example?.replace(/.*\//, '')])}</small>}</div>)}
  </div></div>)
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
const blankForm = () => ({ name: '', language: 'en_US', category: 'UTILITY', header: { format: 'NONE', text: '', example: '' }, body: { text: '', examples: [] }, footer: '', buttons: [] })

function TemplateForm({ initial, editing, onDone, onCancel }) {
  const [f, setF] = useState(initial)
  const [err, setErr] = useState(''); const [busy, setBusy] = useState(false)
  const n = countVars(f.body.text)
  const set = (patch) => setF({ ...f, ...patch })
  const setH = (patch) => set({ header: { ...f.header, ...patch } })
  const setBtn = (i, patch) => set({ buttons: f.buttons.map((b, j) => (j === i ? { ...b, ...patch } : b)) })
  const save = async (e) => {
    e.preventDefault(); setErr(''); setBusy(true)
    const body = { ...f, header: f.header.format === 'NONE' ? null : f.header, body: { text: f.body.text, examples: f.body.examples.slice(0, n) }, footer: f.footer || null }
    try { await api(editing ? `/templates/${editing.id}` : '/templates', { method: editing ? 'PUT' : 'POST', body }); onDone() }
    catch (e) { setErr(e.message) } finally { setBusy(false) }
  }
  return (<div className="tplform">
    <form className="card stack grow" onSubmit={save}>
      <h3>{editing ? `Edit ${editing.name} (${editing.language})` : 'New template'}</h3>
      {editing && <p className="muted small">Name and language can’t be changed. Approved templates: category locked, max 1 edit / 24h and 10 / 30 days. Edited templates go back to review.</p>}
      <div className="row">
        <input className="grow" placeholder="name (lowercase_with_underscores)" disabled={!!editing} value={f.name} onChange={(e) => set({ name: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} />
        <select disabled={!!editing} value={f.language} onChange={(e) => set({ language: e.target.value })}>{LANGS.map((l) => <option key={l}>{l}</option>)}</select>
        <select disabled={editing?.status === 'APPROVED'} value={f.category} onChange={(e) => set({ category: e.target.value })}><option>UTILITY</option><option>MARKETING</option><option>AUTHENTICATION</option></select>
      </div>
      <label>Header
        <div className="row"><select value={f.header.format} onChange={(e) => setH({ format: e.target.value })}>{['NONE', 'TEXT', 'IMAGE', 'DOCUMENT', 'VIDEO'].map((x) => <option key={x}>{x}</option>)}</select>
          {f.header.format === 'TEXT' && <input className="grow" maxLength={60} placeholder="Header text (max 60, one {{1}} allowed)" value={f.header.text} onChange={(e) => setH({ text: e.target.value })} />}
          {f.header.format === 'TEXT' && countVars(f.header.text) > 0 && <input placeholder="Example for {{1}}" value={f.header.example} onChange={(e) => setH({ example: e.target.value })} />}
          {['IMAGE', 'DOCUMENT', 'VIDEO'].includes(f.header.format) && <input className="grow" placeholder="Sample header_handle (from Meta Resumable Upload API)" value={f.header.example} onChange={(e) => setH({ example: e.target.value })} />}
        </div></label>
      <label>Body <small className="muted">({f.body.text.length}/1024) — use {'{{1}}'}, {'{{2}}'}… and *bold* _italic_</small>
        <textarea rows={5} maxLength={1024} value={f.body.text} onChange={(e) => set({ body: { ...f.body, text: e.target.value } })} /></label>
      {n > 0 && <div className="row">{Array.from({ length: n }, (_, i) => <input key={i} placeholder={`Example {{${i + 1}}}`} value={f.body.examples[i] || ''}
        onChange={(e) => { const ex = [...f.body.examples]; ex[i] = e.target.value; set({ body: { ...f.body, examples: ex } }) }} />)}</div>}
      <label>Footer (optional, max 60)<input maxLength={60} value={f.footer} onChange={(e) => set({ footer: e.target.value })} /></label>
      <div>Buttons <button type="button" disabled={f.buttons.length >= 10} onClick={() => set({ buttons: [...f.buttons, { type: 'QUICK_REPLY', text: '', url: '', phone_number: '', example: '' }] })}>+ Add</button></div>
      {f.buttons.map((b, i) => <div className="row" key={i}>
        <select value={b.type} onChange={(e) => setBtn(i, { type: e.target.value })}><option>QUICK_REPLY</option><option>URL</option><option>PHONE_NUMBER</option></select>
        <input maxLength={25} placeholder="Button text" value={b.text} onChange={(e) => setBtn(i, { text: e.target.value })} />
        {b.type === 'URL' && <input className="grow" placeholder="https://… (optional {{1}} at end)" value={b.url} onChange={(e) => setBtn(i, { url: e.target.value })} />}
        {b.type === 'URL' && countVars(b.url) > 0 && <input placeholder="Example full URL" value={b.example} onChange={(e) => setBtn(i, { example: e.target.value })} />}
        {b.type === 'PHONE_NUMBER' && <input placeholder="+919876543210" value={b.phone_number} onChange={(e) => setBtn(i, { phone_number: e.target.value })} />}
        <button type="button" className="danger" onClick={() => set({ buttons: f.buttons.filter((_, j) => j !== i) })}>✕</button></div>)}
      {err && <div className="err">{err}</div>}
      <div className="row"><button className="primary" disabled={busy}>{busy ? 'Submitting…' : editing ? 'Submit edit to Meta' : 'Submit to Meta for review'}</button><button type="button" onClick={onCancel}>Cancel</button></div>
    </form>
    <div><h4 className="muted">Preview</h4><Preview form={f} /></div>
  </div>)
}

export function Templates({ user }) {
  const [rows, setRows] = useState([]); const [flt, setFlt] = useState({ status: '', category: '', language: '', q: '' })
  const [mode, setMode] = useState(null) // null | {new} | {edit: t} | {view: t}
  const [ok, setOk] = useState(''); const [err, setErr] = useState('')
  const load = useCallback(() => { const qs = new URLSearchParams(Object.entries(flt).filter(([, v]) => v)).toString(); api(`/templates?${qs}`).then(setRows).catch((e) => setErr(e.message)) }, [flt])
  useEffect(() => { load() }, [load])
  useEffect(() => { const t = setInterval(load, 15000); return () => clearInterval(t) }, [load]) // picks up webhook status changes
  const sync = async () => { setOk(''); setErr(''); try { const r = await api('/templates-sync', { method: 'POST' }); setOk(`Synced ${r.synced} template(s) from Meta${r.removed ? `, removed ${r.removed}` : ''}`); load() } catch (e) { setErr(e.message) } }
  const del = async (t) => { if (!confirm(`Delete template ${t.name} (${t.language}) on Meta? The name can't be reused for 30 days.`)) return; try { await api(`/templates/${t.id}`, { method: 'DELETE' }); load() } catch (e) { setErr(e.message) } }
  const edit = (t) => { setErr(''); if (!t.edit_check.allowed) { setErr(`Edit not allowed: ${t.edit_check.reason}`); return } setMode({ edit: t }) }
  const view = async (t) => setMode({ view: await api(`/templates/${t.id}`) })
  const langs = useMemo(() => [...new Set(rows.map((r) => r.language))], [rows])

  if (mode?.new || mode?.edit) return <TemplateForm initial={mode.edit ? toForm(mode.edit) : blankForm()} editing={mode.edit}
    onDone={() => { setMode(null); setOk(mode.edit ? 'Edit submitted; template is back in review.' : 'Template submitted to Meta for review.'); load() }} onCancel={() => setMode(null)} />

  return (<div><h2>Template Master</h2>
    <div className="row card">
      <select value={flt.status} onChange={(e) => setFlt({ ...flt, status: e.target.value })}><option value="">All statuses</option>{STATUSES.map((s) => <option key={s}>{s}</option>)}</select>
      <select value={flt.category} onChange={(e) => setFlt({ ...flt, category: e.target.value })}><option value="">All categories</option><option>UTILITY</option><option>MARKETING</option><option>AUTHENTICATION</option></select>
      <select value={flt.language} onChange={(e) => setFlt({ ...flt, language: e.target.value })}><option value="">All languages</option>{langs.map((l) => <option key={l}>{l}</option>)}</select>
      <input placeholder="Search name" value={flt.q} onChange={(e) => setFlt({ ...flt, q: e.target.value })} />
      <button className="primary" onClick={() => setMode({ new: true })}>+ New template</button>
      <button onClick={sync}>⟳ Sync from Meta</button>
    </div>
    {ok && <div className="ok">{ok}</div>}{err && <div className="err">{err}</div>}
    <div className="split">
      <table><thead><tr><th>Name</th><th>Lang</th><th>Category</th><th>Status</th><th>Quality</th><th>Vars</th><th /></tr></thead><tbody>
        {rows.map((t) => <tr key={t.id} className={mode?.view?.id === t.id ? 'sel' : ''}>
          <td><a href="#" onClick={(e) => { e.preventDefault(); view(t) }}>{t.name}</a>{t.rejected_reason && <div className="err small">Rejected: {t.rejected_reason}</div>}</td>
          <td>{t.language}</td><td>{t.category}{t.previous_category && <small className="muted"> (was {t.previous_category})</small>}</td>
          <td><Badge s={t.status} /></td><td>{t.quality_score && <span className={`q ${t.quality_score.toLowerCase()}`}>●</span>} {t.quality_score || '—'}</td>
          <td>{t.body_variable_count}{t.header_format && t.header_format !== 'TEXT' ? ` + ${t.header_format.toLowerCase()}` : ''}{t.header_variable_count ? ' + hdr' : ''}</td>
          <td className="actions"><button onClick={() => edit(t)} title={t.edit_check.reason || 'Edit'} className={t.edit_check.allowed ? '' : 'disabled'}>Edit</button>
            <button className="danger" onClick={() => del(t)}>Delete</button></td></tr>)}
        {!rows.length && <tr><td colSpan={7} className="muted">No templates. Click “Sync from Meta” or create one.</td></tr>}
      </tbody></table>
      {mode?.view && <div className="card side">
        <div className="row between"><b>{mode.view.name}</b><button className="link" onClick={() => setMode(null)}>✕</button></div>
        <div><Badge s={mode.view.status} /> {mode.view.category} · {mode.view.language} · quality {mode.view.quality_score || '—'}</div>
        {mode.view.rejected_reason && <div className="err">Rejection reason: {mode.view.rejected_reason}</div>}
        {!mode.view.edit_check.allowed && <div className="muted small">Editing: {mode.view.edit_check.reason}</div>}
        <Preview form={toForm(mode.view)} />
        <h4>Status history</h4>
        {(mode.view.events || []).map((ev) => <div key={ev.id} className="small">{fmtTime(ev.created_at)} · {ev.field.replace('message_template_', '').replace(/_/g, ' ')}: <b>{ev.event}</b>{ev.reason ? ` — ${ev.reason}` : ''}</div>)}
        {!mode.view.events?.length && <div className="muted small">No webhook events yet.</div>}
      </div>}
    </div>
  </div>)
}

/** Template sender used on the Send Message page. */
export function TemplateSender({ numbers }) {
  const [tpls, setTpls] = useState([]); const [from, setFrom] = useState(''); const [to, setTo] = useState('')
  const [tid, setTid] = useState(''); const [vals, setVals] = useState([]); const [hv, setHv] = useState(''); const [bv, setBv] = useState({})
  const [ok, setOk] = useState(''); const [err, setErr] = useState(''); const [busy, setBusy] = useState(false)
  useEffect(() => { api('/templates?status=APPROVED').then(setTpls).catch(() => {}) }, [])
  const t = tpls.find((x) => String(x.id) === String(tid))
  const form = t ? toForm(t) : null
  const urlBtns = form ? form.buttons.map((b, i) => [b, i]).filter(([b]) => b.type === 'URL' && countVars(b.url) > 0) : []
  const send = async (e) => {
    e.preventDefault(); setOk(''); setErr(''); setBusy(true)
    try {
      const m = await api('/messages/send-template', { method: 'POST', body: { phone_number_id: from, to, template_id: tid, params: vals.slice(0, t?.body_variable_count || 0), header_param: hv || null, button_params: bv } })
      setOk(`Template sent (${m.wamid || m.status})`)
    } catch (e) { setErr(e.message) } finally { setBusy(false) }
  }
  return (<div className="tplform"><form className="card stack grow" onSubmit={send}>
    <select value={from} onChange={(e) => setFrom(e.target.value)}><option value="">Select sender number…</option>{numbers.map((n) => <option key={n.id} value={n.id}>{n.name ? `${n.name} — ` : ''}{n.display_number}</option>)}</select>
    <input placeholder="Recipient (e.g. 919876543210)" value={to} onChange={(e) => setTo(e.target.value)} />
    <select value={tid} onChange={(e) => { setTid(e.target.value); setVals([]); setHv(''); setBv({}) }}><option value="">Select an APPROVED template…</option>
      {tpls.map((x) => <option key={x.id} value={x.id}>{x.name} ({x.language}) · {x.category}</option>)}</select>
    {!tpls.length && <small className="muted">No approved templates. Sync them in Template Master.</small>}
    {t && (t.header_variable_count > 0 || ['IMAGE', 'DOCUMENT', 'VIDEO'].includes(t.header_format)) &&
      <input placeholder={t.header_format === 'TEXT' ? 'Header {{1}}' : `Header ${t.header_format.toLowerCase()} URL (public https) or media id`} value={hv} onChange={(e) => setHv(e.target.value)} />}
    {t && Array.from({ length: t.body_variable_count }, (_, i) => <input key={i} placeholder={`Body {{${i + 1}}}`} value={vals[i] || ''} onChange={(e) => { const v = [...vals]; v[i] = e.target.value; setVals(v) }} />)}
    {urlBtns.map(([b, i]) => <input key={i} placeholder={`Button “${b.text}” URL suffix {{1}}`} value={bv[i] || ''} onChange={(e) => setBv({ ...bv, [i]: e.target.value })} />)}
    <button className="primary" disabled={busy || !t}>{busy ? 'Sending…' : 'Send template'}</button>
    {ok && <div className="ok">{ok}</div>}{err && <div className="err">{err}</div>}
  </form>{form && <div><h4 className="muted">Preview</h4><Preview form={form} headerVal={hv || undefined} bodyVals={vals.length ? vals : undefined} buttonVals={bv} /></div>}</div>)
}
