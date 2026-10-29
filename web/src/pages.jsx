import { WabaHealthBanner } from './apikeys'
import { EmojiButton, editAt, RichText } from './editor-tools'
import { InstagramSettings } from './instagram-settings'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Users as UsersIcon, UserPlus, Trash2, Phone, Plus, RefreshCw, Pencil, ShieldCheck, Send, Paperclip, X, FileText, Image as ImageIcon, Search,
  MessageSquarePlus, Check, CheckCheck, Clock, AlertCircle, Bot, Settings as SettingsIcon, KeyRound, Copy, Webhook, Lock, Hash, Building2, MessageCircle, Smile, Zap, Eye, EyeOff, Info, Bell } from 'lucide-react'
import { api, fmtTime, fmtShort } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, Toggle, useToast, useLoad, Modal, Avatar, Skeleton } from './ui'
import { TemplateSender } from './templates'
import { ClientSelect } from './clients'

// ---------------- Users ----------------
export function Users({ user }) {
  const [rows, reload, loading] = useLoad('/users'); const toast = useToast()
  const blank = { name: '', email: '', password: '', is_admin: false }
  const [f, setF] = useState(blank); const [busy, setBusy] = useState(false)
  const add = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await api('/users', { method: 'POST', body: f }); setF(blank); toast.success('User created'); reload() } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const del = async (u) => { if (!confirm(`Delete ${u.email}?`)) return; try { await api(`/users/${u.id}`, { method: 'DELETE' }); toast.success('User deleted'); reload() } catch (e) { toast.error(e.message) } }
  return (<div className="page">
    <PageHeader icon={UsersIcon} title="Users" subtitle="People who can sign in to this portal" />
    <div className="grid-form">
      <Card title="Add user" icon={UserPlus}>
        <form className="form" onSubmit={add}>
          <Field label="Full name"><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></Field>
          <Field label="Email"><input type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} required /></Field>
          <Field label="Password" hint="At least 8 characters"><input type="password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} required minLength={8} /></Field>
          <label className="check"><input type="checkbox" checked={f.is_admin} onChange={(e) => setF({ ...f, is_admin: e.target.checked })} /> Administrator (users, settings, API keys)</label>
          <Button type="submit" variant="primary" icon={UserPlus} loading={busy}>Create user</Button>
        </form>
      </Card>
      <Card title={`All users (${rows.length})`} pad={false}>
        <div className="table-wrap"><table className="table"><thead><tr><th>User</th><th>Role</th><th>Created</th><th /></tr></thead>
          {loading ? <TableSkeleton cols={4} /> : <tbody>{rows.map((u) => <tr key={u.id}>
            <td><div className="cell-user"><Avatar name={u.name} size={34} /><div><b>{u.name}</b><small>{u.email}</small></div></div></td>
            <td><Badge tone={u.is_admin ? 'orange' : 'gray'}>{u.is_admin ? 'Admin' : 'User'}</Badge></td><td className="muted">{fmtTime(u.created_at)}</td>
            <td className="right">{u.id !== user.id ? <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => del(u)}>Delete</Button> : <span className="muted small">You</span>}</td></tr>)}</tbody>}
        </table></div>
      </Card>
    </div>
  </div>)
}

