import { describe, it, expect } from 'vitest'
import { snippets } from './apikeys.jsx'

const tpl = { name: 'order_update', language: 'en_US', components: [
  { type: 'HEADER', format: 'TEXT', text: 'Order {{1}}', example: { header_text: ['#1001'] } },
  { type: 'BODY', text: 'Hi {{1}}, order {{2}} ships {{3}}', example: { body_text: [['Ravi', '#1001']] } },
  { type: 'BUTTONS', buttons: [{ type: 'URL', text: 'Track', url: 'https://x.com/t/{{1}}' }, { type: 'QUICK_REPLY', text: 'Ok' }] }] }

describe('Use via API snippets', () => {
  const s = snippets({ template: tpl, number: { phone_number_id: 'PN123' }, base: 'https://h.test' })
  it('prefills payload from template', () => {
    expect(s.payload).toMatchObject({ from_number_id: 'PN123', template: 'order_update', language: 'en_US' })
    expect(s.payload.variables.body).toEqual(['Ravi', '#1001', 'value_3'])
    expect(s.payload.variables.header).toBe('#1001')
    expect(s.payload.variables.buttons).toEqual(['button_value', null])
  })
  it('puts key in header, never URL, and reads it from env', () => {
    for (const c of [s.curl, s.php, s.node]) { expect(c).toContain('https://h.test/api/v1/templates/send'); expect(c).not.toMatch(/[?&]api_key=/) }
    expect(s.curl).toContain('X-API-Key: $WAB_API_KEY'); expect(s.php).toContain("getenv('WAB_API_KEY')"); expect(s.node).toContain('process.env.WAB_API_KEY')
    expect(s.php).toContain("'template' => 'order_update'")
  })
  it('omits variables a template does not have', () => {
    const p = snippets({ template: { name: 'hello', language: 'en', components: [{ type: 'BODY', text: 'Hello' }] }, base: 'https://h.test' }).payload
    expect(p.variables).toEqual({ body: [] }); expect(p.from_number_id).toBe('<from_number_id>')
  })
})
