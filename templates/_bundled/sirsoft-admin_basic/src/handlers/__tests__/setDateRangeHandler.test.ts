/**
 * setDateRangeHandler 테스트
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setDateRangeHandler } from '../setDateRangeHandler';

describe('setDateRangeHandler', () => {
  beforeEach(() => {
    // 2026-03-28 14:30:00 고정
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 2, 28, 14, 30, 0));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('today 프리셋: 오늘 00:00:00 ~ 23:59:59 반환', () => {
    const result = setDateRangeHandler({ params: { preset: 'today' } });

    expect(result.preset).toBe('today');
    expect(result.startDate).toBe('2026-03-28T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('week 프리셋: 7일 전 00:00:00 ~ 오늘 23:59:59 반환', () => {
    const result = setDateRangeHandler({ params: { preset: 'week' } });

    expect(result.preset).toBe('week');
    expect(result.startDate).toBe('2026-03-22T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('month 프리셋: 1개월 전 00:00:00 ~ 오늘 23:59:59 반환', () => {
    const result = setDateRangeHandler({ params: { preset: 'month' } });

    expect(result.preset).toBe('month');
    expect(result.startDate).toBe('2026-02-28T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('3months 프리셋: 3개월 전 반환', () => {
    const result = setDateRangeHandler({ params: { preset: '3months' } });

    expect(result.preset).toBe('3months');
    expect(result.startDate).toBe('2025-12-28T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('6months 프리셋: 6개월 전 반환', () => {
    const result = setDateRangeHandler({ params: { preset: '6months' } });

    expect(result.preset).toBe('6months');
    expect(result.startDate).toBe('2025-09-28T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('1year 프리셋: 1년 전 반환', () => {
    const result = setDateRangeHandler({ params: { preset: '1year' } });

    expect(result.preset).toBe('1year');
    expect(result.startDate).toBe('2025-03-28T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('params 없으면 today 기본값 사용', () => {
    const result = setDateRangeHandler({});

    expect(result.preset).toBe('today');
    expect(result.startDate).toBe('2026-03-28T00:00:00');
    expect(result.endDate).toBe('2026-03-28T23:59:59');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it('datetime-local 형식(YYYY-MM-DDTHH:mm:ss) 반환', () => {
    const result = setDateRangeHandler({ params: { preset: 'today' } });

    // YYYY-MM-DDTHH:mm:ss 형식 검증
    const dateTimeLocalRegex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/;
    expect(result.startDate).toMatch(dateTimeLocalRegex);
    expect(result.endDate).toMatch(dateTimeLocalRegex);
  });

  // format 인자 (#705) — 날짜 칸(<input type="date">)은 datetime 값을 받지 못해 빈칸이 된다.
  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it("format='date' 면 YYYY-MM-DD 만 반환 (3months)", () => {
    const result = setDateRangeHandler({ params: { preset: '3months', format: 'date' } });

    expect(result.preset).toBe('3months');
    expect(result.startDate).toBe('2025-12-28');
    expect(result.endDate).toBe('2026-03-28');
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it("format='date' 는 여섯 프리셋 모두 날짜 형식", () => {
    for (const preset of ['today', 'week', 'month', '3months', '6months', '1year'] as const) {
      const result = setDateRangeHandler({ params: { preset, format: 'date' } });
      expect(result.startDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(result.endDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    }
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_date_format
  it("format 미지정·'datetime'·알 수 없는 값은 기존 datetime 형식을 유지 (회귀)", () => {
    expect(setDateRangeHandler({ params: { preset: 'week' } }).startDate).toBe('2026-03-22T00:00:00');
    expect(setDateRangeHandler({ params: { preset: 'week', format: 'datetime' } }).startDate).toBe('2026-03-22T00:00:00');
    expect(setDateRangeHandler({ params: { preset: 'week', format: 'bogus' as any } }).endDate).toBe('2026-03-28T23:59:59');
  });
});
