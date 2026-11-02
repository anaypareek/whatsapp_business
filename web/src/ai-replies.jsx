import { useEffect, useState } from 'react'
import { Sparkles, Check, UserRound, KeyRound, Link2, Cpu, Gauge } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Badge, Toggle, useToast, useLoad, Empty, Skeleton } from './ui'

const pick = (s) => ({ enabled: !!s.enabled, test_mode: s.test_mode ?? true, allowlist: (s.allowlist || []).join('\n'), knowledge_base: s.knowledge_base || '',
  max_per_hour: s.max_per_hour ?? 5, handoff_cooldown_hours: s.handoff_cooldown_hours ?? 24 })

/** Per-number AI chat replies (admin only). Default OFF, test mode ON. */
export function AiReplies({ scope = 'admin' }) {
  const P = scope === 'client' ? '/client' : ''
  const [data, reload, loading] = useLoad(`${P}/ai-replies`)
  const rows = data?.numbers || []
  const [sel, setSel] = useState(null)
  useEffect(() => { if (!sel && rows.length) setSel(rows[0].phone_number.id) }, [rows.length]) // eslint-disable-line
  const row = rows.find((r) => r.phone_number.id === sel)
  if (loading && !rows.length) return <Card title="AI chat replies" icon={Sparkles}><Skeleton h={120} /></Card>
  if (!rows.length) return <Card title="AI chat replies" icon={Sparkles}><Empty icon={Sparkles} title="No numbers">No WhatsApp numbers yet.</Empty></Card>
  return (<div className="greet" style={{ marginTop: 16 }}>
    {data?.provider && !data.provider.configured && <div className="alert-warn small" style={{ marginBottom: 10 }}>AI provider API key is not set{scope === 'admin' ? ' — add it in Settings → AI Replies.' : ' — ask the admin to configure it.'} AI replies stay silent until then.</div>}
    <div className="row" style={{ marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
      {rows.map((r) => <Button key={r.phone_number.id} size="sm" variant={r.phone_number.id === sel ? 'primary' : 'secondary'} onClick={() => setSel(r.phone_number.id)}>
        <Sparkles size={12} /> {r.phone_number.name || r.phone_number.display_number} {r.settings.enabled ? <Badge tone="green">AI on</Badge> : <Badge>AI off</Badge>}</Button>)}
    </div>
    {row && <AiForm key={row.phone_number.id} P={P} row={row} reload={reload} model={data?.provider?.model} />}
  </div>)
}

function AiForm({ P, row, reload, model }) {
  const toast = useToast(); const [f, setF] = useState(pick(row.settings)); const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    const body = { ...f, allowlist: f.allowlist.split(/[\s,;]+/).filter(Boolean), max_per_hour: Number(f.max_per_hour) || 5, handoff_cooldown_hours: Number(f.handoff_cooldown_hours) || 24 }
    try { await api(`${P}/ai-replies/${row.phone_number.id}`, { method: 'PUT', body }); toast.success('AI reply settings saved'); reload() }
    catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const resolve = async (c) => { try { await api(`${P}/ai-replies/${row.phone_number.id}/resolve`, { method: 'POST', body: { contact: c } }); toast.success('Handoff cleared'); reload() } catch (err) { toast.error(err.message) } }
  return (<div className="grid-form">
    <Card title={`AI chat replies · ${row.phone_number.name || row.phone_number.display_number}`} icon={Sparkles}>
      <form className="form" onSubmit={save}>
        <div className="form-2">
          <div className="field"><span className="field-label">AI replies enabled</span><Toggle checked={f.enabled} onChange={(v) => setF({ ...f, enabled: v })} /></div>
          <div className="field"><span className="field-label">Test mode (allowlist only)</span><Toggle checked={f.test_mode} onChange={(v) => setF({ ...f, test_mode: v })} /></div>
        </div>
        {f.test_mode && <Field label="Allowlisted senders" hint="One number per line, with country code (e.g. 919811000001). Only these get AI replies in test mode."><textarea rows={2} value={f.allowlist} onChange={set('allowlist')} placeholder="919811000001" /></Field>}
        <Field label="Knowledge base" hint="Products, prices, timings, policies. The AI answers only from this text; anything else is handed to a team member."><textarea rows={7} value={f.knowledge_base} onChange={set('knowledge_base')} placeholder={'Basmati rice 5kg – ₹650\nShop open 9 AM – 8 PM, Mon–Sat\nFree delivery above ₹999 within city'} /></Field>
        <div className="form-2">
          <Field label="Max AI replies per contact / hour"><input type="number" min={1} max={60} value={f.max_per_hour} onChange={set('max_per_hour')} /></Field>
          <Field label="Handoff pause (hours)" hint="After a handoff the AI stays silent for this contact until staff replies or this time passes"><input type="number" min={1} value={f.handoff_cooldown_hours} onChange={set('handoff_cooldown_hours')} /></Field>
        </div>
        <p className="muted small">Order: automation flows → keyword auto-replies → greeting → AI. One incoming message gets at most one auto-reply. Text messages only, inside the 24h window{model ? ` · model ${model}` : ''}.</p>
        <div className="row"><Button type="submit" variant="primary" icon={Check} loading={busy}>Save AI settings</Button></div>
      </form>
    </Card>
    <Card title="Needs a human" icon={UserRound}>
      {row.handoffs.length ? <div className="table-wrap"><table className="table"><thead><tr><th>Contact</th><th>Asked</th><th /></tr></thead><tbody>
        {row.handoffs.map((h) => <tr key={h.contact_wa_id}><td>+{h.contact_wa_id}</td><td className="muted small">{h.reason}</td><td><Button size="sm" onClick={() => resolve(h.contact_wa_id)}>Resolve</Button></td></tr>)}
      </tbody></table></div> : <Empty icon={UserRound} title="Nothing pending">Conversations the AI could not answer appear here and in Chats with a “needs human” badge.</Empty>}
    </Card>
  </div>)
}

export const MODELS = ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1-nano']

/** Admin Settings → AI Replies (provider URL, model, encrypted API key, global limit). */
export function AiProviderSettings() {
  const toast = useToast(); const [s, setS] = useState(null); const [f, setF] = useState(null); const [busy, setBusy] = useState(false); const [custom, setCustom] = useState(false)
  const apply = (r) => { setS(r); setCustom(!!r.ai_model && !MODELS.includes(r.ai_model)); setF({ ai_base_url: r.ai_base_url || '', ai_model: r.ai_model || '', ai_api_key: '', ai_global_max_per_hour: r.ai_global_max_per_hour || '', orders_api_base_url: r.orders_api_base_url || '', orders_api_key: '' }) }
  useEffect(() => { api('/settings').then(apply).catch((e) => toast.error(e.message)) }, []) // eslint-disable-line
  if (!f) return <Card title="AI Replies" icon={Sparkles}><Skeleton h={120} /></Card>
  const d = s.ai_defaults || {}
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    try { apply(await api('/settings', { method: 'PUT', body: { ...f, ai_model: f.ai_model || MODELS[0], ai_api_key: f.ai_api_key || null, orders_api_key: f.orders_api_key || null } })); toast.success('AI settings saved (key encrypted)') }
    catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  return (<Card title="AI Replies" icon={Sparkles}>
    <form className="form" onSubmit={save}>
      <p className="muted small">OpenAI-compatible chat completions provider used for AI chat replies. Turn AI on per number in Auto-replies.</p>
      <div className="form-2">
        <Field label="Base URL" icon={Link2} hint={`Default ${d.base_url}`}><input value={f.ai_base_url} onChange={set('ai_base_url')} placeholder={d.base_url} /></Field>
        <Field label="Model" icon={Cpu} hint={`Default ${d.model}`}>
          <select value={custom ? '__custom' : (f.ai_model || MODELS[0])} onChange={(e) => { const v = e.target.value; setCustom(v === '__custom'); setF({ ...f, ai_model: v === '__custom' ? '' : v }) }}>
            {MODELS.map((m) => <option key={m} value={m}>{m}{m === MODELS[0] ? ' (default)' : ''}</option>)}<option value="__custom">Custom…</option></select>
          {custom && <input style={{ marginTop: 6 }} value={f.ai_model} onChange={set('ai_model')} placeholder="model name, e.g. gpt-4.1" />}</Field>
      </div>
      <Field label="OpenAI API key" icon={KeyRound} hint={s.ai_api_key_set ? `Saved: ${s.ai_api_key} — leave blank to keep` : 'e.g. an OpenAI key (sk-…); stored encrypted, never shown again'}>
        <input type="password" autoComplete="new-password" value={f.ai_api_key} onChange={set('ai_api_key')} /></Field>
      <Field label="Global max AI replies / hour" icon={Gauge} hint={`All numbers combined. Default ${d.global_max_per_hour}`}><input type="number" min={1} value={f.ai_global_max_per_hour} onChange={set('ai_global_max_per_hour')} placeholder={String(d.global_max_per_hour)} /></Field>
      <p className="field-label" style={{ marginTop: 8 }}>Order status lookup (optional)</p>
      <p className="muted small">Read-only API of your order portal; always queried with the customer's own WhatsApp number. Contract: docs/oswal-order-status-api.md</p>
      <div className="form-2">
        <Field label="Orders API base URL" icon={Link2} hint="We call GET {base}/orders/status?phone=…"><input value={f.orders_api_base_url} onChange={set('orders_api_base_url')} placeholder="https://portal.example.com/api" /></Field>
        <Field label="Orders API key" icon={KeyRound} hint={s.orders_api_key_set ? `Saved: ${s.orders_api_key} — leave blank to keep` : 'Sent as X-Api-Key; stored encrypted'}>
          <input type="password" autoComplete="new-password" value={f.orders_api_key} onChange={set('orders_api_key')} /></Field>
      </div>
      <div><Button type="submit" variant="primary" icon={Check} loading={busy}>Save AI settings</Button></div>
    </form>
  </Card>)
}
