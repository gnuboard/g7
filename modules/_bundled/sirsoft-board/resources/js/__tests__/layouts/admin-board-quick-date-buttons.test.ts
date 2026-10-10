/**
 * @file admin-board-quick-date-buttons.test.ts
 * @description 게시판 관리자 목록(신고·반응·스크랩)의 빠른 기간 버튼 — 키보드 버튼·보는 사람의 달력 날짜
 *
 * 라이브 실측(7.1.0 영역 C 후속, O-C5·O-C2 같은 결함군):
 * - 빠른 기간(`btn-date`) 이 `div` 라 Tab 으로 갈 수 없고 Enter/Space 로 누를 수 없었다.
 * - 「오늘」 을 `new Date().toISOString().slice(0, 10)`(UTC 날짜)으로 만들어, 한국 시간 오전 9시 전에는 어제가 채워졌다.
 *   서버는 날짜 칸을 보는 사람의 시간대 하루로 자르므로 화면도 같은 기준의 달력 날짜를 보낸다.
 * - 7.1.0 R-B(validate q2 R1 같은 결함군): 브라우저 달력 날짜도 계정 시간대와 다를 수 있다(한국 계정을 UTC 브라우저에서
 *   쓰는 오전 0~9시). 「오늘」은 계정 시간대(회원 시간대 → 사이트 기본 시간대)의 날짜로 만든다.
 */

import fs from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';

const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts');
const TARGETS = [
  'admin/admin_board_reports_index.json',
  'admin/partials/admin_board_reactions_index/_partial_filter.json',
  'admin/partials/admin_board_scraps_index/_partial_filter.json',
];

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

describe('게시판 관리자 목록 — 빠른 기간 버튼', () => {
  // @scenario surface=board_admin_quick_date
  // @effects quick_date_buttons_are_keyboard_buttons
  it.each(TARGETS)('%s 의 빠른 기간은 type="button" 인 button 이다', (relative) => {
    const layout = JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, relative), 'utf-8'));
    const nodes = collect(layout, (n) => n.type && typeof n.props?.className === 'string' && /\bbtn-date\b/.test(n.props.className));

    expect(nodes.length).toBe(6);
    for (const node of nodes) {
      expect(node.name).toBe('Button');
      expect(node.props.type).toBe('button');
    }
  });

  // @scenario surface=board_admin_quick_date
  // @effects quick_date_uses_local_calendar_day
  it.each(TARGETS)('%s 의 빠른 기간 식은 UTC 날짜(`toISOString()` 자르기)를 쓰지 않는다', (relative) => {
    const text = fs.readFileSync(path.join(LAYOUT_ROOT, relative), 'utf-8');

    expect(text).not.toMatch(/toISOString\(\)\.(slice\(0, ?10\)|split\('T'\)\[0\])/);
  });
});

/**
 * 빠른 기간 여섯 버튼의 setState 값(dateQuick → dateFrom·dateTo 식)을 모은다.
 */
function quickDateParams(relative: string): Array<{ quick: string; from: string; to: string }> {
  const layout = JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, relative), 'utf-8'));
  return collect(layout, (n) => n.handler === 'setState' && typeof n.params?.dateQuick === 'string' && typeof n.params?.dateFrom === 'string')
    .map((n) => ({ quick: n.params.dateQuick, from: n.params.dateFrom, to: n.params.dateTo }));
}

/**
 * `{{식}}` 하나를 레이아웃 문맥(current_user·_global)으로 평가한다.
 */
function evaluate(binding: string, scope: { current_user: any; _global: any }): string {
  const expr = binding.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('current_user', '_global', `return (${expr});`)(scope.current_user, scope._global);
}

/**
 * 계정 시간대 기준 — 기준 순간 UTC 2026-10-08 05:00 은 한국(이 PC)·UTC 로 10-08, 호놀룰루로 10-07 19:00 이다.
 */
describe('게시판 관리자 목록 — 빠른 기간은 계정 시간대의 날짜', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  const expected: Record<string, string> = {
    today: '2026-10-07',
    '3days': '2026-10-05',
    week: '2026-10-01',
    month: '2026-09-07',
    '3months': '2026-07-07',
  };

  // @scenario surface=board_admin_quick_date
  // @effects quick_date_uses_account_timezone
  it.each(TARGETS)('%s — 회원 시간대(호놀룰루)의 오늘로 채운다', (relative) => {
    const scope = {
      current_user: { data: { timezone: 'Pacific/Honolulu' } },
      _global: { settings: { general: { timezone: 'Asia/Seoul' } } },
    };
    const params = quickDateParams(relative).filter((p) => p.quick in expected);

    expect(params.length).toBe(5);
    for (const p of params) {
      expect(evaluate(p.to, scope)).toBe('2026-10-07');
      expect(evaluate(p.from, scope)).toBe(expected[p.quick]);
    }
  });

  // @scenario surface=board_admin_quick_date
  // @effects quick_date_uses_account_timezone
  it.each(TARGETS)('%s — 회원 시간대가 없으면 사이트 기본 시간대로 채운다', (relative) => {
    const scope = {
      current_user: { data: {} },
      _global: { settings: { general: { timezone: 'Pacific/Honolulu' } } },
    };
    const today = quickDateParams(relative).find((p) => p.quick === 'today')!;

    expect(evaluate(today.from, scope)).toBe('2026-10-07');
    expect(evaluate(today.to, scope)).toBe('2026-10-07');
  });
});
