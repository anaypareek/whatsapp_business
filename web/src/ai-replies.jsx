import { useEffect, useState } from 'react'
import { Sparkles, Check, UserRound, KeyRound, Link2, Cpu, Gauge, IndianRupee } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Badge, Toggle, useToast, useLoad, Empty, Skeleton } from './ui'

const pick = (s, defaults = {}) => ({ enabled: !!s.enabled, orders_lookup: !!s.orders_lookup, typing_indicator: s.typing_indicator ?? true, test_mode: s.test_mode ?? true, allowlist: (s.allowlist || []).join('\n'), knowledge_base: s.knowledge_base || '',
  max_per_hour: s.max_per_hour ?? 5, handoff_cooldown_hours: s.handoff_cooldown_hours ?? 24,
  handoff_text: s.handoff_text || '', handoff_text_hi: s.handoff_text_hi || '',
  human_active_quiet_minutes: s.human_active_quiet_minutes ?? defaults.human_active_quiet_minutes ?? 30 })

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
    {row && <AiForm key={row.phone_number.id} P={P} row={row} reload={reload} model={data?.provider?.model} defaults={data?.defaults || {}} />}
  </div>)
}

function AiForm({ P, row, reload, model, defaults }) {
  const toast = useToast(); const [f, setF] = useState(pick(row.settings, defaults)); const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    const body = { ...f, allowlist: f.allowlist.split(/[\s,;]+/).filter(Boolean), max_per_hour: Number(f.max_per_hour) || 5, handoff_cooldown_hours: Number(f.handoff_cooldown_hours) || 24, human_active_quiet_minutes: Math.max(0, Number(f.human_active_quiet_minutes) || 0), handoff_text: f.handoff_text || null, handoff_text_hi: f.handoff_text_hi || null }
    try { await api(`${P}/ai-replies/${row.phone_number.id}`, { method: 'PUT', body }); toast.success('AI reply settings saved'); reload() }
    catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const resolve = async (c) => { try { await api(`${P}/ai-replies/${row.phone_number.id}/resolve`, { method: 'POST', body: { contact: c } }); toast.success('Handoff cleared'); reload() } catch (err) { toast.error(err.message) } }
  return (<div className="grid-form">
    <Card title={`AI chat replies · ${row.phone_number.name || row.phone_number.display_number}`} icon={Sparkles}>
      <form className="form" onSubmit={save}>
        <div className="form-2">
          <div className="field"><span className="field-label">AI replies enabled</span><Toggle checked={f.enabled} onChange={(v) => setF({ ...f, enabled: v })} /></div>
          <div className="field"><span className="field-label">Order status lookup</span><Toggle checked={f.orders_lookup} onChange={(v) => setF({ ...f, orders_lookup: v })} /><span className="muted small">Uses the Orders API from Settings for this number only</span></div>
          <div className="field"><span className="field-label">Typing indicator</span><Toggle checked={f.typing_indicator} onChange={(v) => setF({ ...f, typing_indicator: v })} /><span className="muted small">Shows the customer "typing…" (and blue ticks) while the AI writes a reply</span></div>
          <div className="field"><span className="field-label">Test mode (allowlist only)</span><Toggle checked={f.test_mode} onChange={(v) => setF({ ...f, test_mode: v })} /></div>
        </div>
        {f.test_mode && <Field label="Allowlisted senders" hint="One number per line, with country code (e.g. 919811000001). Only these get AI replies in test mode."><textarea rows={2} value={f.allowlist} onChange={set('allowlist')} placeholder="919811000001" /></Field>}
        <Field label="Knowledge base" hint="Products, prices, timings, policies. The AI answers only from this text; anything else is handed to a team member."><textarea rows={7} value={f.knowledge_base} onChange={set('knowledge_base')} placeholder={'Basmati rice 5kg – ₹650\nShop open 9 AM – 8 PM, Mon–Sat\nFree delivery above ₹999 within city'} /></Field>
        <div className="form-2">
          <Field label="Max AI replies per contact / hour"><input type="number" min={1} max={60} value={f.max_per_hour} onChange={set('max_per_hour')} /></Field>
          <Field label="Handoff cooldown (hours)" hint="During this window after a handoff the AI can still answer known questions from the knowledge base; unknown ones stay silent (no second handoff message). After it ends a new handoff can fire again."><input type="number" min={1} value={f.handoff_cooldown_hours} onChange={set('handoff_cooldown_hours')} /></Field>
          <Field label="Human-active quiet (minutes)" hint="After a staff/manual reply, keep AI quiet for this many minutes so a human can keep chatting."><input type="number" min={0} max={1440} value={f.human_active_quiet_minutes} onChange={set('human_active_quiet_minutes')} /></Field>
        </div>
        <Field label="Handoff message (English)" hint={`Sent once when the AI hands off. Leave blank for default: “${defaults?.handoff_text || 'I am connecting you with our team member, please wait 🙏'}”`}><textarea rows={2} value={f.handoff_text} onChange={set('handoff_text')} placeholder={defaults?.handoff_text || 'I am connecting you with our team member, please wait 🙏'} /></Field>
        <Field label="Handoff message (Hindi / Hinglish)" hint={`Used when the customer wrote in Hindi/Hinglish. Leave blank for default: “${defaults?.handoff_text_hi || 'Main aapko hamare team member se connect kar raha hoon, kripya wait karein 🙏'}”`}><textarea rows={2} value={f.handoff_text_hi} onChange={set('handoff_text_hi')} placeholder={defaults?.handoff_text_hi || 'Main aapko hamare team member se connect kar raha hoon, kripya wait karein 🙏'} /></Field>
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
  const apply = (r) => {
    setS(r); setCustom(!!r.ai_model && !MODELS.includes(r.ai_model))
    const prices = r.ai_model_prices_parsed || r.ai_defaults?.model_prices || {}
    const mini = prices['gpt-4o-mini'] || { input: 0.15, output: 0.60 }
    setF({ ai_base_url: r.ai_base_url || '', ai_model: r.ai_model || '', ai_api_key: '', ai_global_max_per_hour: r.ai_global_max_per_hour || '',
      ai_usd_inr_rate: r.ai_usd_inr_rate || String(r.ai_defaults?.usd_inr_rate || 88),
      price_input: mini.input, price_output: mini.output,
      orders_api_base_url: r.orders_api_base_url || '', orders_api_key: '' })
  }
  useEffect(() => { api('/settings').then(apply).catch((e) => toast.error(e.message)) }, []) // eslint-disable-line
  if (!f) return <Card title="AI Replies" icon={Sparkles}><Skeleton h={120} /></Card>
  const d = s.ai_defaults || {}
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    try {
      const prices = { ...(s.ai_model_prices_parsed || {}), 'gpt-4o-mini': { input: Number(f.price_input) || 0.15, output: Number(f.price_output) || 0.60 } }
      const body = { ai_base_url: f.ai_base_url, ai_model: f.ai_model || MODELS[0], ai_api_key: f.ai_api_key || null, ai_global_max_per_hour: f.ai_global_max_per_hour,
        ai_usd_inr_rate: String(f.ai_usd_inr_rate || 88), ai_model_prices: JSON.stringify(prices),
        orders_api_base_url: f.orders_api_base_url, orders_api_key: f.orders_api_key || null }
      apply(await api('/settings', { method: 'PUT', body })); toast.success('AI settings saved (key encrypted)')
    }
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
      <p className="field-label" style={{ marginTop: 8 }}>AI cost & credits</p>
      <p className="muted small">Used to meter token usage in INR. Default gpt-4o-mini $0.15 / $0.60 per 1M input/output tokens; USD→INR rate default 88.</p>
      <div className="form-2">
        <Field label="USD → INR rate" icon={IndianRupee} hint={`Default ${d.usd_inr_rate || 88}`}><input type="number" step="0.01" min={1} value={f.ai_usd_inr_rate} onChange={set('ai_usd_inr_rate')} /></Field>
        <Field label="gpt-4o-mini input $ / 1M" hint="USD per million prompt tokens"><input type="number" step="0.01" min={0} value={f.price_input} onChange={set('price_input')} /></Field>
        <Field label="gpt-4o-mini output $ / 1M" hint="USD per million completion tokens"><input type="number" step="0.01" min={0} value={f.price_output} onChange={set('price_output')} /></Field>
      </div>
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
