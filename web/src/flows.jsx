// Automation Flows: number picker -> flow list -> canvas builder (React Flow) with node palette,
// properties panel and a WhatsApp phone simulator. Shared by the admin panel and the client portal.
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { ReactFlow, ReactFlowProvider, Background, Controls, MiniMap, Handle, Position, MarkerType, addEdge, useNodesState, useEdgesState, useReactFlow } from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { Workflow, MessageSquare, HelpCircle, GitBranch, Webhook, Zap, Phone, Plus, ArrowLeft, Save, Copy, Trash2, Pencil, Play, RotateCcw, Send, X, Image as ImageIcon, FileText, List, Search, CheckCircle2, Users, Activity, Smartphone, Settings2 } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Field, TableSkeleton, Empty, Badge, Toggle, useToast, useLoad, Skeleton } from './ui'

export const MAX_STEPS = 10
const TYPES = {
  trigger: { label: 'Trigger', color: '#F97316', icon: Zap },
  message: { label: 'Send a message', color: '#22C55E', icon: MessageSquare },
  question: { label: 'Ask a question', color: '#EAB308', icon: HelpCircle },
  condition: { label: 'Set a condition', color: '#3B82F6', icon: GitBranch },
  webhook: { label: 'Webhook', color: '#EC4899', icon: Webhook },
}
const MATCH = { contains: 'contains', exact: 'is exactly', starts_with: 'starts with' }
const uid = (p) => `${p}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`
const DEFAULT_DATA = {
  message: () => ({ text: '', media_type: 'none', media_url: '', filename: '' }),
  question: () => ({ text: '', options: [{ id: uid('o'), title: 'Yes', keywords: '' }, { id: uid('o'), title: 'No', keywords: '' }], footer: '', list_button: '', retry_text: '' }),
  condition: () => ({ keywords: [], match_type: 'contains' }),
  webhook: () => ({ url: '' }),
}
const Meta = createContext({})

// ---------- canvas nodes ----------
function NodeCard({ id, type, data, selected }) {
  const meta = useContext(Meta)
  const t = TYPES[type]; const Icon = t.icon
  const row = (h, label, cls = '') => <div className={`fn-opt ${cls}`} key={h}><span>{label}</span><Handle type="source" position={Position.Right} id={h} /></div>
  return (<div className={`fn-card ${selected ? 'sel' : ''}`} style={{ '--nc': t.color }}>
    {type !== 'trigger' && <Handle type="target" position={Position.Left} />}
    <div className="fn-head"><span className="fn-ico"><Icon size={14} /></span><b>{t.label}</b>{type !== 'trigger' && <small>#{meta.index?.[id]}</small>}</div>
    <div className="fn-body">
      {type === 'trigger' && <><div className="fn-muted">When a message {MATCH[meta.trigger_type] || 'contains'}</div>
        <div className="fn-chips">{(meta.keywords || []).length ? meta.keywords.map((k) => <span key={k}>{k}</span>) : <em>add keywords…</em>}</div></>}
      {type === 'message' && <>{data.media_type !== 'none' && data.media_url && <div className="fn-media">{data.media_type === 'image' ? <ImageIcon size={14} /> : <FileText size={14} />}{data.media_type}</div>}
        <div className="fn-bubble">{data.text || <em>Message text…</em>}</div></>}
      {type === 'question' && <><div className="fn-bubble">{data.text || <em>Question…</em>}</div>
        <div className="fn-muted small">{(data.options || []).length <= 3 ? 'Reply buttons' : 'List message'}</div></>}
      {type === 'condition' && <div className="fn-muted">Reply {MATCH[data.match_type]} <b>{(data.keywords || []).join(', ') || '…'}</b></div>}
      {type === 'webhook' && <div className="fn-muted ellipsis">POST {data.url || 'https://…'}</div>}
    </div>
    {type === 'question' && <div className="fn-opts">{(data.options || []).map((o) => row(`opt-${o.id}`, o.title || 'Option'))}</div>}
    {type === 'condition' && <div className="fn-opts">{row('match', 'Matched', 'ok')}{row('nomatch', 'No match', 'no')}</div>}
    {['trigger', 'message', 'webhook'].includes(type) && <Handle type="source" position={Position.Right} id="next" />}
  </div>)
}
const nodeTypes = Object.fromEntries(Object.keys(TYPES).map((k) => [k, NodeCard]))
const edgeDefaults = { type: 'smoothstep', markerEnd: { type: MarkerType.ArrowClosed, width: 18, height: 18, color: '#94A3B8' }, style: { strokeDasharray: '6 5', stroke: '#94A3B8', strokeWidth: 1.6 } }

// ---------- side panel editors ----------
function KeywordInput({ value, onChange, placeholder = 'Type and press Enter' }) {
  const [v, setV] = useState('')
  const add = () => { const k = v.trim(); if (k && !value.some((x) => x.toLowerCase() === k.toLowerCase())) onChange([...value, k]); setV('') }
  return (<div className="kw-input">{value.map((k) => <span key={k} className="kw">{k}<button type="button" onClick={() => onChange(value.filter((x) => x !== k))}><X size={12} /></button></span>)}
    <input value={v} placeholder={value.length ? '' : placeholder} onChange={(e) => setV(e.target.value)} onBlur={add}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add() } else if (e.key === 'Backspace' && !v && value.length) onChange(value.slice(0, -1)) }} /></div>)
}