// ---------------- Number Master ----------------
export function Numbers({ user }) {
  const [cf, setCf] = useState('')
  const [rows, reload, loading] = useLoad(`/phone-numbers${cf ? `?client_id=${cf}` : ''}`); const toast = useToast()
  const [clients] = useLoad('/clients-list')
  const blank = { phone_number_id: '', display_number: '', name: '', client_id: '' }
  const [f, setF] = useState(blank); const [editId, setEditId] = useState(null); const [busy, setBusy] = useState(false)
  const [reg, setReg] = useState(null); const [pin, setPin] = useState('')
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await api(editId ? `/phone-numbers/${editId}` : '/phone-numbers', { method: editId ? 'PUT' : 'POST', body: { ...f, client_id: f.client_id || null } }); toast.success(editId ? 'Number updated' : 'Number added'); setF(blank); setEditId(null); reload() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const sync = async () => { try { const r = await api('/phone-numbers-sync', { method: 'POST' }); toast.success(`Synced ${r.synced} number(s) from Meta`); reload() } catch (e) { toast.error(e.message) } }
  const del = async (n) => { if (!confirm(`Delete ${n.display_number}?`)) return; try { await api(`/phone-numbers/${n.id}`, { method: 'DELETE' }); toast.success('Number deleted'); reload() } catch (e) { toast.error(e.message) } }
  const [showPin, setShowPin] = useState(false); const [regBusy, setRegBusy] = useState(false); const [regErr, setRegErr] = useState(null); const [refreshing, setRefreshing] = useState(null)
  const openReg = (n) => { setReg(n); setPin(''); setShowPin(false); setRegErr(null) }
  const closeReg = () => { setReg(null); setPin(''); setRegErr(null) }
  const register = async (e) => {
    e?.preventDefault(); if (!/^\d{6}$/.test(pin)) return
    setRegBusy(true); setRegErr(null)
    try { const r = await api(`/phone-numbers/${reg.id}/register`, { method: 'POST', body: { pin } }); setPin(''); closeReg(); toast.success(`${r.message} Status: ${r.number?.status || 'unknown'}`); reload() }
    catch (err) { setPin(''); setRegErr({ message: err.data?.message || err.message, hint: err.data?.meta?.hint, code: err.data?.error_code, log_id: err.data?.log_id }) }
    finally { setRegBusy(false) }
  }
  const refresh = async (n) => { setRefreshing(n.id); try { const r = await api(`/phone-numbers/${n.id}/refresh`, { method: 'POST' }); toast.success(`${r.number.display_number}: ${r.number.status || 'status unknown'}${r.number.quality_rating ? ` · quality ${r.number.quality_rating}` : ''}`); reload() } catch (e) { toast.error(e.message) } finally { setRefreshing(null) } }
  const pending = rows.filter((n) => n.status && n.status !== 'CONNECTED')
  const [assign, setAssign] = useState(null) // {n, client_id}
  const doAssign = async () => {
    try { const r = await api(`/phone-numbers/${assign.n.id}/client`, { method: 'PUT', body: { client_id: assign.client_id || null } })
      toast.success(r.client ? `${r.display_number} assigned to ${r.client.name}: ${r.messages_count} existing message(s) now visible in their Chat` : `${r.display_number} unassigned`); setAssign(null); reload() }
    catch (e) { toast.error(e.message) }
  }
  const unassigned = rows.filter((n) => !n.client_id).length
  return (<div className="page">
    <PageHeader icon={Phone} title="Number Master" subtitle={`WhatsApp phone numbers linked to your WABA${!cf && unassigned ? ` · ${unassigned} not assigned to a client` : ''}`}><Button icon={RefreshCw} onClick={sync} title="Import every number already on the WABA (keeps existing client assignments)">Sync numbers from Meta</Button></PageHeader>
    <WabaHealthBanner />
    {pending.length > 0 && <div className="alert warn reg-banner"><AlertCircle size={18} /><div>
      <b>{pending.length === 1 ? `${pending[0].display_number} is ${pending[0].status}` : `${pending.length} numbers are not connected`}: messages from {pending.length === 1 ? 'it' : 'them'} will fail with “(#133010) Account not registered”.</b>
      <div>A number must be registered with the WhatsApp Cloud API before it can send. {user.is_admin ? <>Click <b>Register</b> on the number and enter a 6-digit PIN (the number's two-step verification PIN).</> : 'Ask an admin to register it.'} Use <b>Refresh status</b> to re-check with Meta.</div></div></div>}
    <div className="grid-form">
      <Card title={editId ? 'Edit number' : 'Add number'} icon={editId ? Pencil : Plus}>
        <form className="form" onSubmit={save}>
          <Field label="Phone number ID" hint="From Meta → WhatsApp → API Setup" icon={Hash}><input value={f.phone_number_id} onChange={(e) => setF({ ...f, phone_number_id: e.target.value })} required /></Field>
          <Field label="Display number" icon={Phone}><input placeholder="+91 98765 43210" value={f.display_number} onChange={(e) => setF({ ...f, display_number: e.target.value })} required /></Field>
          <Field label="Name" icon={Building2}><input placeholder="e.g. Sales" value={f.name || ''} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <Field label="Client" hint="Which client sees this number in their portal"><ClientSelect clients={clients} value={f.client_id} onChange={(v) => setF({ ...f, client_id: v })} /></Field>
          <div className="row"><Button type="submit" variant="primary" icon={editId ? Check : Plus} loading={busy}>{editId ? 'Update' : 'Add number'}</Button>
            {editId && <Button onClick={() => { setEditId(null); setF(blank) }}>Cancel</Button>}</div>
        </form>
      </Card>
      <Card title={`Numbers (${rows.length})`} pad={false} actions={<div className="filter-w"><ClientSelect allowAll clients={clients} value={cf} onChange={setCf} /></div>}>
        <div className="table-wrap"><table className="table"><thead><tr><th>Number</th><th>Client</th><th>Phone number ID</th><th>Quality</th><th>Status</th><th /></tr></thead>
          {loading ? <TableSkeleton cols={6} /> : rows.length ? <tbody>{rows.map((n) => <tr key={n.id}>
            <td><div className="cell-user"><span className="ico-tile"><Phone size={16} /></span><div><b>{n.display_number}</b><small>{n.name || '—'}</small></div></div></td>
            <td>{n.client ? <div className="client-cell"><b>{n.client.name}</b><small>{n.client.company || ''}{!n.client.is_active && ' · inactive'}</small></div> : <span className="client-chip none">Unassigned</span>}
              <button type="button" className="link-btn" onClick={() => setAssign({ n, client_id: n.client_id ? String(n.client_id) : '' })}>{n.client ? 'Change' : 'Assign client'}</button></td>
            <td><code>{n.phone_number_id}</code></td>
            <td>{n.quality_rating ? <Badge tone={{ GREEN: 'green', YELLOW: 'amber', RED: 'red' }[n.quality_rating] || 'gray'}>{n.quality_rating}</Badge> : <span className="muted">—</span>}</td>
            <td>{n.status ? <Badge tone={n.status === 'CONNECTED' ? 'green' : ['PENDING', 'UNVERIFIED', 'MIGRATED'].includes(n.status) ? 'amber' : ['BANNED', 'FLAGGED', 'RESTRICTED', 'DISCONNECTED', 'DELETED'].includes(n.status) ? 'red' : 'gray'}>{n.status}</Badge> : <span className="muted">—</span>}
              {(n.platform_type || n.name_status) && <small className="block muted">{[n.platform_type && n.platform_type.replace(/_/g, ' ').toLowerCase(), n.name_status && `name ${n.name_status.replace(/_/g, ' ').toLowerCase()}`].filter(Boolean).join(' · ')}</small>}</td>
            <td className="right nowrap">
              {user.is_admin && n.status !== 'CONNECTED' && <Button size="sm" variant="primary" icon={ShieldCheck} onClick={() => openReg(n)}>Register</Button>}
              <Button size="sm" variant="ghost" icon={RefreshCw} loading={refreshing === n.id} title="Refresh status from Meta" onClick={() => refresh(n)} />
              <Button size="sm" variant="ghost" icon={Pencil} onClick={() => { setEditId(n.id); setF({ phone_number_id: n.phone_number_id, display_number: n.display_number, name: n.name || '', client_id: n.client_id ? String(n.client_id) : '' }) }} />
              {user.is_admin && n.status === 'CONNECTED' && <Button size="sm" variant="ghost" icon={ShieldCheck} title="Register again (e.g. after a PIN change or migration)" onClick={() => openReg(n)} />}
              <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => del(n)} /></td></tr>)}</tbody>
            : <tbody><tr><td colSpan={6}><Empty icon={Phone} title={cf ? 'No numbers for this filter' : 'No numbers yet'}>Add one manually or click “Sync from Meta”.</Empty></td></tr></tbody>}
        </table></div>
      </Card>
    </div>
    {assign && <Modal title={`Assign ${assign.n.display_number}`} onClose={() => setAssign(null)}
      footer={<><Button onClick={() => setAssign(null)}>Cancel</Button><Button variant="primary" icon={Check} onClick={doAssign}>{assign.client_id ? 'Assign' : 'Unassign'}</Button></>}>
      <div className="form"><Field label="Client"><ClientSelect clients={clients} value={assign.client_id} onChange={(v) => setAssign({ ...assign, client_id: v })} /></Field>
        <div className="alert info"><Info size={15} /><div className="small">All chats on this number, <b>past and future</b>, appear in the client's portal Chat and mobile app right away. Usage on the number is billed to the assigned client.</div></div></div>
    </Modal>}
    {reg && <Modal title={`Register ${reg.display_number}`} onClose={closeReg} footer={<><Button onClick={closeReg}>Cancel</Button>
      <Button type="submit" form="reg-form" variant="primary" icon={ShieldCheck} loading={regBusy} disabled={!/^\d{6}$/.test(pin)}>Register with Cloud API</Button></>}>
      <form id="reg-form" className="form" onSubmit={register} autoComplete="off">
        <div className="reg-facts"><div><small>Phone number ID</small><code>{reg.phone_number_id}</code></div><div><small>Current status</small><Badge tone={reg.status === 'CONNECTED' ? 'green' : 'amber'}>{reg.status || 'unknown'}</Badge></div>
          {reg.waba_id && <div><small>WABA</small><code>{reg.waba_id}</code></div>}</div>
        <Field label="6-digit PIN" icon={Lock} hint="Choose a 6-digit PIN. If this number already has two-step verification, enter that existing PIN.">
          <div className="pin-wrap"><input className="pin-input" type={showPin ? 'text' : 'password'} inputMode="numeric" pattern="\d{6}" maxLength={6} autoComplete="new-password" placeholder="••••••"
            value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 6))} autoFocus aria-invalid={pin.length > 0 && pin.length < 6} />
            <button type="button" className="icon-btn" title={showPin ? 'Hide PIN' : 'Show PIN'} onClick={() => setShowPin(!showPin)}>{showPin ? <EyeOff size={17} /> : <Eye size={17} />}</button></div>
          {pin.length > 0 && pin.length < 6 && <small className="err-text">{6 - pin.length} more digit{6 - pin.length > 1 ? 's' : ''} needed</small>}</Field>
        <div className="alert info"><Info size={15} /><div className="small">This calls Meta's <code>POST /{reg.phone_number_id}/register</code> and then refreshes the number's status. The PIN becomes the number's two-step verification PIN; it is not stored by this portal, so keep it somewhere safe.</div></div>
        {regErr && <div className="alert error"><AlertCircle size={16} /><div><b>Meta refused the registration{regErr.code ? ` (${regErr.code})` : ''}.</b><div className="break">{regErr.message}</div>
          {regErr.log_id && <div className="small muted">Error log ref #{regErr.log_id}</div>}</div></div>}
      </form>
    </Modal>}
  </div>)
}

// ---------------- Composer (shared) ----------------
function Composer({ numbers, fixedNumber, fixedTo, onSent, compact, sendPath = '/messages/send' }) {
  const [from, setFrom] = useState(''); const [to, setTo] = useState(''); const [text, setText] = useState('')
  const [file, setFile] = useState(null); const [busy, setBusy] = useState(false); const toast = useToast()
  const fileRef = useRef(); const textRef = useRef()
  const addEmoji = (em) => editAt(textRef, text, setText, (v, a, b) => ({ text: v.slice(0, a) + em + v.slice(b), start: a + em.length, end: a + em.length }))
  const clearFile = () => { setFile(null); if (fileRef.current) fileRef.current.value = '' }
  const send = async (e) => {
    e?.preventDefault(); if (!text && !file) return; setBusy(true)
    const fd = new FormData()
    fd.append('phone_number_id', fixedNumber ?? from); fd.append('to', fixedTo ?? to)
    if (text) fd.append('text', text); if (file) fd.append('file', file)
    try { const m = await api(sendPath, { method: 'POST', form: fd }); setText(''); clearFile(); if (!compact) toast.success(`Message sent${m.wamid ? '' : ` (${m.status})`}`); onSent?.(m) }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const chip = file && <span className="file-chip">{file.type.startsWith('image/') ? <ImageIcon size={14} /> : <FileText size={14} />}{file.name}<button type="button" onClick={clearFile}><X size={12} /></button></span>
  if (compact) return (<form className="composer" onSubmit={send}>
    {chip && <div className="composer-file">{chip}</div>}
    <div className="composer-row">
      <EmojiButton up className="icon-btn" size={20} onPick={addEmoji} />
      <label className="icon-btn" title="Attach image or file"><Paperclip size={20} /><input ref={fileRef} type="file" hidden onChange={(e) => setFile(e.target.files[0] || null)} /></label>
      <input ref={textRef} className="composer-input" placeholder="Type a message" value={text} onChange={(e) => setText(e.target.value)} />
      <Button type="submit" variant="primary" className="send-btn" icon={Send} loading={busy} disabled={!text && !file} />
    </div></form>)
  return (<form className="form" onSubmit={send}>
    <div className="form-2">
      <Field label="Sender number" icon={Phone}><select value={from} onChange={(e) => setFrom(e.target.value)} required><option value="">Select sender…</option>
        {numbers.map((n) => <option key={n.id} value={n.id}>{n.name ? `${n.name} — ` : ''}{n.display_number}</option>)}</select></Field>
      <Field label="Recipient" hint="With country code, e.g. 919876543210" icon={Hash}><input value={to} onChange={(e) => setTo(e.target.value)} required /></Field>
    </div>
    <Field label="Message / caption"><div className="editor-box"><div className="fmt-toolbar"><EmojiButton onPick={addEmoji} className="tb-btn" /></div><textarea ref={textRef} rows={6} placeholder="Write your message…" value={text} onChange={(e) => setText(e.target.value)} /></div></Field>
    <div className="row between">
      <div className="row"><label className="btn btn-secondary"><Paperclip size={16} /><span>Attach image / file</span><input ref={fileRef} type="file" hidden onChange={(e) => setFile(e.target.files[0] || null)} /></label>{chip}</div>
      <Button type="submit" variant="primary" icon={Send} loading={busy} disabled={!text && !file}>Send message</Button>
    </div>
  </form>)
}

// ---------------- Send Message ----------------
export function SendMessage() {
  const [numbers] = useLoad('/phone-numbers')
  const [kind, setKind] = useState('free')
  return (<div className="page">
    <PageHeader icon={Send} title="Send Message" subtitle="Send a free-form message or an approved template">
      <div className="seg"><button className={kind === 'free' ? 'on' : ''} onClick={() => setKind('free')}><MessageCircle size={16} /> Text / media</button>
        <button className={kind === 'tpl' ? 'on' : ''} onClick={() => setKind('tpl')}><FileText size={16} /> Template</button></div>
    </PageHeader>
    {kind === 'free' ? <div className="grid-2-1">
      <Card title="Compose" icon={Send}><Composer numbers={numbers} /></Card>
      <Card title="Good to know" icon={AlertCircle}><ul className="tips">
        <li><Clock size={16} /><span><b>24-hour window.</b> Free-form messages only reach customers who messaged you in the last 24 hours. Use a template otherwise.</span></li>
        <li><ImageIcon size={16} /><span><b>Images</b> must be JPEG/PNG (≤5 MB). Other files are sent as documents (≤100 MB).</span></li>
        <li><Zap size={16} /><span>Attachments are uploaded to Meta first, then sent by media ID.</span></li>
      </ul></Card>
    </div> : <TemplateSender numbers={numbers} />}
  </div>)
}

// ---------------- Chat Master ----------------
const TICK = { pending: <Clock size={13} />, sent: <Check size={14} />, delivered: <CheckCheck size={14} />, read: <CheckCheck size={14} />, failed: <AlertCircle size={13} /> }
function Bubble({ m }) {
  const media = m.media_url && (m.type === 'image'
    ? <a href={m.media_url} target="_blank" rel="noreferrer"><img src={m.media_url} alt="" /></a>
    : <a className="doc" href={m.media_url} target="_blank" rel="noreferrer"><FileText size={18} />{m.filename || 'file'}</a>)
  return (<div className={`msg ${m.direction}`}><div className="bubble">
    {m.template_name && <div className="tpl-tag"><FileText size={12} /> {m.template_name}</div>}
    {m.direction === 'in' && m.media_id && !m.media_url && <div className="muted small">[{m.type}{m.filename ? `: ${m.filename}` : ''}]</div>}
    {media}{m.body && <div className="text">{m.body}</div>}
    <div className="meta">{m.source === 'auto_reply' && <span className="src"><Bot size={11} /> auto</span>}{m.source === 'api' && <span className="src">API</span>}
      {m.source === 'test' && <span className="src">test</span>}{fmtShort(m.created_at)}{m.direction === 'out' && <span className={`tick ${m.status}`} title={m.error || m.status}>{TICK[m.status]}</span>}</div>
    {m.status === 'failed' && m.error && <div className="msg-err"><AlertCircle size={12} /> {m.error}</div>}
  </div></div>)
}

const ADMIN_EP = { numbers: '/phone-numbers', conversations: '/conversations', thread: '/messages/thread', poll: '/messages/poll', send: '/messages/send' }
export function Chat({ ep = ADMIN_EP }) {
  const [numbers] = useLoad(ep.numbers)
  const [filter, setFilter] = useState('')
  const [convs, setConvs] = useState(null); const [sel, setSel] = useState(null); const [msgs, setMsgs] = useState(null)
  const [search, setSearch] = useState(''); const [newTo, setNewTo] = useState(''); const [showNew, setShowNew] = useState(false)
  const lastSeen = useRef({ id: 0, upd: null }); const msgsRef = useRef()
  const numName = (id) => { const n = numbers.find((x) => x.id === id); return n ? (n.name || n.display_number) : '—' }

  const loadConvs = useCallback(() => api(`${ep.conversations}${filter ? `?phone_number_id=${filter}` : ''}`).then(setConvs).catch(() => {}), [filter])
  const loadThread = useCallback(() => { if (sel) api(`${ep.thread}?phone_number_id=${sel.phone_number_id}&contact=${encodeURIComponent(sel.contact_wa_id)}`).then(setMsgs).catch(() => {}) }, [sel])
  useEffect(() => { loadConvs() }, [loadConvs])
  useEffect(() => { setMsgs(null); loadThread() }, [loadThread])
  useEffect(() => { const el = msgsRef.current; if (el) el.scrollTop = el.scrollHeight }, [msgs?.length])
  useEffect(() => {
    const t = setInterval(async () => {
      try {
        const p = await api(ep.poll)
        if (p.latest_id !== lastSeen.current.id || p.updated_at !== lastSeen.current.upd) { lastSeen.current = { id: p.latest_id, upd: p.updated_at }; loadConvs(); loadThread() }
      } catch { /* ignore */ }
    }, 3000)
    return () => clearInterval(t)
  }, [loadConvs, loadThread])

  const shown = (convs || []).filter((c) => !search || c.contact_wa_id.includes(search) || (c.contact_name || '').toLowerCase().includes(search.toLowerCase()))
  const startNew = (e) => { e.preventDefault(); const nid = Number(filter) || numbers[0]?.id; if (!nid || !newTo) return; setSel({ phone_number_id: nid, contact_wa_id: newTo.replace(/\D/g, '') }); setNewTo(''); setShowNew(false) }
  const title = sel ? (sel.contact_name || convs?.find((c) => c.contact_wa_id === sel.contact_wa_id)?.contact_name || `+${sel.contact_wa_id}`) : ''

  return (<div className={`chat ${sel ? 'has-sel' : ''}`}>
    <aside className="chat-list">
      <div className="chat-list-head">
        <div className="row between"><h2>Chats</h2><button className="icon-btn" title="New chat" onClick={() => setShowNew(!showNew)}><MessageSquarePlus size={20} /></button></div>
        {showNew && <form onSubmit={startNew} className="new-chat"><input autoFocus placeholder="Number with country code" value={newTo} onChange={(e) => setNewTo(e.target.value)} /><Button type="submit" variant="primary" size="sm">Start</Button></form>}
        <div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search or start a new chat" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
        <div className="chips"><button className={!filter ? 'on' : ''} onClick={() => setFilter('')}>All</button>
          {numbers.map((n) => <button key={n.id} className={String(filter) === String(n.id) ? 'on' : ''} onClick={() => setFilter(String(n.id))}>{n.name || n.display_number}</button>)}</div>
      </div>
      <div className="chat-items">
        {convs === null ? [1, 2, 3, 4, 5].map((i) => <div key={i} className="conv"><Skeleton w={44} h={44} r={22} /><div className="grow"><Skeleton w="60%" /><Skeleton w="85%" style={{ marginTop: 8 }} /></div></div>)
          : shown.map((c) => {
            const active = sel && sel.phone_number_id === c.phone_number_id && sel.contact_wa_id === c.contact_wa_id
            return (<div key={`${c.phone_number_id}-${c.contact_wa_id}`} className={`conv ${active ? 'active' : ''}`} onClick={() => setSel(c)}>
              <Avatar name={c.contact_name || c.contact_wa_id} size={46} />
              <div className="grow"><div className="row between"><b className="ellipsis">{c.contact_name || `+${c.contact_wa_id}`}</b><small className={c.unread ? 'hot' : 'muted'}>{fmtShort(c.last_message?.created_at)}</small></div>
                <div className="row between"><span className="muted ellipsis">{c.last_message?.direction === 'out' && <span className={`tick ${c.last_message.status}`}>{TICK[c.last_message.status]}</span>}{c.last_message?.body || `[${c.last_message?.type}]`}</span>
                  {c.unread > 0 && <em className="pill">{c.unread}</em>}</div>
                <small className="via">via {numName(c.phone_number_id)}</small></div>
            </div>)
          })}
        {convs && !shown.length && <Empty icon={MessageCircle} title="No conversations">Incoming messages appear here automatically.</Empty>}
      </div>
    </aside>
    <section className="thread">
      {sel ? (<>
        <header className="thread-head"><button className="icon-btn back" onClick={() => setSel(null)}>←</button><Avatar name={title} size={40} />
          <div className="grow"><b>{title}</b><small className="muted">+{sel.contact_wa_id} · via {numName(sel.phone_number_id)}</small></div></header>
        <div className="msgs" ref={msgsRef}>{msgs === null ? <div className="center-pad"><Skeleton w="40%" h={36} /><Skeleton w="55%" h={36} style={{ alignSelf: 'flex-end' }} /></div>
          : msgs.map((m) => <Bubble key={m.id} m={m} />)}</div>
        <Composer compact sendPath={ep.send} numbers={numbers} fixedNumber={sel.phone_number_id} fixedTo={sel.contact_wa_id} onSent={() => { loadThread(); loadConvs() }} />
      </>) : <div className="thread-empty"><span className="te-ico"><MessageCircle size={40} /></span><h3>WhatsApp Business Inbox</h3><p>Select a conversation to read and reply.<br />New messages arrive automatically every few seconds.</p></div>}
    </section>
  </div>)
}

// ---------------- Auto-replies ----------------
export function AutoReplies() {
  const [rows, reload, loading] = useLoad('/auto-replies')
  const [numbers] = useLoad('/phone-numbers'); const toast = useToast()
  const blank = { match_type: 'contains', trigger: '', reply: '', phone_number_id: '', enabled: true, priority: 0 }
  const [f, setF] = useState(blank); const [editId, setEditId] = useState(null); const [busy, setBusy] = useState(false)
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    const body = { ...f, phone_number_id: f.phone_number_id || null, priority: Number(f.priority) || 0 }
    try { await api(editId ? `/auto-replies/${editId}` : '/auto-replies', { method: editId ? 'PUT' : 'POST', body }); toast.success(editId ? 'Rule updated' : 'Rule added'); setF(blank); setEditId(null); reload() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const toggle = async (r) => { try { await api(`/auto-replies/${r.id}`, { method: 'PUT', body: { enabled: !r.enabled } }); reload() } catch (e) { toast.error(e.message) } }
  const del = async (r) => { if (!confirm('Delete rule?')) return; try { await api(`/auto-replies/${r.id}`, { method: 'DELETE' }); toast.success('Rule deleted'); reload() } catch (e) { toast.error(e.message) } }
  return (<div className="page">
    <PageHeader icon={Bot} title="Auto-replies" subtitle="Reply automatically when an incoming message matches a rule" />
    <div className="grid-form">
      <Card title={editId ? 'Edit rule' : 'New rule'} icon={editId ? Pencil : Plus}>
        <form className="form" onSubmit={save}>
          <div className="form-2">
            <Field label="Match type"><select value={f.match_type} onChange={(e) => setF({ ...f, match_type: e.target.value })}><option value="keyword">Keyword (whole word)</option><option value="contains">Contains</option><option value="exact">Exact message</option></select></Field>
            <Field label="Number"><select value={f.phone_number_id ?? ''} onChange={(e) => setF({ ...f, phone_number_id: e.target.value })}><option value="">All numbers</option>{numbers.map((n) => <option key={n.id} value={n.id}>{n.name || n.display_number}</option>)}</select></Field>
          </div>
          <Field label="Trigger" hint={f.match_type === 'keyword' ? 'Comma-separated words, e.g. price, rate' : 'Case-insensitive'}><input value={f.trigger} onChange={(e) => setF({ ...f, trigger: e.target.value })} required /></Field>
          <Field label="Reply"><RichText rows={4} value={f.reply} onChange={(t) => setF({ ...f, reply: t })} required /></Field>
          <div className="form-2">
            <Field label="Priority" hint="Higher wins"><input type="number" min={0} value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value })} /></Field>
            <div className="field"><span className="field-label">Enabled</span><Toggle checked={f.enabled} onChange={(v) => setF({ ...f, enabled: v })} /></div>
          </div>
          <div className="row"><Button type="submit" variant="primary" icon={editId ? Check : Plus} loading={busy}>{editId ? 'Update rule' : 'Add rule'}</Button>{editId && <Button onClick={() => { setEditId(null); setF(blank) }}>Cancel</Button>}</div>
        </form>
      </Card>
      <Card title={`Rules (${rows.length})`} pad={false}>
        <div className="table-wrap"><table className="table"><thead><tr><th>On</th><th>Trigger</th><th>Reply</th><th>Number</th><th>Prio</th><th /></tr></thead>
          {loading ? <TableSkeleton cols={6} /> : rows.length ? <tbody>{rows.map((r) => <tr key={r.id} className={r.enabled ? '' : 'dim'}>
            <td><Toggle checked={r.enabled} onChange={() => toggle(r)} /></td>
            <td><Badge tone="orange">{r.match_type}</Badge> <b>{r.trigger}</b></td><td className="wrap">{r.reply}</td>
            <td>{r.phone_number ? (r.phone_number.name || r.phone_number.display_number) : <span className="muted">All</span>}</td><td>{r.priority}</td>
            <td className="right nowrap"><Button size="sm" variant="ghost" icon={Pencil} onClick={() => { setEditId(r.id); setF({ match_type: r.match_type, trigger: r.trigger, reply: r.reply, phone_number_id: r.phone_number_id ?? '', enabled: r.enabled, priority: r.priority }) }} />
              <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => del(r)} /></td></tr>)}</tbody>
            : <tbody><tr><td colSpan={6}><Empty icon={Bot} title="No rules yet">Create a rule like “contains: price → send price list”.</Empty></td></tr></tbody>}
        </table></div>
      </Card>
    </div>
  </div>)
}

