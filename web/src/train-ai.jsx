import { useEffect, useRef, useState } from 'react'
import { GraduationCap, Send, Pencil, Trash2, Upload, Image as ImageIcon, FileText, BookOpen, Check, X, UserRound, Package } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Badge, PageHeader, useToast, useLoad, Empty, Skeleton } from './ui'

/** Admin-only "Train AI": playground, corrections (Q→A), knowledge files and product images — all per number. */
export function TrainAi() {
  const [nums] = useLoad('/phone-numbers')
  const [sel, setSel] = useState(null)
  useEffect(() => { if (!sel && nums.length) setSel(nums[0].id) }, [nums.length]) // eslint-disable-line
  return (<div className="page">
    <PageHeader icon={GraduationCap} title="Train AI" subtitle="Test the AI, correct its answers, and add documents and product photos — per WhatsApp number" />
    <div className="row" style={{ marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
      {nums.map((n) => <Button key={n.id} size="sm" variant={n.id === sel ? 'primary' : 'secondary'} onClick={() => setSel(n.id)}>{n.name || n.display_number}</Button>)}
    </div>
    {sel ? <Trainer key={sel} id={sel} /> : <Card><Empty icon={GraduationCap} title="No numbers">Add a WhatsApp number first.</Empty></Card>}
  </div>)
}

function Trainer({ id }) {
  const [d, reload, loading] = useLoad(`/ai-train/${id}`)
  if (loading && !d.examples) return <Card><Skeleton h={200} /></Card>
  return (<div className="grid-form">
    <Playground id={id} reload={reload} />
    <div className="stack-cards">
      <Examples id={id} rows={d.examples || []} reload={reload} />
      <Files id={id} rows={d.files || []} reload={reload} />
      <Images id={id} rows={d.images || []} reload={reload} />
    </div>
  </div>)
}

function Playground({ id, reload }) {
  const toast = useToast(); const [msgs, setMsgs] = useState([]); const [input, setInput] = useState(''); const [busy, setBusy] = useState(false)
  const [fix, setFix] = useState(null); const box = useRef()
  useEffect(() => { if (box.current) box.current.scrollTop = box.current.scrollHeight }, [msgs.length])
  const send = async (e) => {
    e.preventDefault(); if (!input.trim()) return
    const next = [...msgs, { role: 'user', content: input.trim() }]; setMsgs(next); setInput(''); setBusy(true)
    try {
      const r = await api(`/ai-train/${id}/chat`, { method: 'POST', body: { messages: next.map(({ role, content }) => ({ role, content })) } })
      setMsgs([...next, { role: 'assistant', content: r.reply || r.raw, handoff: r.handoff, image: r.image, order: r.order_lookup }])
    } catch (err) { toast.error(err.message); setMsgs(msgs) } finally { setBusy(false) }
  }
  const saveFix = async () => {
    try { await api(`/ai-train/${id}/examples`, { method: 'POST', body: { question: fix.question, answer: fix.answer } }); toast.success('Correction saved — the AI will use it for similar questions'); setFix(null); reload() }
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

function Examples({ id, rows, reload }) {
  const toast = useToast(); const [f, setF] = useState({ question: '', answer: '' })
  const add = async (e) => { e.preventDefault(); try { await api(`/ai-train/${id}/examples`, { method: 'POST', body: f }); setF({ question: '', answer: '' }); reload() } catch (err) { toast.error(err.message) } }
  const del = async (x) => { if (!confirm('Delete this example?')) return; try { await api(`/ai-train/${id}/examples/${x.id}`, { method: 'DELETE' }); reload() } catch (err) { toast.error(err.message) } }
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

function Files({ id, rows, reload }) {
  const toast = useToast(); const [busy, setBusy] = useState(false)
  const up = async (e) => {
    const file = e.target.files?.[0]; e.target.value = ''; if (!file) return
    const form = new FormData(); form.append('file', file); setBusy(true)
    try { const r = await api(`/ai-train/${id}/files`, { method: 'POST', form }); toast.success(`${r.filename}: ${r.chunks} chunks extracted`); reload() } catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const del = async (x) => { if (!confirm(`Remove ${x.filename}?`)) return; try { await api(`/ai-train/${id}/files/${x.id}`, { method: 'DELETE' }); reload() } catch (err) { toast.error(err.message) } }
  return (<Card title="Knowledge files" icon={FileText}>
    <p className="muted small">PDF, TXT or CSV price lists (max 10 MB). Text is split into small chunks; only the few most relevant are sent with each question.</p>
    <label className="btn btn-secondary btn-sm"><Upload size={14} /> {busy ? 'Uploading…' : 'Upload file'}<input type="file" hidden accept=".pdf,.txt,.csv" onChange={up} disabled={busy} /></label>
    {rows.length > 0 && <div className="table-wrap"><table className="table"><tbody>{rows.map((x) => <tr key={x.id}><td>{x.filename} <Badge>{x.kind}</Badge></td><td className="muted small">{x.chunks} chunks · {x.chars} chars</td>
      <td><Button size="sm" icon={Trash2} onClick={() => del(x)} /></td></tr>)}</tbody></table></div>}
  </Card>)
}

function Images({ id, rows, reload }) {
  const toast = useToast(); const [f, setF] = useState({ name: '', tags: '', file: null }); const [busy, setBusy] = useState(false)
  const add = async (e) => {
    e.preventDefault(); const form = new FormData(); form.append('image', f.file); form.append('name', f.name); form.append('tags', f.tags); setBusy(true)
    try { await api(`/ai-train/${id}/images`, { method: 'POST', form }); setF({ name: '', tags: '', file: null }); e.target.reset(); reload() } catch (err) { toast.error(err.message) } finally { setBusy(false) }
  }
  const del = async (x) => { if (!confirm(`Delete ${x.name}?`)) return; try { await api(`/ai-train/${id}/images/${x.id}`, { method: 'DELETE' }); reload() } catch (err) { toast.error(err.message) } }
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
