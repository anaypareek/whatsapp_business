import { describe, it, expect } from 'vitest'
import { replyProblem } from './button-replies'

describe('replyProblem', () => {
  const tpl = [{ id: 5, body_vars: 1, header_needs: null }]
  it('off rows are fine', () => expect(replyProblem({ on: false }, tpl)).toBeNull())
  it('text needs text', () => { expect(replyProblem({ on: true, reply_type: 'text', text: ' ' }, tpl)).toMatch(/reply text/); expect(replyProblem({ on: true, reply_type: 'text', text: 'Hi' }, tpl)).toBeNull() })
  it('media link must match type', () => {
    expect(replyProblem({ on: true, reply_type: 'media', media_type: 'image', media_url: 'https://x.y/a.pdf' }, tpl)).toMatch(/\.jpg/)
    expect(replyProblem({ on: true, reply_type: 'media', media_type: 'document', media_url: 'https://x.y/a.pdf' }, tpl)).toBeNull()
    expect(replyProblem({ on: true, reply_type: 'media', media_type: 'video', media_url: 'http://x.y/a.mp4' }, tpl)).toMatch(/https/)
  })
  it('template needs mapping', () => {
    expect(replyProblem({ on: true, reply_type: 'template', reply_template_id: null }, tpl)).toMatch(/Choose/)
    expect(replyProblem({ on: true, reply_type: 'template', reply_template_id: 5, template_params: { body: [{ type: 'name', fallback: 'Customer' }] } }, tpl)).toBeNull()
  })
})
