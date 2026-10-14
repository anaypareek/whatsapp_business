import { useCallback, useEffect, useRef, useState } from 'react'
import { api, setToken, fmtTime } from './api'
import { TemplateSender } from './templates'

function useLoad(path) {
  const [data, setData] = useState([]); const [err, setErr] = useState('')
  const reload = useCallback(() => api(path).then(setData).catch((e) => setErr(e.message)), [path])
  useEffect(() => { reload() }, [reload])
  return [data, reload, err, setErr]
}
const Msg = ({ ok, err }) => <>{ok && <div className="ok">{ok}</div>}{err && <div className="err">{err}</div>}</>

export function Login({ onLogin }) {
  const [email, setEmail] = useState(''); const [password, setPassword] = useState(''); const [err, setErr] = useState('')
  const submit = async (e) => {
    e.preventDefault(); setErr('')
    try { const r = await api('/auth/login', { method: 'POST', body: { email, password } }); setToken(r.token); onLogin(r.user) }
    catch (e) { setErr(e.message) }
  }
  return (
    <div className="center"><form className="card login" onSubmit={submit}>
      <h2>WhatsApp Business Portal</h2>
      <input placeholder="Email" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
      <input placeholder="Password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
      <button className="primary">Sign in</button><Msg err={err} />
    </form></div>
  )
}

export function Users({ user }) {
  const [rows, reload, err, setErr] = useLoad('/users')
  const [f, setF] = useState({ name: '', email: '', password: '', is_admin: false })
  const add = async (e) => { e.preventDefault(); setErr(''); try { await api('/users', { method: 'POST', body: f }); setF({ name: '', email: '', password: '', is_admin: false }); reload() } catch (e) { setErr(e.message) } }
  const del = async (u) => { if (confirm(`Delete ${u.email}?`)) { try { await api(`/users/${u.id}`, { method: 'DELETE' }); reload() } catch (e) { setErr(e.message) } } }
  return (<div><h2>Users</h2>
    <form className="row card" onSubmit={add}>
      <input placeholder="Name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
      <input placeholder="Email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
      <input placeholder="Password (min 8)" type="password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
      <label><input type="checkbox" checked={f.is_admin} onChange={(e) => setF({ ...f, is_admin: e.target.checked })} /> Admin</label>
      <button className="primary">Add user</button>
    </form><Msg err={err} />
    <table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th /></tr></thead><tbody>
      {rows.map((u) => <tr key={u.id}><td>{u.name}</td><td>{u.email}</td><td>{u.is_admin ? 'Admin' : 'User'}</td>
        <td>{u.id !== user.id && <button className="danger" onClick={() => del(u)}>Delete</button>}</td></tr>)}
    </tbody></table></div>)
}

export function Numbers({ user }) {
  const [rows, reload, err, setErr] = useLoad('/phone-numbers')
  const blank = { phone_number_id: '', display_number: '', name: '' }
  const [f, setF] = useState(blank); const [editId, setEditId] = useState(null); const [ok, setOk] = useState('')
  const save = async (e) => {
    e.preventDefault(); setErr(''); setOk('')
    try { await api(editId ? `/phone-numbers/${editId}` : '/phone-numbers', { method: editId ? 'PUT' : 'POST', body: f }); setF(blank); setEditId(null); reload() } catch (e) { setErr(e.message) }
  }
  const sync = async () => { setErr(''); setOk(''); try { const r = await api('/phone-numbers-sync', { method: 'POST' }); setOk(`Synced ${r.synced} number(s) from Meta`); reload() } catch (e) { setErr(e.message) } }
  const del = async (n) => { if (confirm(`Delete ${n.display_number}?`)) { await api(`/phone-numbers/${n.id}`, { method: 'DELETE' }); reload() } }
  const register = async (n) => {
    const pin = prompt('6-digit two-step verification PIN to register this number with Cloud API'); if (!pin) return
    try { await api(`/phone-numbers/${n.id}/register`, { method: 'POST', body: { pin } }); setOk('Registered') } catch (e) { setErr(e.message) }
  }
  return (<div><h2>Number Master</h2>
    <form className="row card" onSubmit={save}>
      <input placeholder="Phone number ID (Meta)" value={f.phone_number_id} onChange={(e) => setF({ ...f, phone_number_id: e.target.value })} />
      <input placeholder="Display number e.g. +91 98…" value={f.display_number} onChange={(e) => setF({ ...f, display_number: e.target.value })} />
      <input placeholder="Name" value={f.name || ''} onChange={(e) => setF({ ...f, name: e.target.value })} />
      <button className="primary">{editId ? 'Update' : 'Add'}</button>
      {editId && <button type="button" onClick={() => { setEditId(null); setF(blank) }}>Cancel</button>}
      <button type="button" onClick={sync}>⟳ Sync from Meta</button>
    </form><Msg ok={ok} err={err} />
    <table><thead><tr><th>Name</th><th>Display number</th><th>Phone number ID</th><th>WABA</th><th>Quality</th><th /></tr></thead><tbody>
      {rows.map((n) => <tr key={n.id}><td>{n.name}</td><td>{n.display_number}</td><td><code>{n.phone_number_id}</code></td><td>{n.waba_id}</td><td>{n.quality_rating}</td>
        <td className="actions"><button onClick={() => { setEditId(n.id); setF({ phone_number_id: n.phone_number_id, display_number: n.display_number, name: n.name || '' }) }}>Edit</button>
          {user.is_admin && <button onClick={() => register(n)}>Register</button>}
          <button className="danger" onClick={() => del(n)}>Delete</button></td></tr>)}
    </tbody></table></div>)
}

