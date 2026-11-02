import { useEffect, useState } from 'react'
import { Hand, Users, Plus, Trash2, Pencil, Check, X, Moon } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Badge, Toggle, useToast, useLoad, Empty, Skeleton } from './ui'

export const UNSUPPORTED_NOTE = "This message type isn't supported by WhatsApp Business API (e.g. view-once, poll, edited message). Ask the sender to resend as text, photo or document."
const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']]
const pick = (s) => ({ enabled: !!s.enabled, mode: s.mode || 'every', greeting_text: s.greeting_text || '', image_url: s.image_url || '', cooldown_hours: s.cooldown_hours ?? 24,
  hours_enabled: !!s.hours_enabled, hours_start: s.hours_start || '09:00', hours_end: s.hours_end || '18:00', hours_days: s.hours_days || [1, 2, 3, 4, 5, 6], off_hours_text: s.off_hours_text || '' })

/** Greeting / off-hours auto-reply + contact groups. scope: 'admin' (all numbers) or 'client' (own numbers only). */
export function Greetings({ scope = 'admin' }) {
  const P = scope === 'client' ? '/client' : ''
  const [rows, reload, loading] = useLoad(`${P}/greetings`)
  const [sel, setSel] = useState(null)
  useEffect(() => { if (!sel && rows.length) setSel(rows[0].phone_number.id) }, [rows]) // eslint-disable-line
  const row = rows.find((r) => r.phone_number.id === sel)
  if (loading && !rows.length) return <Card title="Greeting message" icon={Hand}><Skeleton h={120} /></Card>
  if (!rows.length) return <Card title="Greeting message" icon={Hand}><Empty icon={Hand} title="No numbers">No WhatsApp numbers yet.</Empty></Card>
  return (<div className="greet">
    <div className="row" style={{ marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
      {rows.map((r) => <Button key={r.phone_number.id} size="sm" variant={r.phone_number.id === sel ? 'primary' : 'secondary'} onClick={() => setSel(r.phone_number.id)}>
        {r.phone_number.name || r.phone_number.display_number} {r.settings.enabled ? <Badge tone="green">on</Badge> : <Badge>off</Badge>}</Button>)}
    </div>
    {row && <div className="grid-form"><GreetingForm key={row.phone_number.id} P={P} row={row} reload={reload} /><Groups key={`g${row.phone_number.id}`} P={P} row={row} reload={reload} /></div>}
  </div>)
}

function GreetingForm({ P, row, reload }) {
  const toast = useToast(); const [f, setF] = useState(pick(row.settings)); const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await api(`${P}/greetings/${row.phone_number.id}`, { method: 'PUT', body: { ...f, image_url: f.image_url || null, cooldown_hours: Number(f.cooldown_hours) || 24 } }); toast.success('Greeting saved'); reload() }
    catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const day = (d) => setF({ ...f, hours_days: f.hours_days.includes(d) ? f.hours_days.filter((x) => x !== d) : [...f.hours_days, d].sort() })
  return (<Card title={`Greeting · ${row.phone_number.name || row.phone_number.display_number}`} icon={Hand}>
    <form className="form" onSubmit={save}>
      <div className="form-2">
        <div className="field"><span className="field-label">Greeting enabled</span><Toggle checked={f.enabled} onChange={(v) => setF({ ...f, enabled: v })} /></div>
        <Field label="Send to"><select value={f.mode} onChange={set('mode')}><option value="every">Every new conversation</option><option value="new_only">New customers only (first message ever)</option></select></Field>
      </div>
      <Field label="Default greeting text" hint="{name} = contact's WhatsApp name. Contacts in a group get that group's text."><textarea rows={3} value={f.greeting_text} onChange={set('greeting_text')} placeholder="Hi {name}! Thanks for contacting us. We'll reply shortly." /></Field>
      <div className="form-2">
        <Field label="Image URL (optional)" hint="Public .jpg/.png link, sent with the greeting as caption"><input value={f.image_url} onChange={set('image_url')} placeholder="https://…/welcome.jpg" /></Field>
        <Field label="Cooldown (hours)" hint="At most once per customer in this window; skipped if staff replied manually"><input type="number" min={1} value={f.cooldown_hours} onChange={set('cooldown_hours')} /></Field>
      </div>
      <div className="field"><span className="field-label"><Moon size={13} /> Business hours (Asia/Kolkata)</span><Toggle checked={f.hours_enabled} onChange={(v) => setF({ ...f, hours_enabled: v })} /></div>
      {f.hours_enabled && <>
        <div className="form-2"><Field label="Opens"><input type="time" value={f.hours_start} onChange={set('hours_start')} /></Field><Field label="Closes"><input type="time" value={f.hours_end} onChange={set('hours_end')} /></Field></div>
        <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>{DAYS.map(([d, l]) => <Button key={d} size="sm" variant={f.hours_days.includes(d) ? 'primary' : 'secondary'} onClick={() => day(d)}>{l}</Button>)}</div>
        <Field label="Off-hours text" hint="Sent instead of the greeting outside business hours (same cooldown)"><textarea rows={2} value={f.off_hours_text} onChange={set('off_hours_text')} placeholder="We're closed now, {name}. We'll reply from 9 AM." /></Field>
      </>}
      <p className="muted small">Order: keyword auto-replies → automation flows → greeting. One incoming message gets at most one auto-reply.</p>
      <div className="row"><Button type="submit" variant="primary" icon={Check} loading={busy}>Save greeting</Button></div>
    </form>
  </Card>)
}

function Groups({ P, row, reload }) {
  const toast = useToast(); const blank = { name: '', greeting_text: '', off_hours_text: '', priority: 0, members: [] }
  const [f, setF] = useState(null); const [num, setNum] = useState(''); const [busy, setBusy] = useState(false)
  const [contacts] = useLoad(`${P}/greetings/${row.phone_number.id}/contacts`)
  const add = (n) => { const d = (n || '').replace(/\D/g, ''); if (d.length >= 6 && !f.members.includes(d)) setF({ ...f, members: [...f.members, d] }); setNum('') }
  const save = async () => {
    setBusy(true)
    try {
      const body = { ...f, priority: Number(f.priority) || 0 }
      await api(f.id ? `${P}/greeting-groups/${f.id}` : `${P}/greetings/${row.phone_number.id}/groups`, { method: f.id ? 'PUT' : 'POST', body })
      toast.success('Group saved'); setF(null); reload()
    } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const del = async (g) => { if (!confirm(`Delete group “${g.name}”?`)) return; try { await api(`${P}/greeting-groups/${g.id}`, { method: 'DELETE' }); reload() } catch (e) { toast.error(e.message) } }
  return (<Card title={`Contact groups (${row.groups.length})`} icon={Users} actions={!f && <Button size="sm" icon={Plus} onClick={() => setF(blank)}>New group</Button>}>
    {f ? <div className="form">
      <div className="form-2"><Field label="Group name"><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Employees" /></Field>
        <Field label="Priority" hint="Higher wins if a contact is in several groups"><input type="number" min={0} value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value })} /></Field></div>
      <Field label="Group greeting" hint="Empty = use the default greeting"><textarea rows={2} value={f.greeting_text} onChange={(e) => setF({ ...f, greeting_text: e.target.value })} /></Field>
      <Field label="Group off-hours text (optional)"><textarea rows={2} value={f.off_hours_text} onChange={(e) => setF({ ...f, off_hours_text: e.target.value })} /></Field>
      <Field label={`Members (${f.members.length})`} hint="Type a number with country code, or pick an existing contact">
        <div className="row" style={{ gap: 6 }}><input value={num} onChange={(e) => setNum(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(num) } }} placeholder="91 98xxxxxxxx" />
          <Button size="sm" icon={Plus} onClick={() => add(num)}>Add</Button>
          <select value="" onChange={(e) => add(e.target.value)}><option value="">From contacts…</option>{contacts.filter((c) => !f.members.includes(c.contact_wa_id)).map((c) => <option key={c.contact_wa_id} value={c.contact_wa_id}>{c.contact_name ? `${c.contact_name} · ` : ''}+{c.contact_wa_id}</option>)}</select></div>
      </Field>
      <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>{f.members.map((m) => <span key={m} className="badge tone-gray">+{m} <X size={11} style={{ cursor: 'pointer' }} onClick={() => setF({ ...f, members: f.members.filter((x) => x !== m) })} /></span>)}</div>
      <div className="row"><Button variant="primary" icon={Check} loading={busy} onClick={save} disabled={!f.name.trim()}>Save group</Button><Button onClick={() => setF(null)}>Cancel</Button></div>
    </div>
    : row.groups.length ? <div className="table-wrap"><table className="table"><thead><tr><th>Group</th><th>Greeting</th><th>Members</th><th>Prio</th><th /></tr></thead><tbody>
      {row.groups.map((g) => <tr key={g.id}><td><b>{g.name}</b></td><td className="wrap">{g.greeting_text || <span className="muted">default</span>}</td><td>{g.members.length}</td><td>{g.priority}</td>
        <td className="right nowrap"><Button size="sm" variant="ghost" icon={Pencil} onClick={() => setF({ id: g.id, name: g.name, greeting_text: g.greeting_text || '', off_hours_text: g.off_hours_text || '', priority: g.priority, members: g.members.map((m) => m.contact_wa_id) })} />
          <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => del(g)} /></td></tr>)}</tbody></table></div>
    : <Empty icon={Users} title="No groups">Create groups like “Employees” or “Customers” to greet them differently.</Empty>}
  </Card>)
}
