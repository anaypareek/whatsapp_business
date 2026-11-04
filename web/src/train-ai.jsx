import { useEffect, useRef, useState } from 'react'
import { GraduationCap, Paperclip, HelpCircle, ChevronRight, Sparkles, Send, Pencil, Trash2, Upload, Image as ImageIcon, FileText, BookOpen, Check, X, UserRound, Package } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Badge, PageHeader, useToast, useLoad, Empty, Skeleton } from './ui'

/** Admin-only "Train AI": playground, corrections (Q→A), knowledge files and product images — all per number. */
export function TrainAi({ scope = 'admin' }) {
  const [nums] = useLoad(scope === 'client' ? '/client/numbers' : '/phone-numbers')
  const [view, setView] = useState('teach')
  const [sel, setSel] = useState(null)
  const base = `${scope === 'client' ? '/client' : ''}/ai-train/${sel}`
  useEffect(() => { if (!sel && nums.length) setSel(nums[0].id) }, [nums.length]) // eslint-disable-line
  return (<div className="page">
    <PageHeader icon={GraduationCap} title="Train AI" subtitle="Just chat: tell the AI your prices, policies and answers, or drop in files and product photos. It saves what it learns." />
    <div className="row" style={{ marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
      {nums.map((n) => <Button key={n.id} size="sm" variant={n.id === sel ? 'primary' : 'secondary'} onClick={() => setSel(n.id)}>{n.name || n.display_number}</Button>)}
    </div>
    {sel ? <div key={sel}>
      <div className="tabs" style={{ marginBottom: 12 }}>
        <button className={view === 'teach' ? 'on' : ''} onClick={() => setView('teach')}><Sparkles size={14} /> Teach</button>
        <button className={view === 'questions' ? 'on' : ''} onClick={() => setView('questions')}><HelpCircle size={14} /> Unanswered questions <OpenCount base={base} /></button>
      </div>
      {view === 'teach' ? <><TrainerChat id={sel} base={base} />
        <details className="advanced"><summary><ChevronRight size={14} /> Advanced — playground, rules, files and images</summary><Trainer id={sel} base={base} /></details></>
        : <Unanswered base={base} />}
    </div> : <Card><Empty icon={GraduationCap} title="No numbers">Add a WhatsApp number first.</Empty></Card>}
  </div>)
}

function Trainer({ id, base }) {
  const [d, reload, loading] = useLoad(`${base}`)
  if (loading && !d.examples) return <Card><Skeleton h={200} /></Card>
  return (<div className="grid-form">
    <Playground id={id} base={base} reload={reload} />
    <div className="stack-cards">
      <Examples id={id} base={base} rows={d.examples || []} reload={reload} />
      <Files id={id} base={base} rows={d.files || []} reload={reload} />
      <Images id={id} base={base} rows={d.images || []} reload={reload} />
    </div>
  </div>)
}

function Playground({ base, reload }) {
  const toast = useToast(); const [msgs, setMsgs] = useState([]); const [input, setInput] = useState(''); const [busy, setBusy] = useState(false)
  const [fix, setFix] = useState(null); const box = useRef()
  useEffect(() => { if (box.current) box.current.scrollTop = box.current.scrollHeight }, [msgs.length])
  const send = async (e) => {
    e.preventDefault(); if (!input.trim()) return
    const next = [...msgs, { role: 'user', content: input.trim() }]; setMsgs(next); setInput(''); setBusy(true)
    try {
      const r = await api(`${base}/chat`, { method: 'POST', body: { messages: next.map(({ role, content }) => ({ role, content })) } })
      setMsgs([...next, { role: 'assistant', content: r.reply || r.raw, handoff: r.handoff, image: r.image, order: r.order_lookup }])
    } catch (err) { toast.error(err.message); setMsgs(msgs) } finally { setBusy(false) }
  }
  const saveFix = async () => {
    try { await api(`${base}/examples`, { method: 'POST', body: { question: fix.question, answer: fix.answer } }); toast.success('Correction saved — the AI will use it for similar questions'); setFix(null); reload() }
    catch (err) { toast.error(err.message) }
  }
  return (<Card title="Playground" icon={Send}>
    <p className="muted small">Chat with the AI exactly as a customer would. Nothing is sent on WhatsApp and no order API is called. Uses the saved OpenAI key.</p>
    <div className="train-chat" ref={box}>
      {!msgs.length && <div className="muted small center-pad">Try “hi”, “price of toor dal?”, “send a photo of basmati rice” or “where is my order?”</div>}
      {msgs.map((m, i) => <div key={i} className={`msg ${m.role === 'user' ? 'out' : 'in'}`}><div className="bubble">
        {m.image && <img src={m.image.url} alt={m.image.name} style={{ maxWidth: 180, borderRadius: 6 }} />}
        <div className="text">{m.content}</div>
        <div className="meta">{m.handoff && <Badge tone="amber"><UserRound size={11} /> handoff</Badge>}{m.order && <Badge tone="blue"><Package size={11} /> order lookup: {m.order}</Badge>}
          {m.role === 'assistant' && <button className="link small" onClick={() => setFix({ question: msgs[i - 1]?.content || '', answer: m.content })}><Pencil size={11} /> Correct this reply</button>}</div>
      </div></div>)}
    </div>
    {fix && <div className="form" style={{ marginTop: 10 }}>
      <Field label="Customer question"><input value={fix.question} onChange={(e) => setFix({ ...fix, question: e.target.value })} /></Field>
      <Field label="Correct answer" hint="Saved as an example; the most relevant few are added to the AI prompt"><textarea rows={3} value={fix.answer} onChange={(e) => setFix({ ...fix, answer: e.target.value })} /></Field>
      <div className="row" style={{ gap: 8 }}><Button variant="primary" icon={Check} onClick={saveFix}>Save correction</Button><Button icon={X} onClick={() => setFix(null)}>Cancel</Button></div>
    </div>}
    <form className="row" style={{ gap: 8, marginTop: 10 }} onSubmit={send}>
      <input className="grow" value={input} onChange={(e) => setInput(e.target.value)} placeholder="Type as a customer…" />
      <Button type="submit" variant="primary" icon={Send} loading={busy}>Send</Button>{msgs.length > 0 && <Button onClick={() => setMsgs([])}>Clear</Button>}
    </form>
  </Card>)
}

function Examples({ base, rows, reload }) {
  const toast = useToast(); const [f, setF] = useState({ question: '', answer: '' })
  const add = async (e) => { e.preventDefault(); try { await api(`${base}/examples`, { method: 'POST', body: f }); setF({ question: '', answer: '' }); reload() } catch (err) { toast.error(err.message) } }
  const del = async (x) => { if (!confirm('Delete this example?')) return; try { await api(`${base}/examples/${x.id}`, { method: 'DELETE' }); reload() } catch (err) { toast.error(err.message) } }
  return (<Card title={`Corrections & rules (${rows.length})`} icon={BookOpen}>
    <form className="form" onSubmit={add}>
      <Field label="When a customer asks"><input value={f.question} onChange={(e) => setF({ ...f, question: e.target.value })} placeholder="Do you deliver on Sunday?" /></Field>
      <Field label="Answer"><textarea rows={2} value={f.answer} onChange={(e) => setF({ ...f, answer: e.target.value })} placeholder="No, we deliver Monday to Saturday 🙏" /></Field>
      <div><Button type="submit" size="sm" variant="primary" disabled={!f.question || !f.answer}>Add example</Button></div>
    </form>
    {rows.length > 0 && <div className="table-wrap"><table className="table"><tbody>{rows.map((x) => <tr key={x.id}><td><b className="small">Q: {x.question}</b><div className="muted small">A: {x.answer}</div></td>
      <td><Button size="sm" icon={Trash2} onClick={() => del(x)} /></td></tr>)}</tbody></table></div>}
  </Card>)
}

function Files({ base, rows, reload }) {
  const toast = useToast(); const [busy, setBusy] = useState(false)
  const up = async (e) => {
    const file = e.target.files?.[0]; e.target.value = ''; if (!file) return
    const form = new FormData(); form.append('file', file); setBusy(true)
    try { const r = await api(`${base}/files`, { method: 'POST', form }); toast.success(`${r.filename}: ${r.chunks} chunks extracted`); reload() } catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const del = async (x) => { if (!confirm(`Remove ${x.filename}?`)) return; try { await api(`${base}/files/${x.id}`, { method: 'DELETE' }); reload() } catch (err) { toast.error(err.message) } }
  return (<Card title="Knowledge files" icon={FileText}>
    <p className="muted small">PDF, TXT or CSV price lists (max 10 MB). Text is split into small chunks; only the few most relevant are sent with each question.</p>
    <label className="btn btn-secondary btn-sm"><Upload size={14} /> {busy ? 'Uploading…' : 'Upload file'}<input type="file" hidden accept=".pdf,.txt,.csv" onChange={up} disabled={busy} /></label>
    {rows.length > 0 && <div className="table-wrap"><table className="table"><tbody>{rows.map((x) => <tr key={x.id}><td>{x.filename} <Badge>{x.kind}</Badge></td><td className="muted small">{x.chunks} chunks · {x.chars} chars</td>
      <td><Button size="sm" icon={Trash2} onClick={() => del(x)} /></td></tr>)}</tbody></table></div>}
  </Card>)
}

function Images({ base, rows, reload }) {
  const toast = useToast(); const [f, setF] = useState({ name: '', tags: '', file: null }); const [busy, setBusy] = useState(false)
  const add = async (e) => {
    e.preventDefault(); const form = new FormData(); form.append('image', f.file); form.append('name', f.name); form.append('tags', f.tags); setBusy(true)
    try { await api(`${base}/images`, { method: 'POST', form }); setF({ name: '', tags: '', file: null }); e.target.reset(); reload() } catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const del = async (x) => { if (!confirm(`Delete ${x.name}?`)) return; try { await api(`${base}/images/${x.id}`, { method: 'DELETE' }); reload() } catch (err) { toast.error(err.message) } }
  return (<Card title="Product images" icon={ImageIcon}>
    <p className="muted small">When a customer asks for a photo, the AI picks the matching image by name/tags and sends it (JPG/PNG, max 5 MB).</p>
    <form className="form" onSubmit={add}>
      <div className="form-2"><Field label="Product name"><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Basmati rice 5kg" /></Field>
        <Field label="Tags"><input value={f.tags} onChange={(e) => setF({ ...f, tags: e.target.value })} placeholder="rice, basmati, chawal" /></Field></div>
      <input type="file" accept="image/jpeg,image/png" onChange={(e) => setF({ ...f, file: e.target.files?.[0] || null })} />
      <div><Button type="submit" size="sm" variant="primary" icon={Upload} loading={busy} disabled={!f.name || !f.file}>Add image</Button></div>
    </form>
    {rows.length > 0 && <div className="img-grid">{rows.map((x) => <div key={x.id} className="img-tile"><img src={x.url} alt={x.name} /><div className="small"><b>{x.name}</b><div className="muted">{x.tags}</div></div>
      <Button size="sm" icon={Trash2} onClick={() => del(x)} /></div>)}</div>}
  </Card>)
}

/** The main, simple trainer chat. History is stored on the server per number. */
function TrainerChat({ base }) {
  const toast = useToast()
  const [d, reload, loading] = useLoad(`${base}/trainer`)
  const [local, setLocal] = useState([]) // optimistic messages not yet in d
  const [text, setText] = useState(''); const [files, setFiles] = useState([]); const [busy, setBusy] = useState(false)
  const box = useRef(); const fileRef = useRef()
  const msgs = [...(d.messages || []), ...local]
  useEffect(() => { if (box.current) box.current.scrollTop = box.current.scrollHeight }, [msgs.length, busy])
  const send = async (e) => {
    e?.preventDefault(); if (busy || (!text.trim() && !files.length)) return
    const form = new FormData(); form.append('message', text.trim()); files.forEach((f) => form.append('files[]', f))
    setLocal([{ id: 'tmp', role: 'user', content: text.trim() || `(attached ${files.length} file(s))`, attachments: files.map((f) => ({ type: f.type.startsWith('image/') ? 'image' : 'file', filename: f.name })) }])
    setText(''); setFiles([]); setBusy(true)
    try { await api(`${base}/trainer`, { method: 'POST', form }) } catch (err) { toast.error(err.message) }
    finally { await reload(); setLocal([]); setBusy(false) }
  }
  const clear = async () => { if (!confirm('Clear this training chat? Saved rules, files and images stay.')) return; await api(`${base}/trainer`, { method: 'DELETE' }); reload() }
  const dismiss = async (q) => { await api(`${base}/questions/${q.id}`, { method: 'DELETE' }); reload() }
  const open = d.open_questions || []
  return (<Card title="Teach your AI" icon={Sparkles} actions={msgs.length > 0 && <Button size="sm" onClick={clear}>Clear chat</Button>}>
    <div className="train-chat big" ref={box}>
      {loading && !d.messages && <Skeleton h={120} />}
      {!msgs.length && !loading && <div className="msg in"><div className="bubble"><div className="text">Hi 👋 Tell me anything customers should know — prices, delivery areas, timings, return policy — or attach a price list (PDF/CSV/TXT) or product photos. I'll ask if something is unclear and save it for the customer AI.</div></div></div>}
      {d.greeting && <div className="msg in"><div className="bubble"><div className="text">{d.greeting}</div>
        <div className="q-chips">{open.map((q) => <span key={q.id} className="q-chip"><HelpCircle size={12} /><button className="link" onClick={() => setText(`Answer for "${q.question}": `)}>{q.question}</button><button className="link muted" title="Dismiss" onClick={() => dismiss(q)}><X size={12} /></button></span>)}</div></div></div>}
      {msgs.map((m) => <div key={m.id} className={`msg ${m.role === 'user' ? 'out' : 'in'}`}><div className="bubble">
        {(m.attachments || []).map((a, i) => a.type === 'image' && a.url ? <img key={i} src={a.url} alt={a.filename} style={{ maxWidth: 160, borderRadius: 6, display: 'block' }} /> : <div key={i} className="small muted"><FileText size={12} /> {a.filename}</div>)}
        <div className="text">{m.content}</div>
        {m.saved?.length > 0 && <div className="saved-note"><Check size={12} /> Saved: {m.saved.join(' · ')}</div>}
      </div></div>)}
      {busy && <div className="msg in"><div className="bubble"><div className="text muted">Thinking…</div></div></div>}
    </div>
    {files.length > 0 && <div className="row small" style={{ gap: 6, marginTop: 8, flexWrap: 'wrap' }}>{files.map((f, i) => <Badge key={i}>{f.name} <button className="link" onClick={() => setFiles(files.filter((_, j) => j !== i))}><X size={10} /></button></Badge>)}</div>}
    <form className="row composer" style={{ gap: 8, marginTop: 10 }} onSubmit={send}>
      <Button type="button" icon={Paperclip} title="Attach PDF, TXT, CSV or image" onClick={() => fileRef.current?.click()} />
      <input ref={fileRef} type="file" hidden multiple accept=".pdf,.txt,.csv,image/jpeg,image/png" onChange={(e) => { setFiles([...files, ...e.target.files].slice(0, 5)); e.target.value = '' }} />
      <textarea className="grow" rows={1} value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) send(e) }} placeholder="e.g. Toor dal is ₹145/kg, free delivery above ₹500…" />
      <Button type="submit" variant="primary" icon={Send} loading={busy}>Send</Button>
    </form>
  </Card>)
}

function OpenCount({ base }) {
  const [d] = useLoad(`${base}/questions`)
  const n = d.open?.length || 0
  return n ? <span className="count-pill">{n}</span> : null
}

/** Customer questions the AI handed off. Answering saves a rule for THIS number and resolves the question. */
function Unanswered({ base }) {
  const toast = useToast(); const [d, reload, loading] = useLoad(`${base}/questions`); const [ans, setAns] = useState({}); const [busy, setBusy] = useState(null)
  const answer = async (q) => {
    setBusy(q.id)
    try { await api(`${base}/questions/${q.id}/answer`, { method: 'POST', body: { answer: ans[q.id] } }); toast.success('Saved as a rule — the AI will answer this from now on'); reload() }
    catch (err) { toast.error(err.message) } finally { setBusy(null) }
  }
  const dismiss = async (q) => { if (!confirm('Dismiss this question without an answer?')) return; await api(`${base}/questions/${q.id}`, { method: 'DELETE' }); reload() }
  const open = d.open || []; const done = d.resolved || []
  const when = (t) => t ? new Date(t).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : ''
  return (<Card title={`Unanswered questions (${open.length})`} icon={HelpCircle}>
    <p className="muted small">Customers asked these and the AI handed them to a human. Type the answer once; it is saved as a rule for this number only.</p>
    {loading && !d.open && <Skeleton h={80} />}
    {!loading && !open.length && <Empty icon={Check} title="All caught up">No unanswered questions for this number.</Empty>}
    <div className="uq-list">{open.map((q) => <div key={q.id} className="uq">
      <div className="uq-head"><b>“{q.question}”</b><span className="muted small"><UserRound size={12} /> {q.contact_name || 'Customer'}{q.contact_wa_id ? ` · +${q.contact_wa_id}` : ''} · {when(q.created_at)}</span></div>
      <div className="row" style={{ gap: 8 }}>
        <textarea className="grow" rows={2} value={ans[q.id] || ''} onChange={(e) => setAns({ ...ans, [q.id]: e.target.value })} placeholder="Type the answer customers should get…" />
        <div className="stack" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}><Button size="sm" variant="primary" icon={Check} loading={busy === q.id} disabled={!ans[q.id]?.trim()} onClick={() => answer(q)}>Save answer</Button>
          <Button size="sm" icon={X} onClick={() => dismiss(q)}>Dismiss</Button></div>
      </div></div>)}</div>
    {done.length > 0 && <details className="advanced"><summary><ChevronRight size={14} /> Answered recently ({done.length})</summary>
      <div className="uq-list">{done.map((q) => <div key={q.id} className="uq done"><b className="small">Q: {q.question}</b><div className="small">A: {q.answer}</div><div className="muted small">{when(q.resolved_at)}</div></div>)}</div></details>}
  </Card>)
}
