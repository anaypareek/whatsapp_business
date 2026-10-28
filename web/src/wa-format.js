// WhatsApp template-editor helpers (pure functions; unit-tested in wa-format.test.js).
import { createElement as h, Fragment } from 'react'

const VAR_RE = /\{\{\s*(\d+)\s*\}\}/g

/** Old numbers of every placeholder, in order of appearance. */
export function placeholders(text) { return [...String(text || '').matchAll(VAR_RE)].map((m) => Number(m[1])) }

/**
 * Renumber placeholders 1..n in order of appearance. Each occurrence gets its own number (like WhatsApp Manager).
 * examples: previous example values indexed by old number-1; they follow their variable.
 * caret: optional cursor position, adjusted for length changes before it.
 */
export function renumber(text, examples = [], caret = null) {
  text = String(text || '')
  let out = '', last = 0, n = 0, newCaret = caret
  const ex = []
  for (const m of text.matchAll(VAR_RE)) {
    n += 1
    const rep = `{{${n}}}`
    out += text.slice(last, m.index) + rep
    if (caret != null && m.index + m[0].length <= caret) newCaret += rep.length - m[0].length
    else if (caret != null && m.index < caret) newCaret = out.length // caret inside a placeholder → after it
    ex.push(examples[Number(m[1]) - 1] ?? '')
    last = m.index + m[0].length
  }
  out += text.slice(last)
  return { text: out, examples: ex, caret: newCaret, count: n }
}

/** Insert the next {{n}} at the caret (then renumber, so it gets the right position number). */
export function insertVariable(text, examples, start, end = start) {
  text = String(text || '')
  const tmp = `{{${placeholders(text).reduce((a, b) => Math.max(a, b), 0) + 1}}}`
  const r = renumber(text.slice(0, start) + tmp + text.slice(end), examples, start + tmp.length)
  return { ...r, start: r.caret, end: r.caret }
}

/** Insert plain text (e.g. an emoji) at the selection. */
export function insertAt(text, start, end, ins) {
  text = String(text || '')
  return { text: text.slice(0, start) + ins + text.slice(end), start: start + ins.length, end: start + ins.length }
}

export const MARKERS = { bold: '*', italic: '_', strike: '~', mono: '```' }

/**
 * Toggle a WhatsApp marker around the selection. Keeps the inner text selected so toggles combine (*_text_*).
 * No selection: insert a pair and put the caret between.
 */
export function toggleWrap(text, start, end, marker) {
  text = String(text || '')
  const m = marker, L = m.length
  if (start > end) [start, end] = [end, start]
  const sel = text.slice(start, end)
  // already wrapped just outside the selection → unwrap
  if (start >= L && text.slice(start - L, start) === m && text.slice(end, end + L) === m && !(start === end)) {
    return { text: text.slice(0, start - L) + sel + text.slice(end + L), start: start - L, end: end - L }
  }
  // selection itself includes the markers → unwrap
  if (sel.length >= 2 * L + 1 && sel.startsWith(m) && sel.endsWith(m)) {
    const inner = sel.slice(L, sel.length - L)
    return { text: text.slice(0, start) + inner + text.slice(end), start, end: start + inner.length }
  }
  if (start === end) return { text: text.slice(0, start) + m + m + text.slice(end), start: start + L, end: start + L }
  // keep surrounding whitespace outside the markers (WhatsApp ignores "* bold *")
  const lead = sel.match(/^\s*/)[0].length, trail = sel.match(/\s*$/)[0].length
  const s2 = start + lead, e2 = Math.max(s2, end - trail)
  const inner = text.slice(s2, e2)
  return { text: text.slice(0, s2) + m + inner + m + text.slice(e2), start: s2 + L, end: s2 + L + inner.length }
}

// ---- rendering ----
const TYPES = [['```', 'mono'], ['*', 'bold'], ['_', 'italic'], ['~', 'strike']]
const isWord = (c) => !!c && /[\p{L}\p{N}]/u.test(c)

/** Parse WhatsApp formatting into a tree: strings and {type, children}. Nesting supported; monospace is literal. */
export function parseWa(s) {
  s = String(s ?? '')
  const out = []
  let buf = '', i = 0
  while (i < s.length) {
    let hit = null
    for (const [m, type] of TYPES) {
      if (!s.startsWith(m, i) || isWord(s[i - 1])) continue
      const first = s[i + m.length]
      if (!first || /\s/.test(first)) continue
      const L = m.length
      let j = s.indexOf(m, i + L)
      while (j !== -1) {
        const inner = s.slice(i + L, j)
        if (m !== '```' && inner.includes('\n')) { j = -1; break }
        if (inner.length && !/\s/.test(inner[inner.length - 1]) && !isWord(s[j + L])) break
        j = s.indexOf(m, j + 1)
      }
      if (j !== -1) { hit = { m, type, j }; break }
    }
    if (hit) {
      if (buf) { out.push(buf); buf = '' }
      const inner = s.slice(i + hit.m.length, hit.j)
      out.push({ type: hit.type, children: hit.type === 'mono' ? [inner] : parseWa(inner) })
      i = hit.j + hit.m.length
    } else { buf += s[i]; i += 1 }
  }
  if (buf) out.push(buf)
  return out
}

const TAG = { bold: 'strong', italic: 'em', strike: 's', mono: 'code' }
/** React nodes for WhatsApp-formatted text (text is never injected as HTML). */
export function renderWa(s) {
  const walk = (nodes, k) => nodes.map((n, i) => typeof n === 'string' ? h(Fragment, { key: `${k}.${i}` }, n)
    : h(TAG[n.type], { key: `${k}.${i}`, className: n.type === 'mono' ? 'wa-mono' : undefined }, walk(n.children, `${k}.${i}`)))
  return walk(parseWa(s), 'w')
}