function NodeEditor({ node, update, remove }) {
  const d = node.data; const set = (p) => update({ ...d, ...p })
  const t = TYPES[node.type]; const Icon = t.icon
  return (<div className="fe-panel">
    <div className="fe-title" style={{ '--nc': t.color }}><span className="fn-ico"><Icon size={14} /></span><b>{t.label}</b>
      <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={remove} title="Delete step">Delete</Button></div>
    {node.type === 'message' && <div className="form">
      <Field label={`Message (${(d.text || '').length}/4096)`} hint="*bold* _italic_ ~strike~"><textarea rows={5} maxLength={4096} value={d.text} onChange={(e) => set({ text: e.target.value })} /></Field>
      <Field label="Media"><div className="seg">{['none', 'image', 'document'].map((m) => <button key={m} type="button" className={d.media_type === m ? 'on' : ''} onClick={() => set({ media_type: m })}>{m}</button>)}</div></Field>
      {d.media_type !== 'none' && <><Field label={`${d.media_type === 'image' ? 'Image' : 'Document'} URL`} hint="Public https link (JPG/PNG or PDF)"><input value={d.media_url || ''} onChange={(e) => set({ media_url: e.target.value })} placeholder="https://…" /></Field>
        {d.media_type === 'document' && <Field label="File name (optional)"><input value={d.filename || ''} onChange={(e) => set({ filename: e.target.value })} /></Field>}</>}
    </div>}
    {node.type === 'question' && <div className="form">
      <Field label={`Question (${(d.text || '').length}/1024)`}><textarea rows={4} maxLength={1024} value={d.text} onChange={(e) => set({ text: e.target.value })} /></Field>
      <div className="row between"><span className="field-label">Options ({d.options.length}/10)</span><Badge tone={d.options.length <= 3 ? 'green' : 'blue'}>{d.options.length <= 3 ? 'Reply buttons' : 'List message'}</Badge></div>
      {d.options.map((o, i) => <div key={o.id} className="opt-edit">
        <span className="opt-n">{i + 1}</span>
        <div className="grow"><input maxLength={d.options.length <= 3 ? 20 : 24} placeholder="Option title" value={o.title} onChange={(e) => set({ options: d.options.map((x) => x.id === o.id ? { ...x, title: e.target.value } : x) })} />
          <input className="small-in" placeholder="Also match typed words (comma separated)" value={o.keywords || ''} onChange={(e) => set({ options: d.options.map((x) => x.id === o.id ? { ...x, keywords: e.target.value } : x) })} /></div>
        <button type="button" className="icon-btn" disabled={d.options.length <= 1} onClick={() => set({ options: d.options.filter((x) => x.id !== o.id) })}><X size={14} /></button></div>)}
      <Button size="sm" icon={Plus} disabled={d.options.length >= 10} onClick={() => set({ options: [...d.options, { id: uid('o'), title: '', keywords: '' }] })}>Add option</Button>
      <p className="muted small">Up to 3 options go as WhatsApp reply buttons (20 chars), 4–10 as a list message (24 chars). Each option has its own branch on the canvas.</p>
      {d.options.length > 3 && <Field label="List button text"><input maxLength={20} value={d.list_button || ''} placeholder="Choose an option" onChange={(e) => set({ list_button: e.target.value })} /></Field>}
      <Field label="Footer (optional)"><input maxLength={60} value={d.footer || ''} onChange={(e) => set({ footer: e.target.value })} /></Field>
      <Field label="If the reply doesn't match" hint="Sent before the question is asked again"><input maxLength={500} value={d.retry_text || ''} placeholder="Sorry, I didn't get that. Please choose one of the options." onChange={(e) => set({ retry_text: e.target.value })} /></Field>
    </div>}
    {node.type === 'condition' && <div className="form">
      <Field label="Match the contact's last reply"><select value={d.match_type} onChange={(e) => set({ match_type: e.target.value })}>{Object.entries(MATCH).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
      <Field label="Keywords" hint="Case-insensitive; any keyword matches"><KeywordInput value={d.keywords || []} onChange={(k) => set({ keywords: k })} /></Field>
      <p className="muted small">Connect the <b className="ok-t">Matched</b> and <b className="no-t">No match</b> branches to the next steps.</p>
    </div>}
    {node.type === 'webhook' && <div className="form">
      <Field label="Webhook URL" hint="We POST JSON: flow_id, contact, phone_number, last_input"><input value={d.url} placeholder="https://example.com/hooks/lead" onChange={(e) => set({ url: e.target.value })} /></Field>
      <p className="muted small">The flow continues to the next step right after the call. Private and local addresses are blocked.</p>
    </div>}
  </div>)
}

function FlowSettings({ meta, setMeta, stats }) {
  return (<div className="fe-panel">
    <div className="fe-title" style={{ '--nc': TYPES.trigger.color }}><span className="fn-ico"><Zap size={14} /></span><b>Trigger &amp; settings</b></div>
    <div className="form">
      <Field label="Flow name"><input maxLength={120} value={meta.name} onChange={(e) => setMeta({ ...meta, name: e.target.value })} /></Field>
      <Field label="Start when an incoming message"><select value={meta.trigger_type} onChange={(e) => setMeta({ ...meta, trigger_type: e.target.value })}>{Object.entries(MATCH).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
      <Field label="Keywords" hint="Case-insensitive. Press Enter after each keyword."><KeywordInput value={meta.keywords} onChange={(k) => setMeta({ ...meta, keywords: k })} placeholder="hi, menu, order…" /></Field>
      <div className="row between"><span className="field-label">Active</span><Toggle checked={meta.is_active} onChange={(v) => setMeta({ ...meta, is_active: v })} /></div>
      <p className="muted small">Active flows take priority over simple auto-replies. A contact's session expires after 24 hours without a reply.</p>
      {stats && <div className="fe-stats"><div><b>{stats.triggered_count}</b><span>Triggered</span></div><div><b>{stats.completed_count}</b><span>Completed</span></div><div><b>{stats.active_sessions}</b><span>Live sessions</span></div></div>}
    </div>
  </div>)
}

// ---------- phone simulator ----------
function Simulator({ P, draft, number }) {
  const [chat, setChat] = useState([]); const [waiting, setWaiting] = useState(null); const [text, setText] = useState(''); const [busy, setBusy] = useState(false); const [sheet, setSheet] = useState(null)
  const end = useRef(); const toast = useToast()
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [chat])
  const reset = () => { setChat([]); setWaiting(null); setSheet(null) }
  const send = async (t, iid) => {
    if (!t?.trim() && !iid) return
    setChat((c) => [...c, { me: true, text: t }]); setText(''); setBusy(true); setSheet(null)
    try {
      const r = await api(`${P}/flows-simulate`, { method: 'POST', body: { flow: draft(), waiting, text: iid ? null : t, interactive_id: iid || null } })
      const out = []
      if (r.event === 'no_trigger') out.push({ sys: `No trigger keyword matched${draft().keywords.length ? ` (try “${draft().keywords[0]}”)` : ''}.` })
      if (r.event === 'restart') out.push({ sys: 'Trigger keyword matched: flow restarted.' })
      for (const o of r.outputs || []) {
        if (o.kind === 'message') out.push({ text: o.text, media: o.media_url ? o.media_type : null, url: o.media_url, filename: o.filename })
        else if (o.kind === 'question') out.push({ text: o.text, q: o.interactive, options: o.options })
        else if (o.kind === 'condition') out.push({ sys: `Condition: ${o.matched ? 'matched' : 'no match'}` })
        else if (o.kind === 'webhook') out.push({ sys: `Webhook POST → ${o.url} (not called in test mode)` })
      }
      if (r.completed && r.event !== 'no_trigger') out.push({ sys: 'Flow completed ✓' })
      setChat((c) => [...c, ...out]); setWaiting(r.waiting || null)
    } catch (e) { toast.error(e.message); setChat((c) => [...c, { sys: `Error: ${e.message}` }]) } finally { setBusy(false) }
  }
  const lastQ = [...chat].reverse().find((m) => m.q)
  return (<div className="sim">
    <div className="phone"><div className="phone-notch" />
      <div className="phone-top"><span className="avatar sm">{(number?.name || 'WA').slice(0, 2).toUpperCase()}</span><div><b>{number?.name || 'Business'}</b><small>{number?.display_number}</small></div>
        <button className="icon-btn" title="Reset conversation" onClick={reset}><RotateCcw size={15} /></button></div>
      <div className="phone-chat">
        {!chat.length && <div className="sim-hint"><Smartphone size={22} /><b>Test this flow</b><span>Type a trigger keyword below. Nothing is sent to WhatsApp.</span></div>}
        {chat.map((m, i) => m.sys ? <div key={i} className="sim-sys">{m.sys}</div> : <div key={i} className={`sim-msg ${m.me ? 'me' : ''}`}>
          {m.media === 'image' && <div className="sim-media">{/^https?:/.test(m.url) ? <img src={m.url} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} /> : null}<ImageIcon size={16} /> Image</div>}
          {m.media === 'document' && <div className="sim-media"><FileText size={16} /> {m.filename || 'Document'}</div>}
          {m.text && <div className="sim-text">{m.text}</div>}
          {m.q?.type === 'button' && <div className="sim-btns">{m.options.map((o) => <button key={o.id} disabled={busy || m !== lastQ || !waiting} onClick={() => send(o.title, o.id)}>{o.title}</button>)}</div>}
          {m.q?.type === 'list' && <div className="sim-btns"><button disabled={busy || m !== lastQ || !waiting} onClick={() => setSheet(m)}><List size={14} /> {m.q.action.button}</button></div>}
        </div>)}
        <div ref={end} />
      </div>
      {sheet && <div className="sim-sheet"><div className="row between"><b>{sheet.q.action.sections[0].title}</b><button className="icon-btn" onClick={() => setSheet(null)}><X size={15} /></button></div>
        {sheet.options.map((o) => <button key={o.id} onClick={() => send(o.title, o.id)}>{o.title}<span className="radio" /></button>)}</div>}
      <form className="phone-input" onSubmit={(e) => { e.preventDefault(); send(text) }}>
        <input placeholder="Type a message" value={text} onChange={(e) => setText(e.target.value)} disabled={busy} /><button className="send" disabled={busy || !text.trim()}><Send size={16} /></button></form>
    </div>
  </div>)
}

