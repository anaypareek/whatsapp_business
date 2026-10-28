// API keys (admin: any client; client portal: own keys), request logs, "Use via API" snippets, WABA health banner.
// Shared by both bundles: never mention the admin path here.
import { useEffect, useMemo, useState } from 'react'
import { KeyRound, Plus, Copy, Trash2, ShieldAlert, Code2, Activity, Pencil, RefreshCw, AlertOctagon, Check } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Modal, Badge, useToast, useLoad } from './ui'

const copyText = (toast, t) => navigator.clipboard?.writeText(t).then(() => toast.success('Copied'), () => toast.error('Copy failed'))
const lines = (s) => String(s || '').split(/[\n,]+/).map((x) => x.trim()).filter(Boolean)

export function ServerSideWarning() {
  return <div className="alert warn api-warning"><ShieldAlert size={18} /><div><b>Server-side only — never use this key in browser JavaScript.</b><span>Keep the API key in your server's environment
    (e.g. <code>WAB_API_KEY</code> in <code>.env</code>) and call the API from your backend. Never put it in browser JavaScript, a mobile app, or a public repo —
    anyone who sees it can send WhatsApp messages as you. Revoke it here if it leaks.</span></div></div>
}

/** Build example requests for an approved template. */
export function snippets({ template: t, number, base = location.origin }) {
  const comps = t.components || []
  const head = comps.find((c) => c.type === 'HEADER')
  const body = comps.find((c) => c.type === 'BODY')
  const btns = comps.find((c) => c.type === 'BUTTONS')?.buttons || []
  const n = Math.max(0, ...[...String(body?.text || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map((m) => +m[1]))
  const ex = body?.example?.body_text?.[0] || []
  const variables = { body: Array.from({ length: n }, (_, i) => ex[i] || `value_${i + 1}`) }
  if (head && head.format === 'TEXT' && /\{\{\s*1\s*\}\}/.test(head.text || '')) variables.header = head.example?.header_text?.[0] || 'header_value'
  else if (head && ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(head.format)) variables.header = `https://example.com/file.${head.format === 'IMAGE' ? 'jpg' : head.format === 'VIDEO' ? 'mp4' : 'pdf'}`
  if (btns.some((b) => b.type === 'URL' && /\{\{/.test(b.url || '') || b.type === 'OTP')) variables.buttons = btns.map((b) => (b.type === 'URL' && /\{\{/.test(b.url || '')) || b.type === 'OTP' ? 'button_value' : null)
  const payload = { from_number_id: number?.phone_number_id || '<from_number_id>', to: '919812345678', template: t.name, language: t.language, variables }
  const json = JSON.stringify(payload, null, 2)
  const url = `${base}/api/v1/templates/send`
  const curl = `curl -X POST ${url} \\\n  -H "X-API-Key: $WAB_API_KEY" \\\n  -H "Content-Type: application/json" -H "Accept: application/json" \\\n  -d '${JSON.stringify(payload).replace(/'/g, "'\\''")}'`
  const phpArr = (v, ind = '    ') => Array.isArray(v) ? `[${v.map((x) => phpArr(x, ind)).join(', ')}]`
    : v && typeof v === 'object' ? `[\n${Object.entries(v).map(([k, x]) => `${ind}    '${k}' => ${phpArr(x, ind + '    ')}`).join(",\n")},\n${ind}]`
    : v === null ? 'null' : `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
  const php = `<?php
// composer require guzzlehttp/guzzle   — run on your SERVER, key from env
$client = new \\GuzzleHttp\\Client(['timeout' => 15]);
$res = $client->post('${url}', [
    'headers' => ['X-API-Key' => getenv('WAB_API_KEY'), 'Accept' => 'application/json'],
    'json' => ${phpArr(payload)},
    'http_errors' => false,
]);
$data = json_decode((string) $res->getBody(), true);
if ($res->getStatusCode() !== 201) {
    error_log('WhatsApp send failed: '.$res->getStatusCode().' '.($data['message'] ?? $data['error'] ?? ''));
}`
  const node = `// Node 18+ (server-side only), key from process.env
const res = await fetch('${url}', {
  method: 'POST',
  headers: { 'X-API-Key': process.env.WAB_API_KEY, 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify(${json.replace(/\n/g, '\n  ')}),
});
const data = await res.json();
if (res.status !== 201) console.error('WhatsApp send failed', res.status, data.message || data.error);`
  return { curl, php, node, json, payload }
}

export function UseViaApiModal({ template, numbersPath, keysHref, onClose }) {
  const [nums] = useLoad(numbersPath); const toast = useToast()
  const [numId, setNumId] = useState(''); const [tab, setTab] = useState('curl')
  const number = nums.find((x) => String(x.id) === numId) || nums[0]
  const sn = useMemo(() => snippets({ template, number }), [template, number])
  const code = sn[tab]
  return (<Modal wide title={`Use via API · ${template.name} (${template.language})`} onClose={onClose}
    footer={<Button onClick={onClose}>Close</Button>}>
    <ServerSideWarning />
    <div className="form-3">
      <Field label="Send from"><select value={number ? String(number.id) : ''} onChange={(e) => setNumId(e.target.value)}>
        {nums.length ? nums.map((x) => <option key={x.id} value={x.id}>{x.display_number}{x.name ? ` · ${x.name}` : ''}</option>) : <option value="">No numbers</option>}</select></Field>
      <Field label="from_number_id"><div className="copy-row"><code className="grow">{number?.phone_number_id || '—'}</code>{number && <Button size="sm" icon={Copy} onClick={() => copyText(toast, number.phone_number_id)} />}</div></Field>
      <Field label="Endpoint"><code>POST /api/v1/templates/send</code></Field>
    </div>
    <div className="tabs">{[['curl', 'cURL'], ['php', 'PHP (Guzzle)'], ['node', 'Node.js (fetch)'], ['json', 'JSON body']].map(([k, l]) =>
      <button key={k} type="button" className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{l}</button>)}</div>
    <div className="code-wrap"><Button size="sm" className="code-copy" icon={Copy} onClick={() => copyText(toast, code)}>Copy</Button><pre className="code">{code}</pre></div>
    <p className="muted small">Replace <code>to</code> and the variable values. <code>variables.body</code> has one entry per {'{{n}}'} in order. Auth: <code>X-API-Key</code> or <code>Authorization: Bearer</code> header (never the URL).
      Success is HTTP 201 with <code>{'{success, id, wamid, status}'}</code>; 422 = bad input, 401/403 = key or allowlist, 429 = rate limit (see <code>Retry-After</code>), 502 = Meta rejected the send.
      {keysHref && <> Create a key on the <a href={keysHref}>API keys</a> page.</>}</p>
  </Modal>)
}

function KeyForm({ scope, clients, initial, onSaved, onCancel }) {
  const toast = useToast(); const base = scope === 'client' ? '/client/api-keys' : '/api-keys'
  const [f, setF] = useState(() => ({ name: initial?.name || '', client_id: initial?.client_id ? String(initial.client_id) : '',
    ips: (initial?.allowed_ips || []).join('\n'), origins: (initial?.allowed_origins || []).join('\n'), rate: initial?.rate_limit_per_minute || 60 }))
  const [busy, setBusy] = useState(false)
  const save = async (e) => {
    e.preventDefault(); setBusy(true)
    const body = { name: f.name, allowed_ips: lines(f.ips), allowed_origins: lines(f.origins), rate_limit_per_minute: Number(f.rate) || 60,
      ...(scope === 'admin' && !initial ? { client_id: f.client_id ? Number(f.client_id) : null } : {}) }
    try { const r = await api(initial ? `${base}/${initial.id}` : base, { method: initial ? 'PUT' : 'POST', body }); onSaved(r) } catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  return (<form className="form" onSubmit={save}>
    <Field label="Label"><input placeholder="e.g. Oswal website – new orders" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required maxLength={100} /></Field>
    {scope === 'admin' && !initial && <Field label="Client" hint="A client key can only use that client's numbers and templates"><select value={f.client_id} onChange={(e) => setF({ ...f, client_id: e.target.value })}>
      <option value="">— Global admin key (all numbers) —</option>{clients.map((c) => <option key={c.id} value={c.id}>{c.name}{c.company ? ` · ${c.company}` : ''}</option>)}</select></Field>}
    <Field label="IP allowlist (optional)" hint="One per line. IPs or CIDR, e.g. 203.0.113.5 or 10.0.0.0/24. Empty = any IP"><textarea rows={2} value={f.ips} onChange={(e) => setF({ ...f, ips: e.target.value })} placeholder="203.0.113.5" /></Field>
    <Field label="Origin / Referer domains (optional)" hint="One per line, e.g. oswalsoap.com or *.oswal.in. When set, requests must send a matching Origin or Referer header"><textarea rows={2} value={f.origins} onChange={(e) => setF({ ...f, origins: e.target.value })} placeholder="oswalsoap.com" /></Field>
    <Field label="Rate limit (requests / minute)"><input type="number" min={1} max={scope === 'client' ? 300 : 6000} value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Field>
    <div className="row gap8"><Button type="submit" variant="primary" icon={initial ? Check : KeyRound} loading={busy}>{initial ? 'Save' : 'Generate key'}</Button>{onCancel && <Button onClick={onCancel}>Cancel</Button>}</div>
  </form>)
}

export function RequestLogs({ scope, keys = [], clientFilter }) {
  const base = scope === 'client' ? '/client/api-keys/logs' : '/api-keys/logs'
  const [keyId, setKeyId] = useState(''); const [status, setStatus] = useState(''); const [page, setPage] = useState(1)
  const [d, setD] = useState(null); const [tick, setTick] = useState(0); const toast = useToast()
  useEffect(() => {
    const qs = new URLSearchParams({ page, per_page: 15, ...(keyId && { api_key_id: keyId }), ...(status && { status }), ...(clientFilter && { client_id: clientFilter }) })
    api(`${base}?${qs}`).then(setD).catch((e) => toast.error(e.message))
  }, [base, keyId, status, page, tick, clientFilter]) // eslint-disable-line
  return (<Card pad={false} icon={Activity} title="Request logs" actions={<div className="filters">
    <select value={keyId} onChange={(e) => { setKeyId(e.target.value); setPage(1) }}><option value="">All keys</option>{keys.map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}</select>
    <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}><option value="">All</option><option value="ok">Success</option><option value="error">Errors</option></select>
    <Button size="sm" icon={RefreshCw} onClick={() => setTick(tick + 1)}>Refresh</Button></div>}>
    <div className="table-wrap"><table className="table"><thead><tr><th>Time</th><th>Key</th>{scope === 'admin' && <th>Client</th>}<th>Endpoint</th><th>Status</th><th className="right">Latency</th><th>Template</th><th>To</th><th>IP</th></tr></thead>
      {!d ? <TableSkeleton cols={8} rows={4} /> : d.data.length ? <tbody>{d.data.map((l) => <tr key={l.id}>
        <td className="muted nowrap">{fmtTime(l.created_at)}</td><td>{l.api_key ? <><b>{l.api_key.name}</b><small className="block muted"><code>{l.api_key.prefix}…</code></small></> : <span className="muted">—</span>}</td>
        {scope === 'admin' && <td>{l.client?.name || <span className="muted">Global</span>}</td>}
        <td><code>{l.method} {l.endpoint}</code></td>
        <td><Badge tone={l.status < 300 ? 'green' : l.status === 429 ? 'amber' : 'red'}>{l.status}</Badge>{l.error && <small className="block err-text" style={{ maxWidth: 320 }}>{l.error}</small>}</td>
        <td className="right nowrap">{l.latency_ms} ms</td><td>{l.template || <span className="muted">—</span>}</td><td className="nowrap">{l.to || '—'}</td><td className="muted nowrap">{l.ip}</td></tr>)}</tbody>
        : <tbody><tr><td colSpan={9}><Empty icon={Activity} title="No API requests yet">Calls made with your keys appear here.</Empty></td></tr></tbody>}
    </table></div>
    {d && d.total > 0 && <div className="pager"><span className="muted small">{d.from}–{d.to} of {d.total}</span><div className="row gap8">
      <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Prev</Button><span className="small">Page {d.current_page} / {d.last_page}</span>
      <Button size="sm" disabled={page >= d.last_page} onClick={() => setPage(page + 1)}>Next</Button></div></div>}
  </Card>)
}

export function ApiKeysPage({ scope = 'admin' }) {
  const base = scope === 'client' ? '/client/api-keys' : '/api-keys'
  const [clientFilter, setClientFilter] = useState('')
  const [rows, reload, loading] = useLoad(`${base}${scope === 'admin' && clientFilter ? `?client_id=${clientFilter}` : ''}`, [clientFilter])
  const [clients] = useLoad(scope === 'admin' ? '/clients-list' : null)
  const toast = useToast(); const [plain, setPlain] = useState(null); const [editing, setEditing] = useState(null); const [formKey, setFormKey] = useState(0)
  const revoke = async (k) => { if (!confirm(`Revoke "${k.name}"? Apps using it stop working immediately.`)) return
    try { await api(`${base}/${k.id}`, { method: 'DELETE' }); toast.success('Key revoked'); reload() } catch (e) { toast.error(e.message) } }
  const restr = (k) => [k.allowed_ips?.length && `${k.allowed_ips.length} IP`, k.allowed_origins?.length && `${k.allowed_origins.length} domain`].filter(Boolean).join(' · ') || 'None'
  return (<div className="page">
    <PageHeader icon={KeyRound} title="API keys" subtitle={scope === 'client' ? 'Send approved templates from your own server (e.g. new-order alerts)' : 'Keys for the external API — global or per client'} />
    <ServerSideWarning />
    <div className="grid-form">
      <div className="stack-cards">
        <Card title={editing ? `Edit ${editing.name}` : 'Generate key'} icon={editing ? Pencil : Plus}>
          {editing ? <KeyForm scope={scope} clients={clients} initial={editing} onCancel={() => setEditing(null)} onSaved={() => { toast.success('Key updated'); setEditing(null); reload() }} />
            : <KeyForm key={formKey} scope={scope} clients={clients} onSaved={(r) => { setPlain(r.plain_key); setFormKey(formKey + 1); toast.success('API key generated'); reload() }} />}
          {plain && <div className="alert success"><div><b>Copy now — it won't be shown again.</b> Store it in your server's env (never in browser code).
            <div className="copy-row"><code className="grow">{plain}</code><Button size="sm" icon={Copy} onClick={() => copyText(toast, plain)} /></div>
            <button className="link" onClick={() => setPlain(null)}>I've saved it</button></div></div>}
        </Card>
        <Card title="How to call" icon={Code2}><pre className="code">{`POST ${location.origin}/api/v1/templates/send
X-API-Key: <key>           (or Authorization: Bearer <key>)
Content-Type: application/json

{"from_number_id":"<id>","to":"919812345678",
 "template":"order_update","language":"en_US",
 "variables":{"body":["Ravi","#1001"],"header":"#1001"}}`}</pre>
          <p className="muted small">Open a template and click <b>Use via API</b> for ready-to-copy cURL, PHP and Node code. Keys in the URL query string are rejected; HTTPS only.</p></Card>
      </div>
      <Card title={`Keys (${rows.length})`} pad={false} actions={scope === 'admin' && <select value={clientFilter} onChange={(e) => setClientFilter(e.target.value)}>
        <option value="">All clients</option><option value="global">Global keys</option>{clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>}>
        <div className="table-wrap"><table className="table"><thead><tr><th>Label</th>{scope === 'admin' && <th>Client</th>}<th>Key</th><th>Restrictions</th><th>Limit</th><th>Last used</th><th>Status</th><th /></tr></thead>
          {loading ? <TableSkeleton cols={7} /> : rows.length ? <tbody>{rows.map((k) => <tr key={k.id} className={k.active ? '' : 'dim'}>
            <td><div className="cell-user"><span className="ico-tile"><KeyRound size={16} /></span><div><b>{k.name}</b><small className="block muted">Created {fmtTime(k.created_at)}</small></div></div></td>
            {scope === 'admin' && <td>{k.client?.name || <Badge tone="blue">Global</Badge>}</td>}
            <td><code>{k.prefix}…</code></td><td className="small">{restr(k)}</td><td className="nowrap">{k.rate_limit_per_minute}/min</td>
            <td>{k.last_used_at ? <>{fmtTime(k.last_used_at)}<small className="block muted">{k.last_used_ip}</small></> : <span className="muted">Never</span>}</td>
            <td>{k.active ? <Badge tone="green">Active</Badge> : <Badge tone="red">Revoked</Badge>}</td>
            <td className="right nowrap">{k.active && <><Button size="sm" variant="ghost" icon={Pencil} onClick={() => setEditing(k)}>Edit</Button>
              <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => revoke(k)}>Revoke</Button></>}</td></tr>)}</tbody>
            : <tbody><tr><td colSpan={8}><Empty icon={KeyRound} title="No API keys">Generate one to send WhatsApp alerts from your website.</Empty></td></tr></tbody>}
        </table></div>
      </Card>
    </div>
    <RequestLogs scope={scope} keys={rows} clientFilter={scope === 'admin' && clientFilter !== 'global' ? clientFilter : ''} />
  </div>)
}

/** Red banner when Meta says the WABA can't (or can only partly) send. Admin only. */
export function WabaHealthBanner() {
  const [h, setH] = useState(null)
  useEffect(() => { api('/waba-health').then(setH).catch(() => {}) }, [])
  if (!h?.blocked) return null
  const items = (h.entities || []).filter((e) => (e.errors || []).length || ['BLOCKED', 'LIMITED'].includes(e.can_send_message))
  return (<div className="alert error waba-banner" role="alert"><AlertOctagon size={20} /><div>
    <b>WhatsApp account {h.can_send_message === 'BLOCKED' ? 'is blocked' : 'is limited'} by Meta</b> — {h.can_send_message === 'BLOCKED' ? 'messages cannot be sent' : 'some messages may not be delivered'}.
    <ul>{items.map((e, i) => <li key={i}><b>{e.entity_type} {e.id}</b> · {e.can_send_message}
      {(e.errors || []).map((x, j) => <div key={j} className="small">{x.error_code && <code>{x.error_code}</code>} {x.error_description}{x.possible_solution && <><br /><i>Fix: {x.possible_solution}</i></>}</div>)}</li>)}</ul>
    <small className="muted">Checked {fmtTime(h.checked_at)}{h.fetch_error ? ` · last refresh failed: ${h.fetch_error}` : ''}</small></div></div>)
}
