import { useEffect, useMemo, useRef, useState } from 'react'
import { Briefcase, UserPlus, Pencil, KeyRound, Eye, EyeOff, Wand2, Copy, Search, Check, ChevronDown, X, Phone, FileText, Power, Mail, Building2, User, Lock } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, Toggle, useToast, useLoad, Modal, Avatar } from './ui'

export const genPassword = (n = 14) => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
  const a = new Uint32Array(n); crypto.getRandomValues(a)
  return Array.from(a, (x) => chars[x % chars.length]).join('')
}

/** Password input with show/hide toggle, generate and copy buttons. */
export function PasswordField({ label = 'Password', value, onChange, required, hint = 'At least 8 characters' }) {
  const [show, setShow] = useState(false); const toast = useToast()
  return (<Field label={label} hint={hint}>
    <div className="pw-row">
      <div className="input-ico grow"><Lock size={16} className="ico" />
        <input type={show ? 'text' : 'password'} value={value} onChange={(e) => onChange(e.target.value)} required={required} minLength={8} autoComplete="new-password" />
        <button type="button" className="eye" onClick={() => setShow(!show)} aria-label={show ? 'Hide password' : 'Show password'}>{show ? <EyeOff size={16} /> : <Eye size={16} />}</button></div>
      <Button icon={Wand2} title="Generate password" onClick={() => { onChange(genPassword()); setShow(true) }}>Generate</Button>
      {value && <Button icon={Copy} title="Copy" onClick={() => navigator.clipboard?.writeText(value).then(() => toast.success('Password copied'))} />}
    </div></Field>)
}

/** Searchable client dropdown. value: client id or '' (none). allowAll adds an "All clients" option for filters. */
export function ClientSelect({ value, onChange, clients, allowAll, placeholder = 'No client' }) {
  const [open, setOpen] = useState(false); const [q, setQ] = useState(''); const ref = useRef()
  useEffect(() => { const c = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false); document.addEventListener('mousedown', c); return () => document.removeEventListener('mousedown', c) }, [])
  const list = (clients || []).filter((c) => !q || `${c.name} ${c.company || ''}`.toLowerCase().includes(q.toLowerCase()))
  const cur = (clients || []).find((c) => String(c.id) === String(value))
  const label = value === 'none' ? 'Unassigned' : cur ? `${cur.name}${cur.company ? ` · ${cur.company}` : ''}` : allowAll ? 'All clients' : placeholder
  const pick = (v) => { onChange(v); setOpen(false); setQ('') }
  return (<div className="csel" ref={ref}>
    <button type="button" className={`csel-btn ${value ? 'has' : ''}`} onClick={() => setOpen(!open)}><Briefcase size={16} /><span className="ellipsis">{label}</span><ChevronDown size={16} /></button>
    {open && <div className="csel-pop">
      <div className="input-ico"><Search size={15} className="ico" /><input autoFocus placeholder="Search clients…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
      <ul>
        {allowAll && <li className={!value ? 'on' : ''} onClick={() => pick('')}>All clients</li>}
        {allowAll ? <li className={value === 'none' ? 'on' : ''} onClick={() => pick('none')}><em>Unassigned</em></li>
          : <li className={!value ? 'on' : ''} onClick={() => pick('')}><em>{placeholder}</em></li>}
        {list.map((c) => <li key={c.id} className={String(c.id) === String(value) ? 'on' : ''} onClick={() => pick(String(c.id))}>
          <b>{c.name}</b>{c.company && <small>{c.company}</small>}{!c.is_active && <Badge tone="gray">inactive</Badge>}{String(c.id) === String(value) && <Check size={14} />}</li>)}
        {!list.length && <li className="muted">No matches</li>}
      </ul></div>}
  </div>)
}