// ---------------- Settings ----------------
function HealthCheckButton() {
  const toast = useToast(); const [busy, setBusy] = useState(false)
  const run = async () => { setBusy(true); try { const h = await api('/waba-health/refresh', { method: 'POST' }); if (h.fetch_error) toast.error(`Health check failed: ${h.fetch_error}`); else if (h.blocked) toast.error(`Meta reports can_send_message = ${h.can_send_message}`); else toast.success(`WABA healthy (${h.can_send_message || 'AVAILABLE'})`); window.dispatchEvent(new Event('waba-health')) } catch (e) { toast.error(e.message) } finally { setBusy(false) } }
  return <Button icon={RefreshCw} loading={busy} onClick={run}>Check WABA health</Button>
}

export function Settings() {
  const [healthTick, setHealthTick] = useState(0)
  useEffect(() => { const f = () => setHealthTick((t) => t + 1); window.addEventListener('waba-health', f); return () => window.removeEventListener('waba-health', f) }, [])
  const [s, setS] = useState(null); const [busy, setBusy] = useState(false); const toast = useToast()
  useEffect(() => { api('/settings').then(setS).catch((e) => toast.error(e.message)) }, []) // eslint-disable-line
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    try { const { app_id, app_secret, access_token, waba_id, verify_token } = s; setS(await api('/settings', { method: 'PUT', body: { app_id, app_secret, access_token, waba_id, verify_token } })); toast.success('Settings saved (encrypted)') }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const copy = (t) => navigator.clipboard?.writeText(t).then(() => toast.success('Copied'))
  const field = (k, label, icon, secret, hint) => (<Field key={k} label={label} icon={icon} hint={secret && s[`${k}_set`] ? `Saved: ${/^••••/.test(s[k]) ? s[k] : 'will be replaced'} — leave blank to keep` : hint}>
    <input type={secret ? 'password' : 'text'} value={secret && s[k]?.startsWith('••••') ? '' : s[k]} onChange={(e) => setS({ ...s, [k]: e.target.value })} /></Field>)
  const hook = s ? `${location.origin}/api/webhook/whatsapp` : ''
  return (<div className="page">
    <PageHeader icon={SettingsIcon} title="Settings" subtitle="Meta WhatsApp Cloud API credentials, stored encrypted"><HealthCheckButton /></PageHeader>
    <WabaHealthBanner key={healthTick} />
    {!s ? <div className="grid-2-1"><Card><Skeleton h={320} /></Card><Card><Skeleton h={200} /></Card></div> :
      <div className="grid-2-1">
        <Card title="Meta app credentials" icon={Lock}>
          <form className="form" onSubmit={save}>
            <div className="form-2">{field('app_id', 'Meta App ID', Hash)}{field('waba_id', 'WhatsApp Business Account ID', Building2)}</div>
            {field('app_secret', 'App secret', Lock, true, 'Validates X-Hub-Signature-256 on webhooks')}
            {field('access_token', 'Access token', KeyRound, true, 'System User permanent token recommended')}
            {field('verify_token', 'Webhook verify token', ShieldCheck, false, 'Any random string; enter the same value in Meta')}
            <div><Button type="submit" variant="primary" icon={Check} loading={busy}>Save settings</Button></div>
          </form>
        </Card>
        <div className="stack-cards">
          <Card title="Webhook" icon={Webhook}>
            <Field label="Callback URL"><div className="copy-row"><input readOnly value={hook} /><Button icon={Copy} onClick={() => copy(hook)} /></div></Field>
            <p className="muted small">Subscribe the app to: <code>messages</code>, <code>message_template_status_update</code>, <code>message_template_quality_update</code>, <code>template_category_update</code>.</p>
          </Card>
          <Card title="Status" icon={AlertCircle}><ul className="checklist">
            <li className={s.app_id ? 'ok' : ''}><Check size={14} /> App ID</li><li className={s.waba_id ? 'ok' : ''}><Check size={14} /> WABA ID</li>
            <li className={s.app_secret_set ? 'ok' : ''}><Check size={14} /> App secret</li><li className={s.access_token_set ? 'ok' : ''}><Check size={14} /> Access token</li>
            <li className={s.verify_token ? 'ok' : ''}><Check size={14} /> Verify token</li></ul>
            <p className="muted small">Graph API {s.graph_version}</p></Card>
        </div>
      </div>}
    {s && <InstagramSettings />}
    {s && <PushSettings />}
  </div>)
}

