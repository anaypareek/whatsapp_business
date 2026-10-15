// Admin: Number Requests and Template Requests review pages.
import { useState } from 'react'
import { PhoneCall, FileText, Check, X, Search, Eye, Pencil, RotateCw, AlertTriangle, KeyRound, Hash } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, useToast, useLoad, Modal } from './ui'
import { Preview, TemplateForm } from './templates'

export const REQ_STATUSES = ['pending', 'approved', 'rejected']
/** Stored request (name/language/category + builder form) -> builder form object. */
export const reqToForm = (r) => ({
  name: r.name, language: r.language, category: r.category,
  header: r.form?.header ? { format: r.form.header.format || 'NONE', text: r.form.header.text || '', example: r.form.header.example || '' } : { format: 'NONE', text: '', example: '' },
  body: { text: r.form?.body?.text || '', examples: r.form?.body?.examples || [] },
  footer: r.form?.footer || '',
  buttons: (r.form?.buttons || []).map((b) => ({ type: b.type, text: b.text || '', url: b.url || '', phone_number: b.phone_number || '', example: b.example || '' })),
})

export const RegBadge = ({ s }) => s ? <Badge tone={s === 'registered' ? 'green' : 'red'}>{s === 'registered' ? 'Registered on Meta' : 'Registration failed'}</Badge> : null

function StatusTabs({ value, onChange, rows }) {
  const counts = (rows || []).reduce((a, r) => ({ ...a, [r.status]: (a[r.status] || 0) + 1 }), {})
  return <div className="status-tabs">{['', ...REQ_STATUSES].map((s) => <button key={s || 'all'} className={value === s ? 'on' : ''} onClick={() => onChange(s)}>
    {s || 'all'}<em>{s ? counts[s] || 0 : (rows || []).length}</em></button>)}</div>
}

function RejectModal({ title, onClose, onReject }) {
  const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false)
  return <Modal title={title} onClose={onClose} footer={<><Button onClick={onClose}>Cancel</Button>
    <Button variant="primary" icon={X} loading={busy} disabled={!reason.trim()} onClick={async () => { setBusy(true); try { await onReject(reason) } finally { setBusy(false) } }}>Reject</Button></>}>
    <Field label="Reason" hint="Shown to the client"><textarea rows={4} autoFocus value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field></Modal>
}