export function Clients() {
  const [flt, setFlt] = useState({ q: '', status: '' })
  const qs = new URLSearchParams(Object.entries(flt).filter(([, v]) => v)).toString()
  const [rows, reload, loading] = useLoad(`/clients?${qs}`); const toast = useToast()
  const blank = { name: '', company: '', email: '', password: '', is_active: true }
  const [f, setF] = useState(blank); const [busy, setBusy] = useState(false)
  const [edit, setEdit] = useState(null); const [reset, setReset] = useState(null); const [resetPw, setResetPw] = useState(''); const [shown, setShown] = useState(null)
  const add = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await api('/clients', { method: 'POST', body: f }); toast.success(`Client ${f.name} created`); setShown({ email: f.email, password: f.password }); setF(blank); reload() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const saveEdit = async () => { try { await api(`/clients/${edit.id}`, { method: 'PUT', body: { name: edit.name, company: edit.company, email: edit.email, is_active: edit.is_active } }); toast.success('Client updated'); setEdit(null); reload() } catch (e) { toast.error(e.message) } }
  const toggle = async (c) => { try { await api(`/clients/${c.id}`, { method: 'PUT', body: { is_active: !c.is_active } }); toast.success(c.is_active ? `${c.name} deactivated (logged out)` : `${c.name} activated`); reload() } catch (e) { toast.error(e.message) } }
  const doReset = async () => {
    try { const r = await api(`/clients/${reset.id}/reset-password`, { method: 'POST', body: resetPw ? { password: resetPw } : {} }); setShown({ email: reset.email, password: r.password }); toast.success('Password reset; client was logged out'); setReset(null); setResetPw('') }
    catch (e) { toast.error(e.message) }
  }
  const stats = useMemo(() => ({ total: rows.length, active: rows.filter((c) => c.is_active).length, numbers: rows.reduce((a, c) => a + c.phone_numbers_count, 0) }), [rows])

  return (<div className="page">
    <PageHeader icon={Briefcase} title="Clients" subtitle="Client accounts for the client portal, with their numbers and templates" />
    <div className="grid-form">
      <div className="stack-cards">
        <Card title="Add client" icon={UserPlus}>
          <form className="form" onSubmit={add}>
            <Field label="Name" icon={User}><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></Field>
            <Field label="Company" icon={Building2}><input value={f.company} onChange={(e) => setF({ ...f, company: e.target.value })} /></Field>
            <Field label="Email (login)" icon={Mail}><input type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} required /></Field>
            <PasswordField value={f.password} onChange={(v) => setF({ ...f, password: v })} required />
            <div className="row between"><span className="field-label">Status</span><div className="row"><span className="muted small">{f.is_active ? 'Active' : 'Inactive'}</span><Toggle checked={f.is_active} onChange={(v) => setF({ ...f, is_active: v })} /></div></div>
            <Button type="submit" variant="primary" icon={UserPlus} loading={busy}>Create client</Button>
          </form>
        </Card>
        <div className="mini-stats two"><div className="mini"><Briefcase size={18} /><b>{stats.active}/{stats.total}</b><span>Active clients</span></div><div className="mini"><Phone size={18} /><b>{stats.numbers}</b><span>Assigned numbers</span></div></div>
      </div>
      <Card pad={false} title={<div className="filters">
        <div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search name, company or email" value={flt.q} onChange={(e) => setFlt({ ...flt, q: e.target.value })} /></div>
        <select value={flt.status} onChange={(e) => setFlt({ ...flt, status: e.target.value })}><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>}>
        <div className="table-wrap"><table className="table"><thead><tr><th>Client</th><th>Numbers</th><th>Templates</th><th>Status</th><th>Last login</th><th /></tr></thead>
          {loading ? <TableSkeleton cols={6} /> : rows.length ? <tbody>{rows.map((c) => <tr key={c.id} className={c.is_active ? '' : 'dim'}>
            <td><div className="cell-user"><Avatar name={c.name} size={36} /><div><b>{c.name}</b><small>{c.company ? `${c.company} · ` : ''}{c.email}</small></div></div></td>
            <td>{c.phone_numbers_count ? <div className="num-chips">{c.phone_numbers.map((n) => <span key={n.id} className="num-chip" title={n.phone_number_id}><Phone size={11} />{n.display_number}</span>)}</div> : <span className="muted">—</span>}</td>
            <td><span className="count-pill"><FileText size={13} />{c.templates_count}</span></td>
            <td><Badge tone={c.is_active ? 'green' : 'gray'}>{c.is_active ? 'Active' : 'Inactive'}</Badge></td>
            <td className="muted nowrap">{c.last_login_at ? fmtTime(c.last_login_at) : 'Never'}</td>
            <td className="right nowrap">
              <Button size="sm" variant="ghost" icon={Pencil} title="Edit" onClick={() => setEdit({ ...c })} />
              <Button size="sm" variant="ghost" icon={KeyRound} title="Reset password" onClick={() => { setReset(c); setResetPw('') }} />
              <Button size="sm" variant={c.is_active ? 'ghost-danger' : 'ghost'} icon={Power} onClick={() => toggle(c)}>{c.is_active ? 'Deactivate' : 'Activate'}</Button></td></tr>)}</tbody>
            : <tbody><tr><td colSpan={6}><Empty icon={Briefcase} title="No clients yet">Create a client, then assign numbers to them in Number Master.</Empty></td></tr></tbody>}
        </table></div>
      </Card>
    </div>

    {edit && <Modal title={`Edit ${edit.name}`} onClose={() => setEdit(null)} footer={<><Button onClick={() => setEdit(null)}>Cancel</Button><Button variant="primary" icon={Check} onClick={saveEdit}>Save</Button></>}>
      <div className="form">
        <Field label="Name" icon={User}><input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
        <Field label="Company" icon={Building2}><input value={edit.company || ''} onChange={(e) => setEdit({ ...edit, company: e.target.value })} /></Field>
        <Field label="Email (login)" icon={Mail}><input type="email" value={edit.email} onChange={(e) => setEdit({ ...edit, email: e.target.value })} /></Field>
        <div className="row between"><span className="field-label">Active</span><Toggle checked={edit.is_active} onChange={(v) => setEdit({ ...edit, is_active: v })} /></div>
        <p className="muted small">Deactivating logs the client out immediately and blocks portal login.</p>
      </div></Modal>}

    {reset && <Modal title={`Reset password · ${reset.name}`} onClose={() => setReset(null)} footer={<><Button onClick={() => setReset(null)}>Cancel</Button><Button variant="primary" icon={KeyRound} onClick={doReset}>{resetPw ? 'Set password' : 'Generate & reset'}</Button></>}>
      <div className="form"><PasswordField label="New password" value={resetPw} onChange={setResetPw} hint="Leave empty to generate one on the server" />
        <p className="muted small">The client is logged out of all sessions.</p></div></Modal>}

    {shown && <Modal title="Client login details" onClose={() => setShown(null)} footer={<Button variant="primary" onClick={() => setShown(null)}>Done</Button>}>
      <div className="alert info"><Lock size={16} /> Copy and share these securely. The password won't be shown again.</div>
      <div className="cred"><span>Email</span><code>{shown.email}</code></div>
      <div className="cred"><span>Password</span><code>{shown.password}</code><Button size="sm" icon={Copy} onClick={() => navigator.clipboard?.writeText(`${shown.email} / ${shown.password}`).then(() => toast.success('Copied'))} /></div>
      <div className="cred"><span>Portal</span><code>{location.origin}/login</code></div>
    </Modal>}
  </div>)
}
