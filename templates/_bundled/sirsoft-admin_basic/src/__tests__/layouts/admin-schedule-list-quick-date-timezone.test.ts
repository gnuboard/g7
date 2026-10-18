/**
 * @file admin-schedule-list-quick-date-timezone.test.ts
 * @description 스케줄 목록 빠른 기간 버튼 — 「오늘」은 계정 시간대의 날짜 (7.1.0 validate q2 R1)
 *
 * 서버는 등록일 필터(created_from·created_to)를 보는 사람(계정)의 시간대 하루로 자른다. 버튼이
 * `toLocaleDateString('sv-SE')`(브라우저 시간대)로 날짜를 만들면, 계정과 브라우저의 날짜가 다른 시간(한국 계정을
 * UTC 브라우저에서 쓰는 오전 0~9시 등)에 「오늘」이 하루 어긋난다. 회원 시간대 → 사이트 기본 시간대 순으로 쓴다.
 *
 * 기준 순간 UTC 2026-10-08 05:00 = 한국(이 PC)·UTC 10-08, 호놀룰루 10-07 19:00.
 */

import fs from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const LAYOUT = path.resolve(__dirname, '../../../layouts/partials/admin_schedule_list/_tab_schedules.json');

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

/** 빠른 기간 버튼 여섯의 navigate query(created_from·created_to 식)를 버튼 순서대로 모은다. */
function presetQueries(): Array<{ from: string; to: string }> {
  const layout = JSON.parse(fs.readFileSync(LAYOUT, 'utf-8'));
  return collect(
    layout,
    (n) => n.handler === 'navigate' && typeof n.params?.query?.created_from === 'string' && typeof n.params?.query?.created_to === 'string'
      && n.params.query.created_from.includes('toLocaleDateString'),
  ).map((n) => ({ from: n.params.query.created_from, to: n.params.query.created_to }));
}

/** `{{식}}` 하나를 레이아웃 문맥(current_user·_global)으로 평가한다. */
function evaluate(binding: string, scope: { current_user: any; _global: any }): string {
  const expr = binding.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('current_user', '_global', `return (${expr});`)(scope.current_user, scope._global);
}

describe('스케줄 목록 빠른 기간 — 계정 시간대의 날짜', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  // @scenario surface=admin_schedules
  // @effects schedule_quick_dates_use_account_timezone
  it('회원 시간대(호놀룰루)의 오늘을 끝 날짜로, N일 전을 시작 날짜로 채운다', () => {
    const scope = {
      current_user: { data: { timezone: 'Pacific/Honolulu' } },
      _global: { settings: { general: { timezone: 'Asia/Seoul' } } },
    };
    const queries = presetQueries();

    expect(queries.length).toBe(6);
    // 오늘 · 7일 · 30일 · 90일 · 180일 · 365일 (버튼 순서)
    expect(queries.map((q) => evaluate(q.from, scope))).toEqual([
      '2026-10-07',
      '2026-09-30',
      '2026-09-07',
      '2026-07-09',
      '2026-04-10',
      '2025-10-07',
    ]);
    for (const q of queries) {
      expect(evaluate(q.to, scope)).toBe('2026-10-07');
    }
  });

  // @scenario surface=admin_schedules
  // @effects schedule_quick_dates_use_account_timezone
  it('회원 시간대가 없으면 사이트 기본 시간대로 채운다', () => {
    const scope = {
      current_user: { data: {} },
      _global: { settings: { general: { timezone: 'Pacific/Honolulu' } } },
    };
    const [today] = presetQueries();

    expect(evaluate(today.from, scope)).toBe('2026-10-07');
    expect(evaluate(today.to, scope)).toBe('2026-10-07');
  });
});
