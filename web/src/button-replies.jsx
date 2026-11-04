import { useEffect, useState } from 'react'
import { MousePointerClick, Check, MessageSquareText, FileText, Image as ImageIcon } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Badge, Toggle, useToast, useLoad, Empty, Skeleton, PageHeader } from './ui'
import { TplMap, slotProblem } from './greetings'

const TYPES = [['text', 'Text', MessageSquareText], ['template', 'Approved template', FileText], ['media', 'Image / document / video', ImageIcon]]
const EXT = { image: /\.(jpe?g|png)$/i, video: /\.mp4$/i, document: /\.pdf$/i }
const blank = (b) => ({ button_index: b.index, reply_type: 'text', text: '', reply_template_id: null, template_params: null, media_type: 'image', media_url: '', enabled: true, on: false })

/** Problem with one button reply (client-side mirror of the server checks). */
export const replyProblem = (r, templates) => {
  if (!r.on) return null
  if (r.reply_type === 'text') return r.text?.trim() ? null : 'Enter the reply text.'
  if (r.reply_type === 'media') {
    if (!/^https:\/\/\S+$/.test(r.media_url || '')) return `Enter a public https:// link to the ${r.media_type}.`
    return EXT[r.media_type]?.test((r.media_url || '').split('?')[0]) ? null : `A ${r.media_type} link must end in ${r.media_type === 'image' ? '.jpg / .png' : r.media_type === 'video' ? '.mp4' : '.pdf'}.`
  }
  const t = templates.find((x) => x.id === r.reply_template_id)
  return t ? slotProblem(t, r.template_params) : 'Choose an approved template.'
}

/** "Button replies": the follow-up for each QUICK_REPLY button of an APPROVED template. scope 'admin' | 'client'. */
export function ButtonReplies({ scope = 'admin' }) {
  const P = scope === 'client' ? '/client' : ''
  const [rows, reload, loading] = useLoad(`${P}/button-replies`)
  const [sel, setSel] = useState(null)
  useEffect(() => { if (!sel && rows.length) setSel(rows[0].id) }, [rows]) // eslint-disable-line
  return (<div>
    <PageHeader icon={MousePointerClick} title="Button replies" subtitle="When a customer taps a quick-reply button on a template you sent, send this follow-up. Each template has its own replies, even if two templates both have Yes / No." />
    {loading && !rows.length ? <Card><Skeleton h={160} /></Card> : !rows.length ? <Card><Empty icon={MousePointerClick} title="No templates with quick-reply buttons">Approved templates with QUICK_REPLY buttons appear here.</Empty></Card>
      : <div className="br-grid">
        <Card title="Templates" icon={FileText}><div className="br-list">{rows.map((t) => <button key={t.id} className={`br-item ${t.id === sel ? 'on' : ''}`} onClick={() => setSel(t.id)}>
          <b>{t.name}</b><small className="muted">{t.language} · {t.buttons.map((b) => b.text).join(' / ')}</small>
          {t.configured > 0 ? <Badge tone="green">{t.configured} set</Badge> : <Badge>none</Badge>}</button>)}</div></Card>
        {sel && <Editor key={sel} P={P} id={sel} onSaved={reload} />}
      </div>}
    <p className="muted small" style={{ marginTop: 12 }}>Order: button replies → automation flows → keyword auto-replies → greeting → AI. One incoming message gets at most one auto-reply (shown as “auto · button” in chat).</p>
  </div>)
}

function Editor({ P, id, onSaved }) {
  const toast = useToast()
  const [d, setD] = useState(null); const [rs, setRs] = useState([]); const [busy, setBusy] = useState(false)
  useEffect(() => { api(`${P}/button-replies/${id}`).then((x) => { setD(x); setRs(x.template.buttons.map((b) => { const r = x.replies.find((y) => y.button_index === b.index); return r ? { ...blank(b), ...r, media_type: r.media_type || 'image', text: r.text || '', media_url: r.media_url || '', on: true } : blank(b) })) }).catch((e) => toast.error(e.message)) }, [id]) // eslint-disable-line
  if (!d) return <Card><Skeleton h={200} /></Card>
  const set = (i, patch) => setRs((cur) => cur.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  const probs = rs.map((r) => replyProblem(r, d.templates))
  const save = async () => {
    setBusy(true)
    try {
      const replies = rs.filter((r) => r.on).map(({ on, ...r }) => r) // eslint-disable-line no-unused-vars
      await api(`${P}/button-replies/${id}`, { method: 'PUT', body: { replies } }); toast.success('Button replies saved'); onSaved()
    } catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  return (<Card title={`${d.template.name} · ${d.template.language}`} icon={MousePointerClick}>
    <div className="tpl-preview" style={{ marginBottom: 12 }}>{d.template.body}<div className="row" style={{ gap: 6, marginTop: 8 }}>{d.template.buttons.map((b) => <span key={b.index} className="br-chip">↩ {b.text}</span>)}</div></div>
    {rs.map((r, i) => { const b = d.template.buttons[i]; return (<div key={b.index} className="br-row">
      <div className="row" style={{ justifyContent: 'space-between' }}><span className="field-label">When customer taps <b>“{b.text}”</b></span><Toggle checked={r.on} onChange={(v) => set(i, { on: v })} /></div>
      {r.on && <>
        <div className="row" style={{ gap: 6, flexWrap: 'wrap', margin: '6px 0' }}>{TYPES.map(([k, l, I]) => <Button key={k} size="sm" icon={I} variant={r.reply_type === k ? 'primary' : 'secondary'} onClick={() => set(i, { reply_type: k })}>{l}</Button>)}</div>
        {r.reply_type === 'text' && <Field label="Reply text" hint="{name} = contact's WhatsApp name"><textarea rows={3} value={r.text} onChange={(e) => set(i, { text: e.target.value })} placeholder={`Reply for “${b.text}”`} /></Field>}
        {r.reply_type === 'template' && <TplMap tid={r.reply_template_id} params={r.template_params} templates={d.templates} onTpl={(tid, p) => set(i, { reply_template_id: tid, template_params: p })} />}
        {r.reply_type === 'media' && <div className="form-3">
          <Field label="Type"><select value={r.media_type} onChange={(e) => set(i, { media_type: e.target.value })}><option value="image">Image (.jpg/.png)</option><option value="document">Document (.pdf)</option><option value="video">Video (.mp4)</option></select></Field>
          <Field label="Public https:// link" className="span2"><input value={r.media_url} onChange={(e) => set(i, { media_url: e.target.value })} placeholder="https://…/catalog.pdf" /></Field>
          <Field label="Caption (optional)" className="span3"><input value={r.text} onChange={(e) => set(i, { text: e.target.value })} placeholder="Here is our catalog" /></Field></div>}
        {probs[i] && <div className="alert-warn small">{probs[i]}</div>}
      </>}
    </div>) })}
    <div className="row" style={{ marginTop: 12 }}><Button variant="primary" icon={Check} loading={busy} disabled={probs.some(Boolean)} onClick={save}>Save button replies</Button></div>
  </Card>)
}
