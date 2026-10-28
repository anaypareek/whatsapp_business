// Template/composer editing helpers: WhatsApp formatting toolbar + lightweight emoji picker (data lazy-loaded).
import { useEffect, useRef, useState } from 'react'
import { Bold, Italic, Strikethrough, Code, Smile, Braces, Search, X } from 'lucide-react'
import { toggleWrap, insertAt, MARKERS } from './wa-format'

/** Run an edit fn(text, start, end) -> {text, start, end} against an input/textarea ref, then restore the selection. */
export function editAt(ref, value, onChange, fn) {
  const el = ref.current
  const start = el ? el.selectionStart ?? value.length : value.length
  const end = el ? el.selectionEnd ?? start : start
  const r = fn(value, start, end)
  onChange(r.text, r)
  requestAnimationFrame(() => { const e = ref.current; if (e) { e.focus(); try { e.setSelectionRange(r.start, r.end) } catch { /* ignore */ } } })
}

const RECENT = 'wab_recent_emoji'
let dataPromise = null
const loadEmoji = () => (dataPromise ||= import('./emoji-data.js').then((m) => m.default))

export function EmojiPicker({ onPick, onClose }) {
  const [data, setData] = useState(null); const [q, setQ] = useState(''); const [cat, setCat] = useState(0)
  const [recent, setRecent] = useState(() => { try { return JSON.parse(localStorage.getItem(RECENT) || '[]') } catch { return [] } })
  const box = useRef(null)
  useEffect(() => { loadEmoji().then(setData) }, [])
  useEffect(() => {
    const off = (e) => { if (box.current && !box.current.contains(e.target) && !e.target.closest?.('.emoji-toggle')) onClose() }
    const esc = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('mousedown', off); document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', off); document.removeEventListener('keydown', esc) }
  }, [onClose])
  const pick = (e) => {
    const r = [e, ...recent.filter((x) => x !== e)].slice(0, 16); setRecent(r)
    try { localStorage.setItem(RECENT, JSON.stringify(r)) } catch { /* ignore */ }
    onPick(e)
  }
  const term = q.trim().toLowerCase()
  const items = !data ? [] : term ? data.flatMap((c) => c.items).filter(([, k]) => k.includes(term)) : data[cat].items
  return (<div className="emoji-pop" ref={box} role="dialog" aria-label="Emoji picker">
    <div className="emoji-search"><Search size={14} /><input autoFocus placeholder="Search emoji" value={q} onChange={(e) => setQ(e.target.value)} />
      {q && <button type="button" className="icon-btn" onClick={() => setQ('')} aria-label="Clear"><X size={14} /></button>}</div>
    {!term && recent.length > 0 && <div className="emoji-recent"><small className="muted">Recent</small><div className="emoji-grid">
      {recent.map((e) => <button type="button" key={e} onClick={() => pick(e)}>{e}</button>)}</div></div>}
    {!term && data && <div className="emoji-cats">{data.map((c, i) => <button type="button" key={c.name} title={c.name} className={i === cat ? 'on' : ''} onClick={() => setCat(i)}>{c.icon}</button>)}</div>}
    {!term && data && <small className="muted emoji-cat-name">{data[cat].name}</small>}
    <div className="emoji-grid scroll">{!data ? <span className="muted small">Loading…</span> : items.length ? items.map(([e, k]) =>
      <button type="button" key={e + k} title={k} onClick={() => pick(e)}>{e}</button>) : <span className="muted small">No emoji found</span>}</div>
  </div>)
}

/** Smile button + popover. onPick(emoji). */
export function EmojiButton({ onPick, className = '', up = false, size = 18 }) {
  const [open, setOpen] = useState(false)
  return (<span className={`emoji-wrap ${up ? 'up' : ''}`}>
    <button type="button" className={`emoji-toggle ${className} ${open ? 'on' : ''}`} title="Emoji" aria-label="Emoji" onClick={() => setOpen((o) => !o)}><Smile size={size} /></button>
    {open && <EmojiPicker onPick={(e) => onPick(e)} onClose={() => setOpen(false)} />}
  </span>)
}

/** Bold / Italic / Strike / Mono + optional Add variable + emoji, for a textarea bound to (value, onChange). */
export function FormatToolbar({ inputRef, value, onChange, onAddVariable, addVarLabel = 'Add variable', addVarDisabled, emoji = true }) {
  const wrap = (m) => editAt(inputRef, value, onChange, (t, s, e) => toggleWrap(t, s, e, m))
  const B = (Icon, m, title) => <button type="button" className="tb-btn" title={title} aria-label={title} onMouseDown={(e) => e.preventDefault()} onClick={() => wrap(m)}><Icon size={15} /></button>
  return (<div className="fmt-toolbar">
    {B(Bold, MARKERS.bold, 'Bold (*text*)')}{B(Italic, MARKERS.italic, 'Italic (_text_)')}{B(Strikethrough, MARKERS.strike, 'Strikethrough (~text~)')}{B(Code, MARKERS.mono, 'Monospace (```text```)')}
    {emoji && <EmojiButton className="tb-btn" size={15} onPick={(em) => editAt(inputRef, value, onChange, (t, s, e) => insertAt(t, s, e, em))} />}
    {onAddVariable && <button type="button" className="tb-btn wide" disabled={addVarDisabled} onMouseDown={(e) => e.preventDefault()} onClick={onAddVariable}><Braces size={15} /> {addVarLabel}</button>}
  </div>)
}
