import { describe, it, expect } from 'vitest'
import { inr, lineTotal, monthStart, CATS } from './billing.jsx'

describe('billing helpers', () => {
  it('formats INR with Indian grouping', () => { expect(inr(123456.5)).toBe('₹1,23,456.50'); expect(inr(0)).toBe('₹0.00') })
  it('sums line amounts like the API (per-line rounding)', () => {
    expect(lineTotal([{ count: 3, rate: 1 }, { count: 2, rate: 0.2 }, { count: 1, rate: 0.15 }, { count: 1, rate: 0 }])).toBe(3.55)
    expect(lineTotal([{ count: 1000, rate: 0.7846 }])).toBe(784.6)
  })
  it('defaults the period to the first of this month', () => { expect(monthStart(new Date(2026, 9, 29))).toBe('2026-10-01') })
  it('has the four categories with service', () => { expect(CATS.map((c) => c[0])).toEqual(['marketing', 'utility', 'authentication', 'service']) })
})
