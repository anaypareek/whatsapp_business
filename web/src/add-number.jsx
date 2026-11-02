import { useEffect, useState } from 'react'
import { AlertCircle, Building2, Check, Eye, EyeOff, Hash, Info, Lock, MessageSquare, Phone, PhoneCall, Plus, RefreshCw, Send, ShieldCheck } from 'lucide-react'
import { api, fmtTime } from './api'
import { Badge, Button, Field, Modal } from './ui'
import { ClientSelect } from './clients'

// Number Master → Add number: POST /{waba}/phone_numbers → request_code → verify_code → register → status (name_status).
// The row is saved at step 1 (onboarding_step), so an unfinished number can be resumed from the table.
export const ONBOARD_STEPS = [['created', 'Add number'], ['code_requested', 'Verify code'], ['verified', 'Register'], ['registered', 'Status']]
const stepIndex = (s) => ({ created: 1, code_requested: 1, verified: 2, registered: 3 }[s] ?? 0)
const toneFor = (v) => ({ APPROVED: 'green', CONNECTED: 'green', VERIFIED: 'green', GREEN: 'green', AVAILABLE_WITHOUT_REVIEW: 'green', PENDING_REVIEW: 'amber', PENDING: 'amber', NOT_VERIFIED: 'amber', YELLOW: 'amber', DECLINED: 'red', RED: 'red', EXPIRED: 'red', FLAGGED: 'red' }[v] || 'gray')

export const metaErr = (err) => ({ message: err.data?.message || err.message, code: err.data?.error_code, log_id: err.data?.log_id, meta: err.data?.meta, hint: err.data?.hint, errors: err.data?.errors })

