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

/**
 * 계정 시간대 기준 (7.1.0 전수 실측 validate q2 R1 · 원칙 「유저가 보는 화면은 유저의 시간대」).
 *
 * 빠른 기간 버튼이 브라우저 시간대로 「오늘」을 만들면, 서버가 그 날짜를 계정 시간대의 하루로 해석하므로
 * 브라우저와 계정의 날짜가 다른 시간에는 「오늘」이 하루 어긋난다. 기준 순간 UTC 2026-10-08 05:00 은
 * 한국(이 PC)·UTC 로는 10-08, 호놀룰루(UTC-10)로는 10-07 19:00 이다 — 계정 시간대를 호놀룰루로 둬 갈라 본다.
 */
describe('setDateRangeHandler — 계정 시간대', () => {
  const originalG7Core = (window as any).G7Core;

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
  });

  afterEach(() => {
    vi.useRealTimers();
    (window as any).G7Core = originalG7Core;
  });

  /**
   * 로그인 사용자·사이트 기본 시간대를 흉내 낸다.
   */
  function mockTimezones(userTimezone: string | null, siteTimezone: string | null): void {
    (window as any).G7Core = {
      AuthManager: { getInstance: () => ({ getUser: () => (userTimezone ? { timezone: userTimezone } : null) }) },
      state: { get: () => ({ settings: { general: { timezone: siteTimezone } } }) },
    };
  }

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_account_timezone
  it('회원 시간대가 있으면 그 시간대의 오늘로 계산한다', () => {
    mockTimezones('Pacific/Honolulu', 'Asia/Seoul');

    expect(setDateRangeHandler({ params: { preset: 'today', format: 'date' } })).toEqual({
      preset: 'today',
      startDate: '2026-10-07',
      endDate: '2026-10-07',
    });
    expect(setDateRangeHandler({ params: { preset: 'week' } })).toEqual({
      preset: 'week',
      startDate: '2026-10-01T00:00:00',
      endDate: '2026-10-07T23:59:59',
    });
  });

  // @scenario filter_axis=created_at, outcome=narrows
  // @effects date_quick_uses_account_timezone
  it('회원 시간대가 없으면 사이트 기본 시간대로 계산한다', () => {
    mockTimezones(null, 'Pacific/Honolulu');

    const result = setDateRangeHandler({ params: { preset: 'month', format: 'date' } });
    expect(result.startDate).toBe('2026-09-07');
    expect(result.endDate).toBe('2026-10-07');
  });
});