/** Admin → Settings → Push Notifications (Firebase): upload google-services.json + service-account key, send a test push. */
function PushSettings() {
  const toast = useToast()
  const [p, setP] = useState(null); const [busy, setBusy] = useState(''); const [clients] = useLoad('/clients-list'); const [cid, setCid] = useState('')
  const load = () => api('/push-settings').then(setP).catch((e) => toast.error(e.message))
  useEffect(() => { load() }, []) // eslint-disable-line
  const upload = async (kind, file) => {
    if (!file) return; setBusy(kind)
    const form = new FormData(); form.append('file', file)
    try { setP(await api(`/push-settings/${kind}`, { method: 'POST', form })); toast.success(kind === 'client-config' ? 'google-services.json uploaded' : 'Service account key uploaded') }
    catch (e) { toast.error(e.message) } finally { setBusy('') }
  }
  const remove = async (what) => { if (!confirm('Remove this file? Push notifications will stop.')) return; try { setP(await api('/push-settings', { method: 'DELETE', body: { what } })); toast.success('Removed') } catch (e) { toast.error(e.message) } }
  const test = async () => { setBusy('test'); try { const r = await api('/push-settings/test', { method: 'POST', body: { client_id: +cid } }); toast.success(r.message) } catch (e) { toast.error(e.message) } finally { setBusy('') } }
  const Pick = ({ kind, label }) => <label className={`btn btn-secondary btn-sm file-btn ${busy === kind ? 'loading' : ''}`}><input type="file" accept=".json,application/json" hidden onChange={(e) => { upload(kind, e.target.files[0]); e.target.value = '' }} />{busy === kind ? 'Uploading…' : label}</label>
  if (!p) return <Card title="Push Notifications (Firebase)" icon={Bell}><Skeleton h={120} /></Card>
  return (<Card title="Push Notifications (Firebase)" icon={Bell} className="push-card">
    <p className="muted small">The Android app (<code>{p.package_name}</code>) gets its Firebase settings from the server at runtime, so no app rebuild is needed. Steps: <code>docs/FIREBASE-SETUP.md</code>.</p>
    <div className="push-grid">
      <div className={`push-item ${p.client_config.uploaded ? 'ok' : ''}`}>
        <div className="row between"><b>1. google-services.json</b>{p.client_config.uploaded ? <Badge tone="green">uploaded</Badge> : <Badge tone="amber">missing</Badge>}</div>
        <p className="muted small">From Firebase → Project settings → Your apps → Android app <code>{p.package_name}</code>. Public client settings only.</p>
        {p.client_config.uploaded && <div className="small">Project <b>{p.client_config.project_id}</b> · sender {p.client_config.sender_id}<div className="muted break">{p.client_config.app_id}</div></div>}
        <div className="row gap8"><Pick kind="client-config" label={p.client_config.uploaded ? 'Replace file' : 'Upload google-services.json'} />
          {p.client_config.uploaded && <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => remove('client_config')} />}</div>
      </div>
      <div className={`push-item ${p.service_account.uploaded ? 'ok' : ''}`}>
        <div className="row between"><b>2. Service account key</b>{p.service_account.uploaded ? <Badge tone="green">uploaded</Badge> : <Badge tone="amber">missing</Badge>}</div>
        <p className="muted small">Firebase → Project settings → Service accounts → Generate new private key. Stored encrypted on the server and never shown again.</p>
        {p.service_account.uploaded && <div className="small">Project <b>{p.service_account.project_id}</b><div className="muted break">{p.service_account.client_email}</div>{p.service_account.source === 'env' && <div className="muted">Using FCM_CREDENTIALS_PATH from .env</div>}</div>}
        <div className="row gap8"><Pick kind="service-account" label={p.service_account.uploaded ? 'Replace key' : 'Upload key (.json)'} />
          {p.service_account.uploaded && p.service_account.source !== 'env' && <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => remove('service_account')} />}</div>
      </div>
    </div>
    {p.project_mismatch && <div className="alert error"><AlertCircle size={16} /> The two files are from different Firebase projects. Upload both from the same project.</div>}
    <div className="push-test">
      <span className={`dot ${p.enabled ? 'on' : ''}`} /><span><b>{p.enabled ? 'Push enabled' : 'Push disabled'}</b> · {p.devices} device{p.devices === 1 ? '' : 's'} registered ({p.clients_with_devices} client{p.clients_with_devices === 1 ? '' : 's'})</span>
      <div className="grow" />
      <div className="push-client"><ClientSelect clients={clients} value={cid} onChange={setCid} placeholder="Choose client…" /></div>
      <Button icon={Send} variant="primary" disabled={!p.enabled || !cid} loading={busy === 'test'} onClick={test}>Send test push</Button>
    </div>
  </Card>)
}

// ---------------- API Keys ----------------
