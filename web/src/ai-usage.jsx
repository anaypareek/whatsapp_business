// AI usage & credits: admin table + drill-down / add-credit; client "AI usage" page.
import { useCallback, useEffect, useState } from 'react'
import { Sparkles, IndianRupee, Plus, ArrowLeft, Phone, CalendarDays, Coins, Gauge, ShieldAlert } from 'lucide-react'
import { api } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, Modal, Toggle, useToast } from './ui'
import { inr } from './billing'

const tok = (n) => Number(n || 0).toLocaleString('en-IN')
const featLabel = { auto_reply: 'Auto-reply', train_ai: 'Train AI', other: 'Playground' }

function BalCards({ b }) {
  if (!b) return null
  return (<div className="bal-cards">
    <div className="bal"><small>Credits</small><b className="paid">{inr(b.credits, 4)}</b></div>
    <div className="bal"><small>Used (all time)</small><b>{inr(b.used, 4)}</b></div>
    <div className={`bal due ${b.remaining <= 0 && b.block_when_empty ? 'owing' : ''}`}><small>Remaining</small><b>{inr(b.remaining, 4)}</b></div>
    {b.blocked && <div className="bal owing"><small>Status</small><b><Badge tone="red">AI blocked</Badge></b></div>}
  </div>)
}

function ByNumber({ rows }) {
  if (!rows?.length) return <Empty icon={Phone} title="No numbers">Usage appears once AI calls are made.</Empty>
  return (<div className="table-wrap"><table className="table"><thead><tr><th>Number</th><th className="right">Calls</th><th className="right">Tokens</th><th className="right">Cost</th></tr></thead>
    <tbody>{rows.map((r) => <tr key={r.phone_number_id}><td><b>{r.name || r.display_number}</b><small className="block muted">{r.display_number}</small></td>
      <td className="right">{tok(r.calls)}</td><td className="right muted">{tok(r.total_tokens)}</td><td className="right"><b>{inr(r.cost_inr, 4)}</b></td></tr>)}</tbody></table></div>)
}

function ByDay({ rows }) {
  if (!rows?.length) return <Empty icon={CalendarDays} title="No daily usage yet">Last 30 days will show here.</Empty>
  return (<div className="table-wrap"><table className="table"><thead><tr><th>Date</th><th className="right">Calls</th><th className="right">Tokens</th><th className="right">Cost</th></tr></thead>
    <tbody>{rows.map((r) => <tr key={r.date}><td className="nowrap">{r.date}</td><td className="right">{tok(r.calls)}</td><td className="right muted">{tok(r.total_tokens)}</td><td className="right"><b>{inr(r.cost_inr, 4)}</b></td></tr>)}</tbody></table></div>)
}

function Recent({ rows }) {
  if (!rows?.length) return <Empty icon={Sparkles} title="No AI calls yet" />
  return (<div className="table-wrap"><table className="table"><thead><tr><th>When</th><th>Number</th><th>Feature</th><th>Model</th><th className="right">Tokens</th><th className="right">Cost</th></tr></thead>
    <tbody>{rows.map((r) => <tr key={r.id}><td className="nowrap muted small">{String(r.created_at).replace('T', ' ').slice(0, 19)}</td>
      <td>{r.phone_number?.display_number || r.phone_number?.name || '—'}</td>
      <td><Badge>{featLabel[r.feature] || r.feature}</Badge></td><td className="muted small">{r.model}</td>
      <td className="right muted">{tok(r.total_tokens)} <small>({tok(r.prompt_tokens)}+{tok(r.completion_tokens)})</small></td>
      <td className="right"><b>{inr(r.cost_inr, 4)}</b></td></tr>)}</tbody></table></div>)
}

function Ledger({ rows }) {
  if (!rows?.length) return <Empty icon={Coins} title="No credit top-ups yet">Add credits with the button above.</Empty>
  return (<div className="table-wrap"><table className="table"><thead><tr><th>When</th><th>Note</th><th>By</th><th className="right">Amount</th></tr></thead>
    <tbody>{rows.map((r) => <tr key={r.id}><td className="nowrap muted small">{String(r.created_at).replace('T', ' ').slice(0, 19)}</td>
      <td>{r.note || '—'}</td><td className="muted">{r.creator?.name || '—'}</td>
      <td className={`right ${r.amount_inr >= 0 ? 'paid' : 'owing'}`}><b>{r.amount_inr >= 0 ? '+' : ''}{inr(r.amount_inr, 4)}</b></td></tr>)}</tbody></table></div>)
}

