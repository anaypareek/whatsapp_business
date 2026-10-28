import { describe, it, expect } from 'vitest'
import { renderToStaticMarkup } from 'react-dom/server'
import { createElement as h } from 'react'
import { renumber, insertVariable, toggleWrap, parseWa, renderWa, placeholders, insertAt } from './wa-format'

const html = (s) => renderToStaticMarkup(h('p', null, renderWa(s)))

describe('renumber', () => {
  it('renumbers in order of appearance', () => {
    expect(renumber('Hi {{3}}, order {{1}} at {{7}}').text).toBe('Hi {{1}}, order {{2}} at {{3}}')
  })
  it('keeps examples with their variable', () => {
    const r = renumber('{{2}} then {{1}}', ['first', 'second'])
    expect(r.text).toBe('{{1}} then {{2}}')
    expect(r.examples).toEqual(['second', 'first'])
  })
  it('drops examples of deleted variables', () => {
    expect(renumber('a {{2}}', ['x', 'y']).examples).toEqual(['y'])
  })
  it('normalises spaces and counts', () => {
    const r = renumber('{{ 5 }} {{ 9}}')
    expect(r.text).toBe('{{1}} {{2}}'); expect(r.count).toBe(2)
  })
  it('adjusts the caret when lengths change', () => {
    const t = '{{10}} x'
    expect(renumber(t, [], t.length).caret).toBe(t.length - 1)
  })
  it('no placeholders = unchanged', () => { expect(renumber('plain *text*').text).toBe('plain *text*') })
})

describe('insertVariable', () => {
  it('appends the next number at the caret', () => {
    const r = insertVariable('Hi {{1}}, ', ['Ravi'], 10)
    expect(r.text).toBe('Hi {{1}}, {{2}}'); expect(r.examples).toEqual(['Ravi', '']); expect(r.start).toBe(15)
  })
  it('inserting before an existing variable renumbers and shifts examples', () => {
    const r = insertVariable('Order {{1}}', ['OS-7'], 0)
    expect(r.text).toBe('{{1}}Order {{2}}'); expect(r.examples).toEqual(['', 'OS-7']); expect(r.start).toBe(5)
  })
  it('replaces a selection', () => {
    expect(insertVariable('Hi NAME!', [], 3, 7).text).toBe('Hi {{1}}!')
  })
  it('placeholders() lists numbers', () => { expect(placeholders('{{2}} {{1}}')).toEqual([2, 1]) })
  it('insertAt inserts emoji', () => { expect(insertAt('ab', 1, 1, '🙂')).toEqual({ text: 'a🙂b', start: 3, end: 3 }) })
})

describe('toggleWrap', () => {
  it('wraps a selection and keeps inner text selected', () => {
    expect(toggleWrap('make bold now', 5, 9, '*')).toEqual({ text: 'make *bold* now', start: 6, end: 10 })
  })
  it('inserts a pair with the caret between when nothing selected', () => {
    expect(toggleWrap('ab', 1, 1, '_')).toEqual({ text: 'a__b', start: 2, end: 2 })
    expect(toggleWrap('', 0, 0, '```')).toEqual({ text: '``````', start: 3, end: 3 })
  })
  it('combines bold + italic', () => {
    const a = toggleWrap('text', 0, 4, '*')
    const b = toggleWrap(a.text, a.start, a.end, '_')
    expect(b.text).toBe('*_text_*'); expect(b.text.slice(b.start, b.end)).toBe('text')
  })
  it('toggles off (marker outside selection)', () => {
    expect(toggleWrap('*bold*', 1, 5, '*')).toEqual({ text: 'bold', start: 0, end: 4 })
  })
  it('toggles off inner marker of a combo only', () => {
    const r = toggleWrap('*_text_*', 2, 6, '_')
    expect(r.text).toBe('*text*')
  })
  it('toggles off when markers are inside the selection', () => {
    expect(toggleWrap('~gone~', 0, 6, '~').text).toBe('gone')
  })
  it('keeps whitespace outside markers', () => {
    expect(toggleWrap('a word b', 1, 7, '*').text).toBe('a *word* b')
  })
})

describe('parseWa / renderWa', () => {
  it('basic markers', () => {
    expect(html('*b* _i_ ~s~ ```m```')).toBe('<p><strong>b</strong> <em>i</em> <s>s</s> <code class="wa-mono">m</code></p>')
  })
  it('nesting bold+italic', () => {
    expect(html('*_both_*')).toBe('<p><strong><em>both</em></strong></p>')
    expect(html('_*both*_ and ~*x*~')).toBe('<p><em><strong>both</strong></em> and <s><strong>x</strong></s></p>')
  })
  it('monospace content is literal', () => {
    expect(parseWa('```*not bold*```')).toEqual([{ type: 'mono', children: ['*not bold*'] }])
  })
  it('ignores markers inside words and unmatched/space-padded markers', () => {
    expect(html('2*3*4 snake_case_name')).toBe('<p>2*3*4 snake_case_name</p>')
    expect(html('* not bold *')).toBe('<p>* not bold *</p>')
    expect(html('a * b')).toBe('<p>a * b</p>')
  })
  it('does not span lines', () => { expect(html('*a\nb*')).toBe('<p>*a\nb*</p>') })
  it('escapes HTML (no injection)', () => {
    const out = html('*<img src=x onerror=alert(1)>* <script>x</script>')
    expect(out).not.toContain('<img'); expect(out).not.toContain('<script')
    expect(out).toContain('&lt;img')
  })
  it('keeps placeholders', () => { expect(html('Hi *{{1}}*')).toBe('<p>Hi <strong>{{1}}</strong></p>') })
})
