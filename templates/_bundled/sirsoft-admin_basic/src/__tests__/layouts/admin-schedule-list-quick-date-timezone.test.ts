/**
 * @file admin-schedule-list-quick-date-timezone.test.ts
 * @description 스케줄 목록 빠른 기간 버튼 — 「오늘」은 계정 시간대의 날짜 (7.1.0 전수 실측)
 *
 * 서버는 등록일 필터(created_start_date·created_end_date)를 보는 사람(계정)의 시간대 하루로 자른다. 버튼이
 * `toLocaleDateString('sv-SE')`(브라우저 시간대)로 날짜를 만들면, 계정과 브라우저의 날짜가 다른 시간(한국 계정을
 * UTC 브라우저에서 쓰는 오전 0~9시 등)에 「오늘」이 하루 어긋난다. 회원 시간대 → 사이트 기본 시간대 순으로 쓴다.
 *
 * 7.2.0 에서 필터가 쿠폰·회원 목록형으로 바뀌면서 빠른 기간은 레이아웃 안 인라인 식이 아니라 템플릿 핸들러
 * `setDateRange`(계정 시간대 해석)를 부르고 그 결과(`$prev`)를 필터 입력값에 담는다. 이 파일은 ① 레이아웃이
 * 그 핸들러만 쓰고 브라우저 시간대 식이 남지 않았는지 ② 레이아웃이 넘기는 프리셋 그대로 핸들러가 계정 시간대의
 * 날짜를 내는지를 함께 고정한다.
 *
 * 기준 순간 UTC 2026-10-08 05:00 = 한국(이 PC)·UTC 10-08, 호놀룰루 10-07 19:00.
 */

import fs from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setDateRangeHandler } from '../../handlers/setDateRangeHandler';

const LAYOUT = path.resolve(__dirname, '../../../layouts/partials/admin_schedule_list/_partial_filter_section.json');

/** 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    if (match(node)) found.push(node);
    Object.values(node).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

/** 등록일 빠른 기간 버튼 여섯이 넘기는 setDateRange 프리셋을 버튼 순서대로 모은다. */
function presetCalls(layout: any): Array<{ preset: string; format: string }> {
  return collect(layout, (n) => n.handler === 'setDateRange').map((n) => ({
    preset: n.params?.preset ?? n.preset,
    format: n.params?.format ?? n.format,
  }));
}

/** 로그인 사용자·사이트 기본 시간대를 흉내 낸다. */
function mockTimezones(userTimezone: string | null, siteTimezone: string | null): void {
  (window as any).G7Core = {
    AuthManager: { getInstance: () => ({ getUser: () => (userTimezone ? { timezone: userTimezone } : null) }) },
    state: { get: () => ({ settings: { general: { timezone: siteTimezone } } }) },
  };
}

describe('스케줄 목록 빠른 기간 — 계정 시간대의 날짜', () => {
  const layout = JSON.parse(fs.readFileSync(LAYOUT, 'utf-8'));
  const originalG7Core = (window as any).G7Core;

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
  });

  afterEach(() => {
    vi.useRealTimers();
    (window as any).G7Core = originalG7Core;
  });

  // @scenario surface=admin_schedules
  // @effects schedule_quick_dates_use_account_timezone
  it('빠른 기간 여섯은 setDateRange(날짜 형식)만 부르고 브라우저 시간대 식을 쓰지 않는다', () => {
    expect(presetCalls(layout)).toEqual([
      { preset: 'today', format: 'date' },
      { preset: 'week', format: 'date' },
      { preset: 'month', format: 'date' },
      { preset: '3months', format: 'date' },
      { preset: '6months', format: 'date' },
      { preset: '1year', format: 'date' },
    ]);
    // 결과는 $prev 로 필터 입력값(시작·종료)에 담긴다 — 인라인 new Date()/toLocaleDateString 이 남으면 브라우저 시간대다
    const raw = JSON.stringify(layout);
    expect(raw).not.toContain('toLocaleDateString');
    expect(raw).not.toContain('new Date(');
    expect(raw).toContain('"filter.createdStartDate":"{{$prev.startDate}}"');
    expect(raw).toContain('"filter.createdEndDate":"{{$prev.endDate}}"');
  });

  // @scenario surface=admin_schedules
  // @effects schedule_quick_dates_use_account_timezone
  it('회원 시간대(호놀룰루)의 오늘을 끝 날짜로 채운다', () => {
    mockTimezones('Pacific/Honolulu', 'Asia/Seoul');

    const results = presetCalls(layout).map((c) => setDateRangeHandler({ params: c } as any));

    for (const r of results) {
      expect(r.endDate).toBe('2026-10-07');
    }
    expect(results[0].startDate).toBe('2026-10-07');
  });

  // @scenario surface=admin_schedules
  // @effects schedule_quick_dates_use_account_timezone
  it('회원 시간대가 없으면 사이트 기본 시간대로 채운다', () => {
    mockTimezones(null, 'Pacific/Honolulu');

    const results = presetCalls(layout).map((c) => setDateRangeHandler({ params: c } as any));

    for (const r of results) {
      expect(r.endDate).toBe('2026-10-07');
    }
  });
});
