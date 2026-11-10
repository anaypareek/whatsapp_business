import { describe, it, expect } from 'vitest'
import { authPreview, toForm, requestToForm, blankOtp } from './templates.jsx'

describe('authentication template form helpers', () => {
  it('builds Meta fixed preview text', () => {
    expect(authPreview({ add_security_recommendation: true, code_expiration_minutes: 10 })).toEqual({
      body: '{{1}} is your verification code. For your security, do not share this code.',
      footer: 'This code expires in 10 minutes.',
    })
    expect(authPreview({}).body).toBe('{{1}} is your verification code.')
    expect(authPreview({}).footer).toBe('')
  })

  it('maps stored auth components without exposing custom body text', () => {
    const f = toForm({
      name: 'otp', language: 'en_US', category: 'AUTHENTICATION',
      components: [
        { type: 'BODY', add_security_recommendation: true, text: 'should not become editable' },
        { type: 'FOOTER', code_expiration_minutes: 10, text: 'This code expires in 10 minutes.' },
        { type: 'BUTTONS', buttons: [{ type: 'OTP', otp_type: 'COPY_CODE', text: 'Copy Code' }] },
      ],
    })
    expect(f.category).toBe('AUTHENTICATION')
    expect(f.header.format).toBe('NONE')
    expect(f.body.text).toBe('')
    expect(f.body.add_security_recommendation).toBe(true)
    expect(f.code_expiration_minutes).toBe('10')
    expect(f.buttons[0]).toMatchObject({ type: 'OTP', otp_type: 'COPY_CODE', text: 'Copy Code' })
  })

  it('requestToForm keeps one-tap apps', () => {
    const f = requestToForm({
      name: 'otp', language: 'en_US', category: 'AUTHENTICATION',
      form: { body: { add_security_recommendation: true }, buttons: [{ type: 'OTP', otp_type: 'ONE_TAP', supported_apps: [{ package_name: 'com.example.app', signature_hash: 'K8a/AINcGX7' }] }] },
      components: [{ type: 'BODY', add_security_recommendation: true }, { type: 'BUTTONS', buttons: [{ type: 'OTP', otp_type: 'ONE_TAP', supported_apps: [{ package_name: 'com.example.app', signature_hash: 'K8a/AINcGX7' }] }] }],
    })
    expect(f.buttons[0].otp_type).toBe('ONE_TAP')
    expect(f.buttons[0].supported_apps[0].package_name).toBe('com.example.app')
    expect(blankOtp().otp_type).toBe('COPY_CODE')
  })
})