export function NumberRequests({ onChange }) {
  const [rows, reload, loading] = useLoad('/number-requests'); const toast = useToast()
  const [st, setSt] = useState('pending'); const [q, setQ] = useState('')
  const [approve, setApprove] = useState(null); const [reject, setReject] = useState(null); const [busy, setBusy] = useState(false)
  const done = () => { reload(); onChange?.() }
  const list = rows.filter((r) => (!st || r.status === st) && (!q || `${r.phone_number} ${r.display_name} ${r.business_name} ${r.client?.name}`.toLowerCase().includes(q.toLowerCase())))
  const doApprove = async () => {
    setBusy(true)
    try {
      const path = approve.retry ? `/number-requests/${approve.id}/register` : `/number-requests/${approve.id}/approve`
      const r = await api(path, { method: 'POST', body: approve.retry ? { pin: approve.pin } : { phone_number_id: approve.phone_number_id, waba_id: approve.waba_id || null, pin: approve.pin } })
      r.registration_status === 'registered' ? toast.success('Approved and registered on Meta') : toast.error(`Number created, but Meta registration failed: ${r.registration_error}`)
      setApprove(null); done()
    } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  return (<div className="page">
    <PageHeader icon={PhoneCall} title="Number Requests" subtitle="Phone numbers requested by clients. Approving creates the number for the client and registers it on Meta." />
    <StatusTabs value={st} onChange={setSt} rows={rows} />
    <Card pad={false} title={<div className="filters"><div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search number, name, business or client" value={q} onChange={(e) => setQ(e.target.value)} /></div></div>}>
      <div className="table-wrap"><table className="table"><thead><tr><th>Requested number</th><th>Client</th><th>Business</th><th>Status</th><th>Requested</th><th /></tr></thead>
        {loading ? <TableSkeleton cols={6} /> : list.length ? <tbody>{list.map((r) => <tr key={r.id}>
          <td><b className="nowrap">{r.phone_number}</b><small className="block muted">{r.display_name}</small></td>
          <td><b className="nowrap">{r.client?.name}</b>{r.client?.company && <small className="block muted nowrap">{r.client.company}</small>}</td>
          <td>{r.business_name}<small className="block muted">{r.category || '—'}</small>{r.notes && <small className="block muted ellipsis" style={{ maxWidth: 260 }} title={r.notes}>“{r.notes}”</small>}</td>
          <td><Badge s={r.status} />{r.status === 'approved' && <div style={{ marginTop: 4 }}><RegBadge s={r.registration_status} /></div>}
            {r.registration_error && <small className="block err-text">{r.registration_error}</small>}
            {r.rejection_reason && <small className="block err-text">Reason: {r.rejection_reason}</small>}
            {r.created_number && <small className="block muted">ID {r.created_number.phone_number_id}</small>}</td>
          <td className="muted nowrap">{fmtTime(r.created_at)}{r.reviewed_at && <small className="block">reviewed {fmtTime(r.reviewed_at)}</small>}</td>
          <td className="right nowrap">{r.status === 'pending' && <>
            <Button size="sm" variant="primary" icon={Check} onClick={() => setApprove({ id: r.id, req: r, phone_number_id: '', waba_id: '', pin: '' })}>Approve</Button>{' '}
            <Button size="sm" variant="ghost-danger" icon={X} onClick={() => setReject(r)}>Reject</Button></>}
            {r.status === 'approved' && r.registration_status === 'failed' && <Button size="sm" icon={RotateCw} onClick={() => setApprove({ id: r.id, req: r, retry: true, pin: '' })}>Retry registration</Button>}</td>
        </tr>)}</tbody> : <tbody><tr><td colSpan={6}><Empty icon={PhoneCall} title={st === 'pending' ? 'No pending requests' : 'No requests'}>Client requests for new WhatsApp numbers appear here.</Empty></td></tr></tbody>}
      </table></div></Card>

    {approve && <Modal title={approve.retry ? `Retry registration · ${approve.req.phone_number}` : `Approve ${approve.req.phone_number}`} onClose={() => setApprove(null)}
      footer={<><Button onClick={() => setApprove(null)}>Cancel</Button><Button variant="primary" icon={Check} loading={busy} onClick={doApprove}
        disabled={!/^\d{6}$/.test(approve.pin) || (!approve.retry && !approve.phone_number_id)}>{approve.retry ? 'Register' : 'Approve & register'}</Button></>}>
      <div className="form">
        {!approve.retry && <div className="alert info"><AlertTriangle size={16} /> Creates the number for <b>&nbsp;{approve.req.client?.name}&nbsp;</b> as “{approve.req.display_name}”, then calls Meta's register endpoint with the PIN. Add the number to your WABA in Meta first to get its Phone number ID.</div>}
        {!approve.retry && <Field label="Meta Phone number ID" icon={Hash} hint="WhatsApp Manager → Phone numbers"><input value={approve.phone_number_id} onChange={(e) => setApprove({ ...approve, phone_number_id: e.target.value.trim() })} autoFocus /></Field>}
        {!approve.retry && <Field label="WABA ID (optional)" hint="Defaults to the WABA in Settings"><input value={approve.waba_id} onChange={(e) => setApprove({ ...approve, waba_id: e.target.value.trim() })} /></Field>}
        <Field label="Two-step verification PIN" icon={KeyRound} hint="6 digits"><input inputMode="numeric" maxLength={6} value={approve.pin} onChange={(e) => setApprove({ ...approve, pin: e.target.value.replace(/\D/g, '') })} /></Field>
      </div></Modal>}
    {reject && <RejectModal title={`Reject ${reject.phone_number}`} onClose={() => setReject(null)}
      onReject={async (reason) => { try { await api(`/number-requests/${reject.id}/reject`, { method: 'POST', body: { reason } }); toast.success('Request rejected'); setReject(null); done() } catch (e) { toast.error(e.message) } }} />}
  </div>)
}

export function TemplateRequests({ onChange }) {
  const [rows, reload, loading] = useLoad('/template-requests'); const toast = useToast()
  const [st, setSt] = useState('pending'); const [q, setQ] = useState('')
  const [view, setView] = useState(null); const [edit, setEdit] = useState(null); const [reject, setReject] = useState(null); const [busy, setBusy] = useState(false)
  const done = () => { reload(); onChange?.() }
  const list = rows.filter((r) => (!st || r.status === st) && (!q || `${r.name} ${r.client?.name}`.toLowerCase().includes(q.toLowerCase())))
  const approve = async (r) => {
    if (!confirm(`Submit “${r.name}” (${r.language}) to Meta for review and link it to ${r.client?.name}?`)) return
    setBusy(true)
    try { const x = await api(`/template-requests/${r.id}/approve`, { method: 'POST' }); toast.success(`Created on Meta · status ${x.template?.status}`); setView(x); done() }
    catch (e) { toast.error(e.message); reload() } finally { setBusy(false) }
  }
  if (edit) return <TemplateForm initial={reqToForm(edit)} title={`Edit request · ${edit.name}`} subtitle={`From ${edit.client?.name}. Saving does not submit to Meta.`}
    submitLabel="Save changes" submit={async (body) => { const x = await api(`/template-requests/${edit.id}`, { method: 'PUT', body }); setView(x) }}
    onDone={() => { toast.success('Request updated'); setEdit(null); reload() }} onCancel={() => setEdit(null)} />

  return (<div className="page">
    <PageHeader icon={FileText} title="Template Requests" subtitle="Templates requested by clients. Review, edit, then approve to submit to Meta." />
    <StatusTabs value={st} onChange={setSt} rows={rows} />
    <div className={view ? 'grid-2-1' : ''}>
      <Card pad={false} title={<div className="filters"><div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search name or client" value={q} onChange={(e) => setQ(e.target.value)} /></div></div>}>
        <div className="table-wrap"><table className="table"><thead><tr><th>Template</th><th>Client</th><th>Request</th><th>Meta status</th><th /></tr></thead>
          {loading ? <TableSkeleton cols={5} /> : list.length ? <tbody>{list.map((r) => <tr key={r.id} className={`clickable ${view?.id === r.id ? 'sel' : ''}`} onClick={() => setView(r)}>
            <td><b>{r.name}</b><small className="block muted">{r.language} · {r.category}</small></td>
            <td>{r.client?.name}<small className="block muted">{fmtTime(r.created_at)}</small></td>
            <td><Badge s={r.status} />{r.rejection_reason && <small className="block err-text">{r.rejection_reason}</small>}{r.meta_error && <small className="block err-text">Meta: {r.meta_error}</small>}</td>
            <td>{r.template ? <><Badge s={r.template.status} />{r.template.rejected_reason && <small className="block err-text">{r.template.rejected_reason}</small>}</> : <span className="muted">—</span>}</td>
            <td className="right nowrap" onClick={(e) => e.stopPropagation()}>
              <Button size="sm" variant="ghost" icon={Eye} title="View" onClick={() => setView(r)} />
              {r.status === 'pending' && <><Button size="sm" variant="ghost" icon={Pencil} title="Edit" onClick={() => setEdit(r)} />
                <Button size="sm" variant="primary" icon={Check} loading={busy} onClick={() => approve(r)}>Approve</Button>{' '}
                <Button size="sm" variant="ghost-danger" icon={X} onClick={() => setReject(r)}>Reject</Button></>}</td>
          </tr>)}</tbody> : <tbody><tr><td colSpan={5}><Empty icon={FileText} title={st === 'pending' ? 'No pending requests' : 'No requests'}>Template requests from clients appear here.</Empty></td></tr></tbody>}
        </table></div></Card>
      {view && <div className="sticky"><Card title={view.name} icon={Eye} actions={<button className="icon-btn" onClick={() => setView(null)}><X size={16} /></button>}>
        <div className="row wrap gap8"><Badge s={view.status} /><Badge tone="orange">{view.category}</Badge><Badge>{view.language}</Badge>{view.template && <Badge s={view.template.status}>Meta: {view.template.status}</Badge>}</div>
        <p className="muted small">Requested by <b>{view.client?.name}</b> on {fmtTime(view.created_at)}{view.reviewer && <> · reviewed by {view.reviewer.name}</>}</p>
        {view.rejection_reason && <div className="alert error">Rejected: <b>{view.rejection_reason}</b></div>}
        {view.meta_error && <div className="alert error">Last Meta error: {view.meta_error}</div>}
        {view.template?.rejected_reason && <div className="alert error">Meta rejection: <b>{view.template.rejected_reason}</b></div>}
        <Preview form={reqToForm(view)} />
        {view.status === 'pending' && <div className="row gap8"><Button size="sm" icon={Pencil} onClick={() => setEdit(view)}>Edit</Button>
          <Button size="sm" variant="primary" icon={Check} loading={busy} onClick={() => approve(view)}>Approve</Button>
          <Button size="sm" variant="ghost-danger" icon={X} onClick={() => setReject(view)}>Reject</Button></div>}
      </Card></div>}
    </div>
    {reject && <RejectModal title={`Reject ${reject.name}`} onClose={() => setReject(null)}
      onReject={async (reason) => { try { const x = await api(`/template-requests/${reject.id}/reject`, { method: 'POST', body: { reason } }); toast.success('Request rejected'); setReject(null); if (view?.id === x.id) setView(x); done() } catch (e) { toast.error(e.message) } }} />}
  </div>)
}