/** Admin: Messaging → AI usage */
export function AdminAiUsage() {
  const [sum, setSum] = useState(null); const [open, setOpen] = useState(null); const toast = useToast()
  const load = useCallback(() => api('/ai-usage').then(setSum).catch((e) => toast.error(e.message)), []) // eslint-disable-line
  useEffect(() => { load() }, [load])
  if (open) return <AdminClientUsage id={open} onBack={() => { setOpen(null); load() }} />
  const rows = sum?.clients || []
  return (<div className="page"><PageHeader icon={Sparkles} title="AI usage" subtitle="Token usage, INR cost and credit balance per client" />
    {sum && <div className="bal-cards">
      <div className="bal"><small>Used this month</small><b>{inr(sum.totals.used_month, 4)}</b></div>
      <div className="bal"><small>Used all time</small><b>{inr(sum.totals.used_all, 4)}</b></div>
      <div className="bal"><small>Credits issued</small><b className="paid">{inr(sum.totals.credits, 4)}</b></div>
      <div className="bal"><small>Calls (month / all)</small><b>{tok(sum.totals.calls_month)} / {tok(sum.totals.calls)}</b></div>
    </div>}
    <Card title={`Clients (${rows.length})`} pad={false}><div className="table-wrap"><table className="table"><thead><tr>
      <th>Client</th><th className="right">Month</th><th className="right">All time</th><th className="right">Tokens</th><th className="right">Calls</th>
      <th className="right">Credits</th><th className="right">Remaining</th><th /></tr></thead>
      {!sum ? <TableSkeleton cols={8} /> : rows.length ? <tbody>{rows.map((c) => <tr key={c.id} className="clickable" onClick={() => setOpen(c.id)}>
        <td><div className="client-cell"><b>{c.name}</b><small>{c.company || ''}{c.blocked ? ' · blocked' : ''}</small></div></td>
        <td className="right">{inr(c.used_month, 4)}</td><td className="right">{inr(c.used_all, 4)}</td>
        <td className="right muted">{tok(c.total_tokens)}</td><td className="right">{tok(c.calls)}</td>
        <td className="right paid">{inr(c.credits, 4)}</td>
        <td className="right">{c.remaining <= 0 ? <Badge tone="red">{inr(c.remaining, 4)}</Badge> : <Badge tone="green">{inr(c.remaining, 4)}</Badge>}</td>
        <td className="right"><Button size="sm" variant="ghost" icon={Plus}>Credits</Button></td></tr>)}</tbody>
        : <tbody><tr><td colSpan={8}><Empty icon={Sparkles} title="No clients yet" /></td></tr></tbody>}</table></div></Card>
  </div>)
}

