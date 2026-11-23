/**
 * @file mypage-activity.test.tsx
 * @description 마이페이지 활동 기록 (#952 E) — 코어 활동 기록 API(쪽·기간), 표·카드 두 모양, 빈 목록, 쪽 나누기.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const layoutsDir = path.resolve(__dirname, '../../layouts');
const load = (relative: string): any => JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

const evaluate = (expression: string, context: Record<string, unknown>) =>
  dataBindingEngine.evaluateExpression(String(expression).replace(/^\{\{/, '').replace(/\}\}$/, ''), context as any);
const refOf = (n: any) => String(n?.props?.['data-ref'] ?? n?.id ?? '');

const page = load('mypage/activity.json');
const list = load('partials/mypage/activity/_list.json');

describe('마이페이지 활동 기록 (#952)', () => {
  // @scenario case=mypage_activity_data_source
  // @effects user_activity_log_paginated
  it('코어 활동 기록 API 를 쪽 번호와 기간 조건으로 부른다', () => {
    const source = collect(page, (n) => n.type === 'api' && n.id === 'myActivity')[0];
    expect(source.endpoint).toBe('/api/user/profile/activity-log');
    const text = JSON.stringify(source);
    expect(text).toContain('query.page');
    expect(text).toMatch(/date_from|date_to/);
  });

  // @scenario case=mypage_activity_rows
  // @effects mypage_activity_rows
  it('기록이 있으면 표(넓은 화면)와 카드(좁은 화면)를 같은 목록으로 그리고, 없으면 빈 안내를 보인다', () => {
    const eaches = collect(list, (n) => n.iteration?.item_var === 'log');
    expect(eaches.length).toBe(2);
    eaches.forEach((n) => expect(n.iteration.source).toContain('myActivity?.data?.activities'));

    const table = collect(list, (n) => refOf(n) === 'activity_table')[0];
    const empty = collect(list, (n) => refOf(n) === 'activity_empty')[0];
    const rows = { myActivity: { data: { activities: [{ id: 1 }] } } };
    const none = { myActivity: { data: { activities: [] } } };
    expect(evaluate(table.if, rows)).toBe(true);
    expect(evaluate(empty.if, none)).toBe(true);
    expect(evaluate(empty.if, { myActivity: undefined })).toBe(false);
  });

  // @scenario case=mypage_activity_paging
  // @effects user_activity_log_paginated
  it('쪽이 하나면 쪽 나누기를 숨기고, 다음 쪽이 있으면 보인다', () => {
    const pager = collect(list, (n) => n.name === 'Pagination')[0];
    expect(evaluate(pager.if, { myActivity: { data: { pagination: { last_page: 1, current_page: 1, has_more_pages: false } } } })).toBe(false);
    expect(evaluate(pager.if, { myActivity: { data: { pagination: { last_page: 2, current_page: 1, has_more_pages: true } } } })).toBe(true);
  });
});
