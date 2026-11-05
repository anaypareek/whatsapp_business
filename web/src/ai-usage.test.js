import { describe, it, expect } from 'vitest'
import { remaining, costInr } from './ai-usage.jsx'

describe('ai usage helpers', () => {
  it('remaining is credits minus used', () => {
    expect(remaining(10, 2.5)).toBe(7.5)
    expect(remaining(1, 1)).toBe(0)
    expect(remaining(0, 0.01)).toBe(-0.01)
  })
  it('costInr matches gpt-4o-mini defaults at rate 88', () => {
    // 1000 prompt + 500 completion → 0.00045 USD * 88
    expect(costInr({ prompt: 1000, completion: 500 })).toBeCloseTo(0.00045 * 88, 8)
  })
})