function Composer({ numbers, fixedNumber, fixedTo, onSent, compact }) {
  const [from, setFrom] = useState(''); const [to, setTo] = useState(''); const [text, setText] = useState('')
  const [file, setFile] = useState(null); const [busy, setBusy] = useState(false); const [err, setErr] = useState(''); const [ok, setOk] = useState('')
  const fileRef = useRef()
  const send = async (e) => {
    e.preventDefault(); setErr(''); setOk(''); setBusy(true)
    const fd = new FormData()
    fd.append('phone_number_id', fixedNumber ?? from); fd.append('to', fixedTo ?? to)
    if (text) fd.append('text', text); if (file) fd.append('file', file)
    try { const m = await api('/messages/send', { method: 'POST', form: fd }); setText(''); setFile(null); if (fileRef.current) fileRef.current.value = ''; setOk(`Sent (${m.wamid || m.status})`); onSent?.(m) }
    catch (e) { setErr(e.message) } finally { setBusy(false) }
  }
  return (
    <form className={compact ? 'composer' : 'card stack'} onSubmit={send}>
      {fixedNumber == null && <select value={from} onChange={(e) => setFrom(e.target.value)}><option value="">Select sender number…</option>
        {numbers.map((n) => <option key={n.id} value={n.id}>{n.name ? `${n.name} — ` : ''}{n.display_number}</option>)}</select>}
      {fixedTo == null && <input placeholder="Recipient (with country code, e.g. 919876543210)" value={to} onChange={(e) => setTo(e.target.value)} />}
      {compact ? <input className="grow" placeholder="Type a message" value={text} onChange={(e) => setText(e.target.value)} />
        : <textarea rows={4} placeholder="Message text / caption" value={text} onChange={(e) => setText(e.target.value)} />}
      <label className="attach" title="Attach image or file">📎<input ref={fileRef} type="file" hidden onChange={(e) => setFile(e.target.files[0] || null)} /></label>
      {file && <span className="chip">{file.name} <button type="button" className="link" onClick={() => { setFile(null); fileRef.current.value = '' }}>✕</button></span>}
      <button className="primary" disabled={busy}>{busy ? 'Sending…' : 'Send'}</button>
      {!compact && <Msg ok={ok} err={err} />}{compact && err && <div className="err small">{err}</div>}
    </form>)
}

export function SendMessage() {
  const [numbers] = useLoad('/phone-numbers')
  const [kind, setKind] = useState('free')
  return (<div><h2>Send Message</h2>
    <div className="row"><button className={kind === 'free' ? 'primary' : ''} onClick={() => setKind('free')}>Text / media</button><button className={kind === 'tpl' ? 'primary' : ''} onClick={() => setKind('tpl')}>Template</button></div>
    {kind === 'free' ? <><p className="muted">Free-form messages only deliver within 24h of the customer's last message. Attachments are uploaded to Meta (/media) first, then sent by media ID.</p><Composer numbers={numbers} /></>
      : <><p className="muted">Approved templates can be sent any time (business-initiated).</p><TemplateSender numbers={numbers} /></>}
  </div>)
}