function AdminClientUsage({ id, onBack }) {
  const [d, setD] = useState(null); const [pay, setPay] = useState(null); const toast = useToast()
  const load = useCallback(() => api(`/ai-usage/clients/${id}`).then(setD).catch((e) => toast.error(e.message)), [id]) // eslint-disable-line
  useEffect(() => { load() }, [load])
  const add = async (e) => {
    e.preventDefault()
    try {
      const r = await api(`/ai-usage/clients/${id}/credits`, { method: 'POST', body: { amount_inr: Number(pay.amount_inr), note: pay.note || null } })
      toast.success(`Credits updated · remaining ${inr(r.balance.remaining, 4)}`); setPay(null); load()
    } catch (err) { toast.error(err.message) }
  }
  const toggleBlock = async (v) => {
    try { await api(`/ai-usage/clients/${id}`, { method: 'PUT', body: { ai_block_when_empty: v } }); toast.success(v ? 'Will block AI when balance ≤ 0' : 'AI allowed even at zero balance'); load() }
    catch (err) { toast.error(err.message) }
  }
  return (<div className="page">
    <PageHeader icon={Sparkles} title={d ? `${d.client.name} · AI usage` : 'AI usage'} subtitle={d?.client.company || 'Usage, credits and recent calls'}>
      <Button icon={ArrowLeft} onClick={onBack}>All clients</Button>
      <Button variant="primary" icon={Plus} onClick={() => setPay({ amount_inr: '', note: '' })}>Add credits</Button>
    </PageHeader>
    {d && <BalCards b={d.balance} />}
    {d && <Card title="When balance is empty" icon={ShieldAlert}>
      <div className="field" style={{ margin: 0 }}><span className="field-label">Block AI auto-replies when remaining ≤ ₹0</span>
        <Toggle checked={!!d.client.ai_block_when_empty} onChange={toggleBlock} />
        <span className="muted small">Default ON. Trainer / playground are also paused while blocked.</span></div>
    </Card>}
    <div className="grid-2-1">
      <Card title="By number" icon={Phone} pad={false}>{d ? <ByNumber rows={d.by_number} /> : <table className="table"><TableSkeleton cols={4} /></table>}</Card>
      <Card title="By day (30d)" icon={CalendarDays} pad={false}>{d ? <ByDay rows={d.by_day} /> : <table className="table"><TableSkeleton cols={4} /></table>}</Card>
    </div>
    <Card title="Recent calls" icon={Gauge} pad={false}>{d ? <Recent rows={d.recent} /> : null}</Card>
    <Card title="Credit ledger" icon={Coins} pad={false}>{d ? <Ledger rows={d.ledger} /> : null}</Card>
    {pay && <Modal title="Add / adjust AI credits" onClose={() => setPay(null)} footer={<><Button onClick={() => setPay(null)}>Cancel</Button><Button type="submit" form="ai-cred-form" variant="primary" icon={Plus}>Save</Button></>}>
      <form id="ai-cred-form" className="form" onSubmit={add}>
        <Field label="Amount (₹)" icon={IndianRupee} hint="Positive to top up, negative to adjust down"><input type="number" step="0.01" required autoFocus value={pay.amount_inr} onChange={(e) => setPay({ ...pay, amount_inr: e.target.value })} /></Field>
        <Field label="Note"><input value={pay.note} onChange={(e) => setPay({ ...pay, note: e.target.value })} placeholder="e.g. starter pack / adjustment" /></Field>
        {d && <div className="small muted">Current remaining: <b>{inr(d.balance.remaining, 4)}</b></div>}
      </form></Modal>}
  </div>)
}

/** Client portal: AI usage */
export function ClientAiUsage() {
  const [d, setD] = useState(null); const toast = useToast()
  useEffect(() => { api('/client/ai-usage').then(setD).catch((e) => toast.error(e.message)) }, []) // eslint-disable-line
  return (<div className="page"><PageHeader icon={Sparkles} title="AI usage" subtitle="How many AI credits your numbers have used, and what remains" />
    {d && <BalCards b={d.balance} />}
    {d?.balance?.blocked && <div className="alert-warn small" style={{ marginBottom: 12 }}>AI replies are paused — your credit balance is empty. Contact your account manager to top up.</div>}
    <div className="grid-2-1">
      <Card title="By number" icon={Phone} pad={false}>{d ? <ByNumber rows={d.by_number} /> : <table className="table"><TableSkeleton cols={4} /></table>}</Card>
      <Card title="By day (30d)" icon={CalendarDays} pad={false}>{d ? <ByDay rows={d.by_day} /> : <table className="table"><TableSkeleton cols={4} /></table>}</Card>
    </div>
    <Card title="Recent calls" icon={Gauge} pad={false}>{d ? <Recent rows={d.recent} /> : null}</Card>
  </div>)
}

/** Helpers exported for vitest. */
export function remaining(credits, used) { return Math.round((Number(credits || 0) - Number(used || 0)) * 10000) / 10000 }
export function costInr({ prompt = 0, completion = 0, input = 0.15, output = 0.60, rate = 88 }) {
  const usd = (prompt / 1e6) * input + (completion / 1e6) * output
  return Math.round(usd * rate * 1e6) / 1e6
}
