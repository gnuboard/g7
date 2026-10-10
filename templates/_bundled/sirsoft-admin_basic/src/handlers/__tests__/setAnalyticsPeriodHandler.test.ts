
/**
 * setAnalyticsPeriodHandler 테스트 — 프리셋 8종이 서버(`PeriodRequest::resolvedRange`)와
 * 같은 날짜 구간을 내는지, 월말·윤년 경계에서 하루가 밀리지 않는지 잠근다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  ANALYTICS_PERIOD_PRESETS,
  isAnalyticsPeriodPreset,
  resolveAnalyticsPeriod,
  setAnalyticsPeriodHandler,
} from '../setAnalyticsPeriodHandler';

describe('setAnalyticsPeriodHandler', () => {
  beforeEach(() => {
    // 2026-03-31 14:30:00 (월말) 고정
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 2, 31, 14, 30, 0));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_match_server_rules
  it('today: 오늘 하루', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: 'today' } })).toEqual({
      preset: 'today',
      from: '2026-03-31',
      to: '2026-03-31',
    });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_match_server_rules
  it('yesterday: 어제 하루', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: 'yesterday' } })).toEqual({
      preset: 'yesterday',
      from: '2026-03-30',
      to: '2026-03-30',
    });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_match_server_rules
  it('7d·30d·90d: 오늘을 포함한 N일 (서버 subDays(N-1) 과 동일)', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: '7d' } })).toMatchObject({ from: '2026-03-25', to: '2026-03-31' });
    expect(setAnalyticsPeriodHandler({ params: { preset: '30d' } })).toMatchObject({ from: '2026-03-02', to: '2026-03-31' });
    expect(setAnalyticsPeriodHandler({ params: { preset: '90d' } })).toMatchObject({ from: '2026-01-01', to: '2026-03-31' });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_match_server_rules
  it('this_month: 이달 1일 ~ 오늘', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: 'this_month' } })).toMatchObject({ from: '2026-03-01', to: '2026-03-31' });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_last_month_never_overflows
  it('last_month: 31일에서도 지난달(2월) 1일 ~ 말일로 넘치지 않는다', () => {
    // Date.setMonth(-1) 을 31일에 부르면 3월 3일로 되돌아오는 함정 — 서버의 subMonthNoOverflow 와 같아야 한다.
    expect(setAnalyticsPeriodHandler({ params: { preset: 'last_month' } })).toMatchObject({ from: '2026-02-01', to: '2026-02-28' });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_match_server_rules
  it('this_year: 1월 1일 ~ 오늘', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: 'this_year' } })).toMatchObject({ from: '2026-01-01', to: '2026-03-31' });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_handle_leap_years
  it('윤년: 2028-03-01 의 어제는 2월 29일, 지난달 말일도 29일', () => {
    const now = new Date(2028, 2, 1, 9, 0, 0);
    expect(resolveAnalyticsPeriod('yesterday', now)).toEqual({ from: '2028-02-29', to: '2028-02-29' });
    expect(resolveAnalyticsPeriod('last_month', now)).toEqual({ from: '2028-02-01', to: '2028-02-29' });
    expect(resolveAnalyticsPeriod('7d', now)).toEqual({ from: '2028-02-24', to: '2028-03-01' });
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_last_month_never_overflows
  it('연초: 1월 15일의 last_month 는 지난해 12월 전체', () => {
    const now = new Date(2026, 0, 15, 9, 0, 0);
    expect(resolveAnalyticsPeriod('last_month', now)).toEqual({ from: '2025-12-01', to: '2025-12-31' });
    expect(resolveAnalyticsPeriod('90d', now)).toEqual({ from: '2025-10-18', to: '2026-01-15' });
  });

  // @scenario screen=component, state=empty, viewport=1440, locale=ko
  // @effects period_unknown_preset_falls_back_to_7d
  it('모르는 프리셋·누락은 기본 7d 로 떨어진다', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: 'bogus' } }).preset).toBe('7d');
    expect(setAnalyticsPeriodHandler().preset).toBe('7d');
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_preset_never_returns_datetime
  it('반환값은 날짜만 담는다 (datetime-local 이면 서버가 422 로 거절한다)', () => {
    for (const preset of ANALYTICS_PERIOD_PRESETS) {
      const { from, to } = setAnalyticsPeriodHandler({ params: { preset } });
      expect(from).toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(to).toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(from <= to).toBe(true);
    }
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_match_server_rules
  it('isAnalyticsPeriodPreset 은 서버 허용값 8종만 통과시킨다', () => {
    expect(ANALYTICS_PERIOD_PRESETS).toHaveLength(8);
    expect(isAnalyticsPeriodPreset('this_month')).toBe(true);
    expect(isAnalyticsPeriodPreset('week')).toBe(false);
    expect(isAnalyticsPeriodPreset(undefined)).toBe(false);
  });
});

/**
 * 사이트 표준시 기준 — 통계 서버는 프리셋을 사이트 표준시의 오늘로 푼다(AnalyticsClock). 첫 응답 전 달력 두 칸·
 * CSV 이름도 같은 날짜여야 한다. 기준 순간 UTC 2026-10-08 05:00 = 한국(이 PC) 10-08, 호놀룰루 10-07.
 */
describe('setAnalyticsPeriodHandler — 사이트 표준시', () => {
  const originalG7Core = (window as any).G7Core;

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
    (window as any).G7Core = {
      AuthManager: { getInstance: () => ({ getUser: () => ({ timezone: 'Asia/Seoul' }) }) },
      state: { get: () => ({ settings: { general: { timezone: 'Pacific/Honolulu' } } }) },
    };
  });

  afterEach(() => {
    vi.useRealTimers();
    (window as any).G7Core = originalG7Core;
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects period_presets_use_site_timezone
  it('보는 사람·브라우저가 아니라 사이트 표준시의 오늘로 계산한다', () => {
    expect(setAnalyticsPeriodHandler({ params: { preset: 'today' } })).toEqual({ preset: 'today', from: '2026-10-07', to: '2026-10-07' });
    expect(setAnalyticsPeriodHandler({ params: { preset: 'yesterday' } })).toEqual({ preset: 'yesterday', from: '2026-10-06', to: '2026-10-06' });
    expect(setAnalyticsPeriodHandler({ params: { preset: 'last_month' } })).toEqual({ preset: 'last_month', from: '2026-09-01', to: '2026-09-30' });
  });
});