// ---------- builder ----------
const toRF = (nodes) => nodes.map((n) => ({ id: n.id, type: n.type, position: n.position || { x: 0, y: 0 }, data: n.data || {}, deletable: n.type !== 'trigger' }))
const fromRF = (nodes) => nodes.map((n) => ({ id: n.id, type: n.type, position: { x: Math.round(n.position.x), y: Math.round(n.position.y) }, data: n.type === 'trigger' ? {} : n.data }))
const newFlowGraph = () => {
  const m = uid('m')
  return { nodes: [{ id: 'trigger', type: 'trigger', position: { x: 0, y: 120 }, data: {} }, { id: m, type: 'message', position: { x: 340, y: 100 }, data: { ...DEFAULT_DATA.message(), text: 'Hi! 👋 Thanks for messaging us.' } }],
    edges: [{ id: `e-trigger-next-${m}`, source: 'trigger', sourceHandle: 'next', target: m }] }
}

function BuilderInner({ P, number, flow, onBack, onSaved }) {
  const toast = useToast(); const rf = useReactFlow(); const wrap = useRef()
  const g = flow?.id ? flow : { ...newFlowGraph(), name: 'New flow', trigger_type: 'contains', keywords: [], is_active: false }
  const [meta, setMeta] = useState({ name: g.name, trigger_type: g.trigger_type, keywords: g.keywords || [], is_active: !!g.is_active })
  const [nodes, setNodes, onNodesChange] = useNodesState(toRF(g.nodes))
  const [edges, setEdges, onEdgesChange] = useEdgesState(g.edges.map((e) => ({ ...e, ...edgeDefaults })))
  const [sel, setSel] = useState(null); const [tab, setTab] = useState('edit'); const [busy, setBusy] = useState(false); const [saved, setSaved] = useState(flow)
  const steps = nodes.filter((n) => n.type !== 'trigger').length
  const index = useMemo(() => Object.fromEntries(nodes.filter((n) => n.type !== 'trigger').map((n, i) => [n.id, i + 1])), [nodes])
  const selected = nodes.find((n) => n.id === sel)

  const onConnect = useCallback((c) => setEdges((es) => addEdge({ ...c, id: `e-${c.source}-${c.sourceHandle}-${c.target}`, ...edgeDefaults },
    es.filter((e) => !(e.source === c.source && (e.sourceHandle || 'next') === (c.sourceHandle || 'next'))))), [setEdges])
  const add = (type, position) => {
    if (steps >= MAX_STEPS) { toast.error(`A flow can have at most ${MAX_STEPS} steps.`); return }
    const id = uid(type[0])
    const pos = position || (() => { const r = wrap.current.getBoundingClientRect(); return rf.screenToFlowPosition({ x: r.left + r.width / 2 - 130, y: r.top + r.height / 2 - 60 + (steps % 4) * 24 }) })()
    setNodes((ns) => [...ns.map((n) => ({ ...n, selected: false })), { id, type, position: pos, data: DEFAULT_DATA[type](), selected: true }])
    setSel(id); setTab('edit')
  }
  const onDrop = (e) => { e.preventDefault(); const t = e.dataTransfer.getData('application/flow-node'); if (t) add(t, rf.screenToFlowPosition({ x: e.clientX - 130, y: e.clientY - 30 })) }
  const update = (data) => setNodes((ns) => ns.map((n) => {
    if (n.id !== sel) return n
    if (n.type === 'question') { const keep = new Set(data.options.map((o) => `opt-${o.id}`)); setEdges((es) => es.filter((e) => e.source !== n.id || keep.has(e.sourceHandle))) }
    return { ...n, data }
  }))
  const remove = () => { setNodes((ns) => ns.filter((n) => n.id !== sel)); setEdges((es) => es.filter((e) => e.source !== sel && e.target !== sel)); setSel(null) }
  const draft = () => ({ id: saved?.id || 0, trigger_type: meta.trigger_type, keywords: meta.keywords, nodes: fromRF(rf.getNodes()), edges: rf.getEdges().map(({ id, source, sourceHandle, target }) => ({ id, source, sourceHandle: sourceHandle || 'next', target })) })
  const save = async (patch = {}) => {
    setBusy(true)
    try {
      const body = { ...meta, ...patch, ...draft() }; delete body.id
      const r = saved?.id ? await api(`${P}/flows/${saved.id}`, { method: 'PUT', body }) : await api(`${P}/flows`, { method: 'POST', body: { ...body, phone_number_id: number.id } })
      setSaved(r); setMeta((m) => ({ ...m, ...patch })); toast.success(patch.is_active === undefined ? 'Flow saved' : patch.is_active ? 'Flow activated' : 'Flow deactivated'); onSaved?.(r)
    } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const duplicate = async () => { try { const r = await api(`${P}/flows/${saved.id}/duplicate`, { method: 'POST' }); toast.success(`Created “${r.name}” (inactive)`); onSaved?.(r) } catch (e) { toast.error(e.message) } }

  return (<Meta.Provider value={{ ...meta, index }}>
    <div className="fb">
      <div className="fb-bar">
        <Button icon={ArrowLeft} onClick={onBack}>Flows</Button>
        <div className="fb-name"><Workflow size={18} /><input value={meta.name} onChange={(e) => setMeta({ ...meta, name: e.target.value })} aria-label="Flow name" /></div>
        <span className="muted small nowrap"><Phone size={13} /> {number.display_number}</span>
        <span className={`fb-count ${steps >= MAX_STEPS ? 'full' : ''}`}>{steps}/{MAX_STEPS} steps</span>
        <div className="grow" />
        <div className="row nowrap"><span className="small muted">{meta.is_active ? 'Active' : 'Inactive'}</span><Toggle checked={meta.is_active} disabled={busy} onChange={(v) => save({ is_active: v })} /></div>
        {saved?.id && <Button icon={Copy} onClick={duplicate}>Duplicate</Button>}
        <Button variant="primary" icon={Save} loading={busy} onClick={() => save()}>Save</Button>
      </div>
      <div className="fb-body">
        <aside className="fb-palette"><div className="nav-label">Add a step</div>
          {['message', 'question', 'condition', 'webhook'].map((k) => { const t = TYPES[k]; const I = t.icon; return <button key={k} draggable={steps < MAX_STEPS} disabled={steps >= MAX_STEPS} style={{ '--nc': t.color }}
            onDragStart={(e) => { e.dataTransfer.setData('application/flow-node', k); e.dataTransfer.effectAllowed = 'move' }} onClick={() => add(k)}><span className="fn-ico"><I size={16} /></span>{t.label}</button> })}
          <p className="muted small">Drag onto the canvas or click to add. Connect the dots on the right of a card to the next step. {steps >= MAX_STEPS && <b className="err-text">Step limit reached.</b>}</p>
        </aside>
        <div className="fb-canvas" ref={wrap} onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move' }} onDrop={onDrop}>
          <ReactFlow nodes={nodes} edges={edges} nodeTypes={nodeTypes} onNodesChange={onNodesChange} onEdgesChange={onEdgesChange} onConnect={onConnect}
            onSelectionChange={({ nodes: s }) => { if (s[0]) { setSel(s[0].id); setTab('edit') } }} onPaneClick={() => setSel(null)} defaultEdgeOptions={edgeDefaults}
            fitView fitViewOptions={{ padding: 0.2, maxZoom: 1 }} onInit={(inst) => setTimeout(() => { if (inst.getZoom() < 0.62) { const ys = inst.getNodes().map((n) => n.position.y); inst.setViewport({ x: 40, y: 60 - Math.min(...ys) * 0.62, zoom: 0.62 }) } }, 0)} minZoom={0.3} maxZoom={1.6} deleteKeyCode={['Delete']} proOptions={{ hideAttribution: true }}>
            <Background variant="dots" gap={18} size={1.6} color="#C9C2BA" /><Controls showInteractive={false} /><MiniMap pannable zoomable style={{ width: 150, height: 96 }} nodeColor={(n) => TYPES[n.type]?.color} maskColor="rgba(247,245,242,.7)" />
          </ReactFlow>
        </div>
        <aside className="fb-side">
          <div className="seg w100"><button className={tab === 'edit' ? 'on' : ''} onClick={() => setTab('edit')}><Settings2 size={14} /> {selected && selected.type !== 'trigger' ? 'Edit step' : 'Settings'}</button>
            <button className={tab === 'test' ? 'on' : ''} onClick={() => setTab('test')}><Play size={14} /> Test flow</button></div>
          {tab === 'edit' ? (selected && selected.type !== 'trigger' ? <NodeEditor key={selected.id} node={selected} update={update} remove={remove} /> : <FlowSettings meta={meta} setMeta={setMeta} stats={saved} />)
            : <Simulator P={P} draft={draft} number={number} />}
        </aside>
      </div>
    </div>
  </Meta.Provider>)
}
const Builder = (p) => <ReactFlowProvider><BuilderInner {...p} /></ReactFlowProvider>

// ---------- number picker + list ----------
export function Flows({ scope = 'admin' }) {
  const P = scope === 'client' ? '/client' : ''
  const [numbers, , nLoading] = useLoad(`${P}/flow-numbers`)
  const [number, setNumber] = useState(null); const [q, setQ] = useState('')
  const [flows, setFlows] = useState(null); const [edit, setEdit] = useState(null); const toast = useToast()
  const load = useCallback(() => number && api(`${P}/flows?phone_number_id=${number.id}`).then(setFlows).catch((e) => toast.error(e.message)), [number]) // eslint-disable-line
  useEffect(() => { setFlows(null); load() }, [load])
  useEffect(() => { if (!number && numbers.length === 1 && scope === 'client') setNumber(numbers[0]) }, [numbers]) // eslint-disable-line
  const open = async (f) => { try { setEdit(await api(`${P}/flows/${f.id}`)) } catch (e) { toast.error(e.message) } }
  const toggle = async (f) => { try { await api(`${P}/flows/${f.id}`, { method: 'PUT', body: { is_active: !f.is_active } }); toast.success(f.is_active ? 'Flow deactivated' : 'Flow activated'); load() } catch (e) { toast.error(e.message) } }
  const dup = async (f) => { try { await api(`${P}/flows/${f.id}/duplicate`, { method: 'POST' }); toast.success('Flow duplicated (inactive)'); load() } catch (e) { toast.error(e.message) } }
  const del = async (f) => { if (!confirm(`Delete flow “${f.name}”? Live sessions end immediately.`)) return; try { await api(`${P}/flows/${f.id}`, { method: 'DELETE' }); toast.success('Flow deleted'); load() } catch (e) { toast.error(e.message) } }

  if (edit) return <Builder key={edit.id || 'new'} P={P} number={number} flow={edit.id ? edit : null} onBack={() => { setEdit(null); load() }} onSaved={(f) => { if (!edit.id || f.id !== edit.id) setEdit(f); load() }} />

  if (!number) {
    const list = numbers.filter((n) => !q || `${n.display_number} ${n.name || ''} ${n.client?.name || ''}`.toLowerCase().includes(q.toLowerCase()))
    return (<div className="page"><PageHeader icon={Workflow} title="Automation Flows" subtitle="Step 1 of 2 · choose the WhatsApp number to build flows for" />
      <Card title={<div className="filters"><div className="input-ico"><Search size={16} className="ico" /><input placeholder={scope === 'admin' ? 'Search number, name or client' : 'Search your numbers'} value={q} onChange={(e) => setQ(e.target.value)} /></div></div>}>
        {nLoading ? <div className="num-grid">{[1, 2, 3].map((i) => <Skeleton key={i} h={72} r={12} />)}</div> : list.length ? <div className="num-grid">{list.map((n) => <button key={n.id} className="num-card" onClick={() => setNumber(n)}>
          <span className="ico-tile"><Phone size={18} /></span><div className="grow"><b>{n.display_number}</b><small>{n.name || 'No display name'}</small>
            {scope === 'admin' && <small className="muted">{n.client ? `Client: ${n.client.name}` : 'No client'}</small>}</div>
          <Badge tone={n.flows_count ? 'orange' : 'gray'}>{n.flows_count} flow{n.flows_count === 1 ? '' : 's'}</Badge></button>)}</div>
          : <Empty icon={Phone} title="No numbers">{scope === 'admin' ? 'Add a number in Number Master first.' : 'No WhatsApp numbers are assigned to your account yet.'}</Empty>}
      </Card></div>)
  }

  return (<div className="page"><PageHeader icon={Workflow} title="Automation Flows" subtitle={`Step 2 of 2 · flows for ${number.display_number}${number.name ? ` (${number.name})` : ''}${number.client ? ` · ${number.client.name}` : ''}`}>
    {(scope === 'admin' || numbers.length > 1) && <Button icon={ArrowLeft} onClick={() => setNumber(null)}>Change number</Button>}
    <Button variant="primary" icon={Plus} onClick={() => setEdit({})}>New flow</Button></PageHeader>
    <div className="mini-stats">
      <div className="mini"><Workflow size={18} /><b>{flows ? flows.length : '–'}</b><span>Flows</span></div>
      <div className="mini"><Activity size={18} /><b>{flows ? flows.reduce((a, f) => a + f.triggered_count, 0) : '–'}</b><span>Triggered</span></div>
      <div className="mini"><CheckCircle2 size={18} /><b>{flows ? flows.reduce((a, f) => a + f.completed_count, 0) : '–'}</b><span>Completed</span></div>
      <div className="mini"><Users size={18} /><b>{flows ? flows.reduce((a, f) => a + f.active_sessions, 0) : '–'}</b><span>Live sessions</span></div>
    </div>
    <Card pad={false}><div className="table-wrap"><table className="table"><thead><tr><th>Flow</th><th>Trigger</th><th className="right">Steps</th><th className="right">Triggered</th><th className="right">Completed</th><th className="right">Live sessions</th><th>Active</th><th /></tr></thead>
      {flows === null ? <TableSkeleton cols={8} /> : flows.length ? <tbody>{flows.map((f) => <tr key={f.id} className="clickable" onClick={() => open(f)}>
        <td><b>{f.name}</b><small className="block muted">Updated {fmtTime(f.updated_at)}</small></td>
        <td><small className="muted">{MATCH[f.trigger_type]} </small>{f.keywords.map((k) => <span key={k} className="kw sm">{k}</span>)}</td>
        <td className="right">{f.steps}/{MAX_STEPS}</td><td className="right"><b>{f.triggered_count}</b></td><td className="right">{f.completed_count}</td><td className="right">{f.active_sessions}</td>
        <td onClick={(e) => e.stopPropagation()}><Toggle checked={f.is_active} onChange={() => toggle(f)} /></td>
        <td className="right nowrap" onClick={(e) => e.stopPropagation()}><Button size="sm" variant="ghost" icon={Pencil} title="Edit" onClick={() => open(f)} />
          <Button size="sm" variant="ghost" icon={Copy} title="Duplicate" onClick={() => dup(f)} /><Button size="sm" variant="ghost-danger" icon={Trash2} title="Delete" onClick={() => del(f)} /></td></tr>)}</tbody>
        : <tbody><tr><td colSpan={8}><Empty icon={Workflow} title="No flows yet">Create a flow: pick trigger keywords, then add messages, questions and conditions on the canvas.</Empty></td></tr></tbody>}
    </table></div></Card>
  </div>)
}
