// Usage-based billing: due badges (admin + client), admin dues page, client "Usage & billing" page.
import { useCallback, useEffect, useState } from 'react'
import { Wallet, IndianRupee, Plus, Receipt, Save, ArrowLeft, CalendarDays, Megaphone, Wrench, ShieldCheck, Headphones } from 'lucide-react'
import { api } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, Modal, useToast } from './ui'

export const CATS = [['marketing', 'Marketing', Megaphone], ['utility', 'Utility', Wrench], ['authentication', 'Authentication', ShieldCheck], ['service', 'Service', Headphones]]
export const inr = (n, d = 2) => `₹${Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d })}`
export const rate = (n) => `₹${Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
export const today = () => iso(new Date())
export const monthStart = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
/** Sum of line amounts (mirrors the API; used by tests + to double-check the UI). */
export const lineTotal = (lines) => Math.round((lines || []).reduce((a, l) => a + Math.round(l.count * l.rate * 100) / 100, 0) * 100) / 100

/** Top-right badge. scope=admin → total due across clients; scope=client → own due. Hidden for staff without billing access. */
export function DueBadge({ scope, onClick }) {
  const [v, setV] = useState(null)
  useEffect(() => {
    const f = () => api(scope === 'admin' ? '/billing/summary' : '/client/billing/due').then((r) => setV(scope === 'admin' ? r.total_due : r.due)).catch(() => setV(undefined))
    f(); const t = setInterval(f, 60000); return () => clearInterval(t)
  }, [scope])
  if (v === undefined) return null
  return <button className={`due-badge ${v > 0 ? 'owing' : 'clear'}`} onClick={onClick} title={scope === 'admin' ? 'Total due from all clients' : 'Your balance due'}>
    <Wallet size={16} /><span className="due-l">{scope === 'admin' ? 'Total due' : 'Due'}</span><b>{v === null ? '…' : inr(v)}</b></button>
}

function Period({ from, to, onChange }) {
  const preset = (f, t) => onChange({ from: f, to: t })
  const now = new Date(); const lm = new Date(now.getFullYear(), now.getMonth() - 1, 1); const lmEnd = new Date(now.getFullYear(), now.getMonth(), 0)
  return (<div className="period">
    <CalendarDays size={16} />
    <input type="date" value={from} max={to} onChange={(e) => onChange({ from: e.target.value, to })} aria-label="From" />
    <span className="muted">to</span>
    <input type="date" value={to} min={from} onChange={(e) => onChange({ from, to: e.target.value })} aria-label="To" />
    <div className="seg"><button className={from === monthStart() ? 'on' : ''} onClick={() => preset(monthStart(), today())}>This month</button>
      <button className={from === iso(lm) && to === iso(lmEnd) ? 'on' : ''} onClick={() => preset(iso(lm), iso(lmEnd))}>Last month</button></div>
  </div>)
}

/** Charges table + totals for a statement. */
function Statement({ s }) {
  return (<div className="table-wrap"><table className="table bill"><thead><tr><th>Category</th><th className="right">Messages</th><th className="right">Rate</th><th className="right">Amount</th></tr></thead>
    <tbody>{CATS.map(([k, l, Icon]) => { const x = s.lines.find((y) => y.category === k) || { count: 0, rate: 0, amount: 0 }
      return <tr key={k}><td><div className="cat-cell"><span className={`cat-ico c-${k}`}><Icon size={14} /></span><b>{l}</b></div></td>
        <td className="right">{x.count.toLocaleString('en-IN')}</td><td className="right muted">{rate(x.rate)}</td><td className="right"><b>{inr(x.amount)}</b></td></tr> })}</tbody>
    <tfoot><tr><td><b>Total</b></td><td className="right"><b>{s.messages.toLocaleString('en-IN')}</b></td><td /><td className="right"><b className="grand">{inr(s.total)}</b></td></tr></tfoot>
  </table></div>)
}

function Payments({ rows, showBy }) {
  if (!rows?.length) return <Empty icon={Receipt} title="No payments recorded">Payments appear here once recorded by your account manager.</Empty>
  return (<div className="table-wrap"><table className="table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Note</th>{showBy && <th>By</th>}<th className="right">Amount</th></tr></thead>
    <tbody>{rows.map((p) => <tr key={p.id}><td className="nowrap">{String(p.paid_at).slice(0, 10)}</td><td>{p.method || '—'}</td><td><code>{p.reference || '—'}</code></td><td className="muted">{p.note || '—'}</td>
      {showBy && <td className="muted">{p.creator?.name || '—'}</td>}<td className="right"><b className="paid">{inr(p.amount)}</b></td></tr>)}</tbody></table></div>)
}

function BalanceCards({ b, extra }) {
  return (<div className="bal-cards">
    <div className="bal"><small>Total charges</small><b>{inr(b.charges)}</b></div>
    <div className="bal"><small>Total paid</small><b className="paid">{inr(b.paid)}</b></div>
    <div className={`bal due ${b.due > 0 ? 'owing' : ''}`}><small>Balance due</small><b>{b.due < 0 ? `${inr(-b.due)} credit` : inr(b.due)}</b></div>{extra}
  </div>)
}

function useStatement(path) {
  const [p, setP] = useState({ from: monthStart(), to: today() }); const [s, setS] = useState(null); const toast = useToast()
  const load = useCallback(() => api(`${path}?from=${p.from}&to=${p.to}`).then(setS).catch((e) => toast.error(e.message)), [path, p.from, p.to]) // eslint-disable-line
  useEffect(() => { load() }, [load])
  return [s, p, setP, load]
}

// ---------------- client ----------------
export function UsageBilling() {
  const [s, p, setP] = useStatement('/client/billing')
  return (<div className="page"><PageHeader icon={IndianRupee} title="Usage & billing" subtitle="Messages sent from your numbers, the rate per category, and your payments" />
    {s && <BalanceCards b={s.balance} />}
    <Card title="Usage" icon={Receipt} pad={false} actions={<Period {...p} onChange={setP} />}>
      {s ? <Statement s={s} /> : <table className="table"><TableSkeleton cols={4} rows={4} /></table>}
      <div className="bill-foot small muted">Charged per delivered outbound message by Meta pricing category. Template messages use the template's category; free-form replies inside the 24-hour window are “service”. Failed messages are not charged.</div>
    </Card>
    <Card title={`Payments in period${s ? ` · ${inr(s.payments_total)}` : ''}`} icon={Wallet} pad={false}>{s ? <Payments rows={s.payments} /> : <table className="table"><TableSkeleton cols={5} rows={2} /></table>}</Card>
  </div>)
}

// ---------------- admin ----------------
export function Dues() {
  const [sum, setSum] = useState(null); const [open, setOpen] = useState(null); const [defs, setDefs] = useState(null); const toast = useToast()
  const load = useCallback(() => api('/billing/summary').then((r) => { setSum(r); setDefs((d) => d ?? r.default_rates) }).catch((e) => toast.error(e.message)), []) // eslint-disable-line
  useEffect(() => { load() }, [load])
  const saveDefs = async () => { try { const r = await api('/billing/default-rates', { method: 'PUT', body: defs }); setDefs(r.rates); toast.success('Default rate card saved'); load() } catch (e) { toast.error(e.message) } }
  if (open) return <ClientDues id={open} onBack={() => { setOpen(null); load() }} />
  const rows = sum?.clients || []
  return (<div className="page"><PageHeader icon={Wallet} title="Client dues" subtitle="Usage charges, payments and balance per client (INR)" />
    {sum && <BalanceCards b={{ charges: sum.total_charges, paid: sum.total_paid, due: sum.total_due }} />}
    <div className="grid-2-1">
      <Card title={`Clients (${rows.length})`} pad={false}><div className="table-wrap"><table className="table"><thead><tr><th>Client</th><th className="right">Charges</th><th className="right">Paid</th><th className="right">Due</th><th /></tr></thead>
        {!sum ? <TableSkeleton cols={5} /> : rows.length ? <tbody>{rows.map((c) => <tr key={c.id} className="clickable" onClick={() => setOpen(c.id)}>
          <td><div className="client-cell"><b>{c.name}</b><small>{c.company || ''}{!c.is_active && ' · inactive'}</small></div></td>
          <td className="right">{inr(c.charges)}</td><td className="right paid">{inr(c.paid)}</td>
          <td className="right">{c.due > 0 ? <Badge tone="red">{inr(c.due)}</Badge> : <Badge tone="green">{inr(c.due)}</Badge>}</td>
          <td className="right"><Button size="sm" variant="ghost" icon={Plus}>Payment</Button></td></tr>)}</tbody>
          : <tbody><tr><td colSpan={5}><Empty icon={Wallet} title="No clients yet" /></td></tr></tbody>}</table></div></Card>
      <Card title="Default rate card" icon={IndianRupee}>
        <p className="small muted" style={{ marginTop: 0 }}>INR per message, used for every client without their own rates. Open a client to override.</p>
        {defs && <RateForm rates={defs} onChange={setDefs} />}
        <Button variant="primary" icon={Save} onClick={saveDefs}>Save defaults</Button>
      </Card>
    </div></div>)
}

function RateForm({ rates, onChange }) {
  return <div className="rate-grid">{CATS.map(([k, l, Icon]) => <Field key={k} label={l} icon={Icon}>
    <input type="number" step="0.0001" min="0" value={rates[k] ?? ''} onChange={(e) => onChange({ ...rates, [k]: e.target.value })} aria-label={`${l} rate`} /></Field>)}</div>
}

export function ClientDues({ id, onBack }) {
  const [s, p, setP, load] = useStatement(`/billing/clients/${id}`)
  const [rates, setRates] = useState(null); const [pay, setPay] = useState(null); const toast = useToast()
  useEffect(() => { if (s && !rates) setRates(s.rates) }, [s]) // eslint-disable-line
  const saveRates = async () => { try { await api(`/billing/clients/${id}/rates`, { method: 'PUT', body: rates }); toast.success('Rates saved'); load() } catch (e) { toast.error(e.message) } }
  const addPay = async (e) => {
    e.preventDefault()
    try { const r = await api(`/billing/clients/${id}/payments`, { method: 'POST', body: pay }); toast.success(`Payment of ${inr(r.payment.amount)} recorded · due now ${inr(r.balance.due)}`); setPay(null); load() }
    catch (err) { toast.error(err.message) }
  }
  return (<div className="page">
    <PageHeader icon={Wallet} title={s ? `${s.client.name} · dues` : 'Client dues'} subtitle={s?.client.company || 'Charges, payments and balance'}>
      <Button icon={ArrowLeft} onClick={onBack}>All clients</Button>
      <Button variant="primary" icon={Plus} onClick={() => setPay({ amount: '', paid_at: today(), method: 'UPI', reference: '', note: '' })}>Add payment</Button></PageHeader>
    {s && <BalanceCards b={s.balance} />}
    <div className="grid-2-1">
      <Card title="Charges" icon={Receipt} pad={false} actions={<Period {...p} onChange={setP} />}>{s ? <Statement s={s} /> : <table className="table"><TableSkeleton cols={4} rows={4} /></table>}</Card>
      <Card title="Rates for this client" icon={IndianRupee}>
        <p className="small muted" style={{ marginTop: 0 }}>{s && Object.keys(s.custom_rates || {}).length ? 'Custom rate card.' : 'Using the default rate card. Saving creates a custom card.'}</p>
        {rates && <RateForm rates={rates} onChange={setRates} />}
        <Button variant="primary" icon={Save} onClick={saveRates}>Save rates</Button></Card>
    </div>
    <Card title={`Payments (all time${s ? ` · ${inr(s.balance.paid)}` : ''})`} icon={Wallet} pad={false}>{s ? <Payments rows={s.all_payments} showBy /> : null}</Card>
    {pay && <Modal title="Add payment" onClose={() => setPay(null)} footer={<><Button onClick={() => setPay(null)}>Cancel</Button><Button type="submit" form="pay-form" variant="primary" icon={Plus}>Record payment</Button></>}>
      <form id="pay-form" className="form" onSubmit={addPay}>
        <div className="row gap8"><Field label="Amount (₹)" icon={IndianRupee} className="grow"><input type="number" step="0.01" min="0.01" required autoFocus value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} /></Field>
          <Field label="Date" className="grow"><input type="date" required value={pay.paid_at} onChange={(e) => setPay({ ...pay, paid_at: e.target.value })} /></Field></div>
        <div className="row gap8"><Field label="Method" className="grow"><select value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}>{['UPI', 'Bank transfer', 'Cash', 'Cheque', 'Card', 'Other'].map((m) => <option key={m}>{m}</option>)}</select></Field>
          <Field label="Reference" className="grow"><input placeholder="UTR / txn id" value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} /></Field></div>
        <Field label="Note"><input value={pay.note} onChange={(e) => setPay({ ...pay, note: e.target.value })} /></Field>
        {s && <div className="small muted">Current due: <b>{inr(s.balance.due)}</b>{pay.amount ? <> → after this payment: <b>{inr(s.balance.due - Number(pay.amount))}</b></> : null}</div>}
      </form></Modal>}
  </div>)
}