/** Shows Meta's error fields clearly: message, code/subcode, error_user_title/msg, hint, fbtrace id. */
export function MetaError({ e, title = 'Meta returned an error' }) {
  if (!e) return null
  const m = e.meta || {}
  return (<div className="alert error meta-error" role="alert"><AlertCircle size={16} /><div>
    {e.hint && <div className="break" style={{ marginBottom: 6 }}><b>{e.hint}</b></div>}
    <b>{title}{m.meta_code ? ` (code ${m.meta_code}${m.meta_subcode ? `/${m.meta_subcode}` : ''})` : e.code ? ` (${e.code})` : ''}</b>
    {m.error_user_msg ? <div className="break"><b>{m.error_user_title ? `${m.error_user_title}: ` : ''}</b>{m.error_user_msg}</div> : <div className="break">{e.message}</div>}
    {(m.meta_message || m.meta_code) && <dl className="meta-err-kv">
      {m.meta_message && <><dt>message</dt><dd className="break">{m.meta_message}</dd></>}
      {m.meta_code && <><dt>code</dt><dd>{m.meta_code}{m.meta_subcode ? ` · subcode ${m.meta_subcode}` : ''}{m.http_status ? ` · HTTP ${m.http_status}` : ''}</dd></>}
      {m.error_user_msg && <><dt>error_user_msg</dt><dd className="break">{m.error_user_msg}</dd></>}
      {m.fbtrace_id && <><dt>fbtrace_id</dt><dd><code>{m.fbtrace_id}</code></dd></>}
    </dl>}
    {m.hint && <div className="small">{m.hint}</div>}
    {e.log_id && <div className="small muted">Error log ref #{e.log_id}</div>}</div></div>)
}

function Steps({ at }) {
  return <ol className="wiz-steps">{ONBOARD_STEPS.map(([k, label], i) => <li key={k} className={i < at ? 'done' : i === at ? 'on' : ''}><span>{i < at ? <Check size={13} /> : i + 1}</span>{label}</li>)}</ol>
}

export function AddNumberWizard({ resume, clients, onClose, onChanged }) {
  const [n, setN] = useState(resume || null)
  const [wabas, setWabas] = useState([])
  const [f, setF] = useState({ waba_id: '', cc: '91', phone_number: '', verified_name: '', client_id: '' })
  const [method, setMethod] = useState('SMS'); const [language, setLanguage] = useState('en_US')
  const [code, setCode] = useState(''); const [pin, setPin] = useState(''); const [pin2, setPin2] = useState(''); const [showPin, setShowPin] = useState(false)
  const [busy, setBusy] = useState(false); const [err, setErr] = useState(null); const [note, setNote] = useState(null); const [meta, setMeta] = useState(null)
  const [codeSent, setCodeSent] = useState(resume?.onboarding_step === 'code_requested')

  useEffect(() => { if (resume) return; api('/phone-numbers-wabas').then((r) => { setWabas(r.wabas); setF((x) => ({ ...x, waba_id: r.default || r.wabas[0]?.id || '' })) }).catch((e) => setErr(metaErr(e))) }, [resume])
  const step = n ? stepIndex(n.onboarding_step) : 0
  const run = async (fn) => { setBusy(true); setErr(null); setNote(null); try { await fn() } catch (e) { setErr(metaErr(e)); if (e.data?.number) { setN(e.data.number); onChanged?.() } } finally { setBusy(false) } }
  const update = (r) => { setN(r.number); setNote(r.message || null); onChanged?.() }

  const create = (e) => { e.preventDefault(); run(async () => update(await api('/phone-numbers-onboard', { method: 'POST', body: { ...f, client_id: f.client_id || null } }))) }
  const requestCode = () => run(async () => { update(await api(`/phone-numbers/${n.id}/request-code`, { method: 'POST', body: { code_method: method, language } })); setCodeSent(true) })
  const verify = (e) => { e.preventDefault(); run(async () => { update(await api(`/phone-numbers/${n.id}/verify-code`, { method: 'POST', body: { code } })); setCode('') }) }
  const register = (e) => { e.preventDefault(); if (!/^\d{6}$/.test(pin) || pin !== pin2) return
    run(async () => { try { const r = await api(`/phone-numbers/${n.id}/register`, { method: 'POST', body: { pin } }); update(r); refresh() } finally { setPin(''); setPin2('') } }) }
  const refresh = () => run(async () => { const r = await api(`/phone-numbers/${n.id}/refresh`, { method: 'POST' }); setN(r.number); setMeta(r.meta); onChanged?.() })
  useEffect(() => { if (resume?.onboarding_step === 'registered') refresh() }, []) // eslint-disable-line

  const pinBad = pin.length > 0 && !/^\d{6}$/.test(pin); const pinMismatch = pin2.length === 6 && pin !== pin2
  const facts = n && <div className="reg-facts"><div><small>Number</small><b>{n.display_number}</b></div><div><small>Display name</small><b>{n.name || '—'}</b></div>
    <div><small>Phone number ID</small><code>{n.phone_number_id}</code></div>{n.waba_id && <div><small>WABA</small><code>{n.waba_id}</code></div>}</div>

  return (<Modal wide title={n ? `Add number · ${n.display_number}` : 'Add a new number to WhatsApp'} onClose={onClose}
    footer={<><Button onClick={onClose}>{step === 3 ? 'Done' : 'Close'}</Button>
      {step === 0 && <Button type="submit" form="an-create" variant="primary" icon={Plus} loading={busy}>Add number on Meta</Button>}
      {step === 1 && codeSent && <Button type="submit" form="an-verify" variant="primary" icon={Check} loading={busy} disabled={!/^\d{4,8}$/.test(code)}>Verify code</Button>}
      {step === 1 && !codeSent && <Button variant="primary" icon={Send} loading={busy} onClick={requestCode}>Send code</Button>}
      {step === 2 && <Button type="submit" form="an-reg" variant="primary" icon={ShieldCheck} loading={busy} disabled={!/^\d{6}$/.test(pin) || pin !== pin2}>Register with Cloud API</Button>}
      {step === 3 && <Button variant="primary" icon={RefreshCw} loading={busy} onClick={refresh}>Refresh status</Button>}</>}>
    <div className="form">
      <Steps at={step} />
      {step === 0 && <form id="an-create" className="form" onSubmit={create} autoComplete="off">
        <Field label="WhatsApp Business Account (WABA)" hint={wabas.length > 1 ? 'Several WABAs found in Settings / existing numbers: pick the one to add this number to' : 'From Settings'}>
          <select value={f.waba_id} onChange={(e) => setF({ ...f, waba_id: e.target.value })} required>
            {!wabas.length && <option value="">No WABA configured (Settings)</option>}
            {wabas.map((w) => <option key={w.id} value={w.id}>{w.id}{w.is_default ? ' (Settings)' : ''} · {w.numbers} number{w.numbers === 1 ? '' : 's'}</option>)}</select></Field>
        <div className="an-phone"><Field label="Country code" icon={Plus}><input inputMode="numeric" value={f.cc} onChange={(e) => setF({ ...f, cc: e.target.value.replace(/[^\d]/g, '').slice(0, 4) })} required placeholder="91" /></Field>
          <Field label="Phone number" icon={Phone} hint="Without country code. Must not be active on WhatsApp / WhatsApp Business app."><input inputMode="tel" value={f.phone_number} onChange={(e) => setF({ ...f, phone_number: e.target.value })} required placeholder="98110 22334" /></Field></div>
        <Field label="Display name (verified_name)" icon={Building2} hint="Shown to customers. Meta reviews it against your business name."><input value={f.verified_name} onChange={(e) => setF({ ...f, verified_name: e.target.value })} required minLength={3} maxLength={100} placeholder="e.g. Oswal Home Care" /></Field>
        <Field label="Client (optional)" hint="Which client sees this number in their portal"><ClientSelect clients={clients} value={f.client_id} onChange={(v) => setF({ ...f, client_id: v })} /></Field>
        <div className="alert info"><Info size={15} /><div className="small">Calls Meta's <code>POST /{f.waba_id || '{waba-id}'}/phone_numbers</code>. The number is saved in Number Master right away, so you can close this and resume later.</div></div>
      </form>}
      {step === 1 && <>{facts}
        <div className="an-method"><span className="field-label">Send verification code by</span><div className="seg">
          {[['SMS', 'SMS', MessageSquare], ['VOICE', 'Voice call', PhoneCall]].map(([k, l, I]) => <button key={k} type="button" className={method === k ? 'on' : ''} onClick={() => setMethod(k)}><I size={15} />{l}</button>)}</div>
          <Field label="Language"><select value={language} onChange={(e) => setLanguage(e.target.value)}><option value="en_US">English (en_US)</option><option value="hi">Hindi (hi)</option><option value="en_GB">English UK (en_GB)</option></select></Field>
          {codeSent && <Button size="sm" icon={RefreshCw} onClick={requestCode} loading={busy}>Resend code</Button>}</div>
        {codeSent && <form id="an-verify" className="form" onSubmit={verify} autoComplete="off">
          <Field label="Verification code (OTP)" icon={Hash} hint={`Sent by ${n.code_method === 'VOICE' ? 'voice call' : 'SMS'} to ${n.display_number}${n.code_requested_at ? ` at ${fmtTime(n.code_requested_at)}` : ''}`}>
            <input className="pin-input" inputMode="numeric" autoComplete="one-time-code" maxLength={8} placeholder="123456" value={code} onChange={(e) => { setCode(e.target.value.replace(/\D/g, '').slice(0, 8)); setErr(null) }} autoFocus /></Field></form>}
        {!codeSent && <div className="alert info"><Info size={15} /><div className="small">Calls <code>POST /{n.phone_number_id}/request_code</code>. Meta sends a code to the number; enter it on the next screen.</div></div>}
      </>}
      {step === 2 && <>{facts}
        <form id="an-reg" className="form" onSubmit={register} autoComplete="off">
          <div className="an-pins"><Field label="6-digit PIN" icon={Lock} hint="Becomes the number's two-step verification PIN">
            <div className="pin-wrap"><input className="pin-input" type={showPin ? 'text' : 'password'} inputMode="numeric" maxLength={6} autoComplete="new-password" placeholder="••••••"
              value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 6))} aria-invalid={pinBad} autoFocus />
              <button type="button" className="icon-btn" title={showPin ? 'Hide PIN' : 'Show PIN'} onClick={() => setShowPin(!showPin)}>{showPin ? <EyeOff size={17} /> : <Eye size={17} />}</button></div>
            {pinBad && <small className="err-text">{6 - pin.length} more digit{6 - pin.length > 1 ? 's' : ''} needed</small>}</Field>
            <Field label="Confirm PIN" icon={Lock}><input className="pin-input" type={showPin ? 'text' : 'password'} inputMode="numeric" maxLength={6} autoComplete="new-password" placeholder="••••••"
              value={pin2} onChange={(e) => setPin2(e.target.value.replace(/\D/g, '').slice(0, 6))} aria-invalid={pinMismatch} />
              {pinMismatch && <small className="err-text">PINs do not match</small>}</Field></div>
          <div className="alert info"><Info size={15} /><div className="small">Calls <code>POST /{n.phone_number_id}/register</code> with <code>messaging_product=whatsapp</code>. The PIN is not stored by this portal, so keep it somewhere safe.</div></div>
        </form></>}
      {step === 3 && <>{facts}
        <div className="an-status">
          {[['Display name review', 'name_status'], ['Code verification', 'code_verification_status'], ['Status', 'status'], ['Quality', 'quality_rating']].map(([l, k]) =>
            <div key={k}><small>{l}</small><Badge tone={toneFor(n[k])}>{n[k] || 'unknown'}</Badge>{k === 'name_status' && <code className="muted small">name_status</code>}</div>)}
          <div><small>Verified name</small><b>{n.name || '—'}</b></div><div><small>Display number</small><b>{n.display_number}</b></div>
        </div>
        <div className="small muted">Last checked {n.meta_checked_at ? fmtTime(n.meta_checked_at) : 'never'} · fields from <code>GET /{n.phone_number_id}?fields=name_status,verified_name,code_verification_status,quality_rating,display_phone_number</code></div>
        {n.name_status === 'PENDING_REVIEW' && <div className="alert warn"><Info size={16} /><div><span>Meta is reviewing the display name “{n.name}”. You can send messages meanwhile; check back with <b>Refresh status</b>.</span></div></div>}
        {n.name_status === 'DECLINED' && <div className="alert warn"><AlertCircle size={16} /><div>Meta declined the display name. Change it in WhatsApp Manager → Phone numbers, then refresh.</div></div>}
        {meta && <details className="small"><summary>Raw Meta response</summary><pre className="raw-json">{JSON.stringify(meta, null, 2)}</pre></details>}
      </>}
      {note && !err && <div className="alert success"><Check size={16} /><div>{note}</div></div>}
      <MetaError e={err} />
    </div>
  </Modal>)
}