const ticks = { pending: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠' }
function Bubble({ m }) {
  const media = m.media_url && (m.type === 'image'
    ? <a href={m.media_url} target="_blank" rel="noreferrer"><img src={m.media_url} alt="" /></a>
    : <a href={m.media_url} target="_blank" rel="noreferrer">📄 {m.filename || 'file'}</a>)
  return (<div className={`bubble ${m.direction}`}>
    {m.direction === 'in' && m.media_id && !m.media_url && <div className="muted">[{m.type}{m.filename ? `: ${m.filename}` : ''}]</div>}
    {media}{m.template_name && <div className="muted small">📋 {m.template_name}</div>}{m.body && <div className="text">{m.body}</div>}
    <div className="meta">{m.source === 'auto_reply' && 'auto · '}{m.source === 'api' && 'api · '}{fmtTime(m.created_at)}
      {m.direction === 'out' && <span className={m.status === 'read' ? 'read' : ''} title={m.error || m.status}> {ticks[m.status] || ''}</span>}</div>
  </div>)
}

export function Chat() {
  const [numbers] = useLoad('/phone-numbers')
  const [filter, setFilter] = useState('')
  const [convs, setConvs] = useState([]); const [sel, setSel] = useState(null); const [msgs, setMsgs] = useState([])
  const [search, setSearch] = useState(''); const [newTo, setNewTo] = useState('')
  const lastSeen = useRef({ id: 0, upd: null }); const endRef = useRef()
  const numName = (id) => { const n = numbers.find((x) => x.id === id); return n ? (n.name || n.display_number) : '—' }

  const loadConvs = useCallback(() => api(`/conversations${filter ? `?phone_number_id=${filter}` : ''}`).then(setConvs).catch(() => {}), [filter])
  const loadThread = useCallback(() => { if (sel) api(`/messages/thread?phone_number_id=${sel.phone_number_id}&contact=${encodeURIComponent(sel.contact_wa_id)}`).then(setMsgs).catch(() => {}) }, [sel])
  useEffect(() => { loadConvs() }, [loadConvs])
  useEffect(() => { loadThread() }, [loadThread])
  useEffect(() => { endRef.current?.scrollIntoView() }, [msgs.length])
  useEffect(() => { // poll every 3s; refetch only when something changed (new message or status update)
    const t = setInterval(async () => {
      try {
        const p = await api('/messages/poll')
        if (p.latest_id !== lastSeen.current.id || p.updated_at !== lastSeen.current.upd) { lastSeen.current = { id: p.latest_id, upd: p.updated_at }; loadConvs(); loadThread() }
      } catch { /* ignore */ }
    }, 3000)
    return () => clearInterval(t)
  }, [loadConvs, loadThread])

  const shown = convs.filter((c) => !search || c.contact_wa_id.includes(search) || (c.contact_name || '').toLowerCase().includes(search.toLowerCase()))
  const startNew = (e) => { e.preventDefault(); const nid = Number(filter) || numbers[0]?.id; if (!nid || !newTo) return; setSel({ phone_number_id: nid, contact_wa_id: newTo.replace(/\D/g, '') }); setNewTo('') }

  return (<div className="chat">
    <div className="convs">
      <div className="convhead">
        <select value={filter} onChange={(e) => setFilter(e.target.value)}><option value="">All numbers</option>{numbers.map((n) => <option key={n.id} value={n.id}>{n.name || n.display_number}</option>)}</select>
        <input placeholder="Search chats" value={search} onChange={(e) => setSearch(e.target.value)} />
        <form onSubmit={startNew} className="row"><input placeholder="New chat: 91…" value={newTo} onChange={(e) => setNewTo(e.target.value)} /><button>+</button></form>
      </div>
      {shown.map((c) => {
        const active = sel && sel.phone_number_id === c.phone_number_id && sel.contact_wa_id === c.contact_wa_id
        return (<div key={`${c.phone_number_id}-${c.contact_wa_id}`} className={`conv ${active ? 'active' : ''}`} onClick={() => setSel(c)}>
          <div className="avatar">{(c.contact_name || c.contact_wa_id).slice(0, 1).toUpperCase()}</div>
          <div className="grow"><div className="row between"><b>{c.contact_name || `+${c.contact_wa_id}`}</b><small>{fmtTime(c.last_message?.created_at)}</small></div>
            <div className="row between"><small className="muted ellipsis">{c.last_message?.direction === 'out' ? '↪ ' : ''}{c.last_message?.body || `[${c.last_message?.type}]`}</small>
              {c.unread > 0 && <span className="badge">{c.unread}</span>}</div>
            <small className="via">via {numName(c.phone_number_id)}</small></div>
        </div>)
      })}
      {!shown.length && <p className="muted pad">No conversations yet.</p>}
    </div>
    <div className="thread">
      {sel ? (<>
        <div className="threadhead"><b>{sel.contact_name || `+${sel.contact_wa_id}`}</b> <small className="muted">via {numName(sel.phone_number_id)}</small></div>
        <div className="msgs">{msgs.map((m) => <Bubble key={m.id} m={m} />)}<div ref={endRef} /></div>
        <Composer compact numbers={numbers} fixedNumber={sel.phone_number_id} fixedTo={sel.contact_wa_id} onSent={() => { loadThread(); loadConvs() }} />
      </>) : <div className="center muted">Select a conversation</div>}
    </div>
  </div>)
}

export function AutoReplies() {
  const [rows, reload, err, setErr] = useLoad('/auto-replies')
  const [numbers] = useLoad('/phone-numbers')
  const blank = { match_type: 'contains', trigger: '', reply: '', phone_number_id: '', enabled: true, priority: 0 }
  const [f, setF] = useState(blank); const [editId, setEditId] = useState(null)
  const save = async (e) => {
    e.preventDefault(); setErr('')
    const body = { ...f, phone_number_id: f.phone_number_id || null, priority: Number(f.priority) || 0 }
    try { await api(editId ? `/auto-replies/${editId}` : '/auto-replies', { method: editId ? 'PUT' : 'POST', body }); setF(blank); setEditId(null); reload() } catch (e) { setErr(e.message) }
  }
  const toggle = async (r) => { await api(`/auto-replies/${r.id}`, { method: 'PUT', body: { enabled: !r.enabled } }); reload() }
  const del = async (r) => { if (confirm('Delete rule?')) { await api(`/auto-replies/${r.id}`, { method: 'DELETE' }); reload() } }
  return (<div><h2>Auto-reply rules</h2>
    <p className="muted"><b>keyword</b>: whole-word match of any comma-separated word · <b>contains</b>: substring · <b>exact</b>: whole message. Case-insensitive. Highest priority wins.</p>
    <form className="card stack" onSubmit={save}>
      <div className="row">
        <select value={f.match_type} onChange={(e) => setF({ ...f, match_type: e.target.value })}><option value="keyword">keyword</option><option value="contains">contains</option><option value="exact">exact</option></select>
        <input className="grow" placeholder="Trigger" value={f.trigger} onChange={(e) => setF({ ...f, trigger: e.target.value })} />
        <select value={f.phone_number_id ?? ''} onChange={(e) => setF({ ...f, phone_number_id: e.target.value })}><option value="">All numbers</option>{numbers.map((n) => <option key={n.id} value={n.id}>{n.name || n.display_number}</option>)}</select>
        <input type="number" style={{ width: 80 }} title="Priority" value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value })} />
        <label><input type="checkbox" checked={f.enabled} onChange={(e) => setF({ ...f, enabled: e.target.checked })} /> Enabled</label>
      </div>
      <textarea rows={3} placeholder="Reply text" value={f.reply} onChange={(e) => setF({ ...f, reply: e.target.value })} />
      <div className="row"><button className="primary">{editId ? 'Update rule' : 'Add rule'}</button>{editId && <button type="button" onClick={() => { setEditId(null); setF(blank) }}>Cancel</button>}</div>
    </form><Msg err={err} />
    <table><thead><tr><th>On</th><th>Match</th><th>Trigger</th><th>Reply</th><th>Number</th><th>Prio</th><th /></tr></thead><tbody>
      {rows.map((r) => <tr key={r.id}><td><input type="checkbox" checked={r.enabled} onChange={() => toggle(r)} /></td><td>{r.match_type}</td><td>{r.trigger}</td><td className="wrap">{r.reply}</td>
        <td>{r.phone_number ? (r.phone_number.name || r.phone_number.display_number) : 'All'}</td><td>{r.priority}</td>
        <td className="actions"><button onClick={() => { setEditId(r.id); setF({ match_type: r.match_type, trigger: r.trigger, reply: r.reply, phone_number_id: r.phone_number_id ?? '', enabled: r.enabled, priority: r.priority }) }}>Edit</button>
          <button className="danger" onClick={() => del(r)}>Delete</button></td></tr>)}
    </tbody></table></div>)
}

export function Settings() {
  const [s, setS] = useState(null); const [ok, setOk] = useState(''); const [err, setErr] = useState('')
  useEffect(() => { api('/settings').then(setS).catch((e) => setErr(e.message)) }, [])
  if (!s) return <Msg err={err} />
  const save = async (e) => {
    e.preventDefault(); setOk(''); setErr('')
    try { const { app_id, app_secret, access_token, waba_id, verify_token } = s; setS(await api('/settings', { method: 'PUT', body: { app_id, app_secret, access_token, waba_id, verify_token } })); setOk('Saved (stored encrypted)') } catch (e) { setErr(e.message) }
  }
  const field = (k, label, secret) => (<label className="stack" key={k}>{label}
    <input type={secret ? 'password' : 'text'} placeholder={secret && s[`${k}_set`] ? 'Leave blank to keep current' : ''} value={secret && s[k]?.startsWith('••••') ? '' : s[k]} onChange={(e) => setS({ ...s, [k]: e.target.value })} />
    {secret && s[`${k}_set`] && <small className="muted">Current: {/^••••/.test(s[k]) ? s[k] : 'will be replaced'}</small>}</label>)
  return (<div><h2>Settings</h2>
    <form className="card stack" onSubmit={save} style={{ maxWidth: 640 }}>
      {field('app_id', 'Meta App ID')}{field('app_secret', 'App Secret (used to validate X-Hub-Signature-256)', true)}
      {field('access_token', 'Access token (System User permanent token recommended)', true)}{field('waba_id', 'WhatsApp Business Account (WABA) ID')}
      {field('verify_token', 'Webhook verify token (any random string; enter the same in Meta)')}
      <button className="primary">Save</button><Msg ok={ok} err={err} />
    </form>
    <div className="card"><b>Webhook callback URL</b> (must be public HTTPS): <code>{'https://<your-public-host>/api/webhook/whatsapp'}</code><br />
      <small className="muted">Local: {s.webhook_url} · Graph API {s.graph_version} · subscribe the app to the <code>messages</code> field.</small></div>
  </div>)
}

export function ApiKeys() {
  const [rows, reload, err, setErr] = useLoad('/api-keys')
  const [name, setName] = useState(''); const [plain, setPlain] = useState('')
  const add = async (e) => { e.preventDefault(); setErr(''); try { const r = await api('/api-keys', { method: 'POST', body: { name } }); setPlain(r.plain_key); setName(''); reload() } catch (e) { setErr(e.message) } }
  const del = async (k) => { if (confirm(`Revoke ${k.name}?`)) { await api(`/api-keys/${k.id}`, { method: 'DELETE' }); reload() } }
  return (<div><h2>API Keys</h2>
    <p className="muted">Used as <code>Authorization: Bearer &lt;key&gt;</code> on <code>POST /api/v1/messages/send</code> (e.g. Oswal Soap new-order alerts). Keys are stored hashed.</p>
    <form className="row card" onSubmit={add}><input placeholder="Key name, e.g. Oswal Soap orders" value={name} onChange={(e) => setName(e.target.value)} /><button className="primary">Generate key</button></form>
    {plain && <div className="ok">New key (copy now, shown once): <code>{plain}</code></div>}<Msg err={err} />
    <table><thead><tr><th>Name</th><th>Prefix</th><th>Created</th><th>Last used</th><th /></tr></thead><tbody>
      {rows.map((k) => <tr key={k.id}><td>{k.name}</td><td><code>{k.prefix}…</code></td><td>{fmtTime(k.created_at)}</td><td>{fmtTime(k.last_used_at) || '—'}</td><td><button className="danger" onClick={() => del(k)}>Revoke</button></td></tr>)}
    </tbody></table></div>)
}
