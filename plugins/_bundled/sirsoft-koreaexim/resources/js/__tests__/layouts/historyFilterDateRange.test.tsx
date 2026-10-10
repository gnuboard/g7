/**
 * @file historyFilterDateRange.test.tsx
 * @description 실행 이력 필터 — 종료일이 시작일보다 앞서는 기간
 *
 * 서버는 date_to < date_from 을 422 로 거절하는데, 화면은 그 실패를 빈 목록으로만 그려
 * 운영자는 「기록이 없다」로 읽었다. 두 날짜 입력이 서로의 범위를 제한하고, 그래도 뒤집힌
 * 기간이 쿼리에 실려 있으면(직접 입력·주소 공유) 이유를 필터 아래에 보여 준다.
 *
 * 뒤집힌 기간은 조회하지 않는다 — 요청을 보내면 422 토스트가 필드 원문 이름으로 뜨고, 그리드에는
 * 직전 기간의 결과가 그대로 남아 새 기간의 결과처럼 보였다(#703 브라우저 실측).
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import filters from '../../../layouts/admin/partials/plugin_settings/_history_filter.json';
import ko from '../../../lang/ko.json';
import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import grid from '../../../layouts/admin/partials/plugin_settings/_history_grid.json';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

let utils: ReturnType<typeof createLayoutTest> | null = null;

beforeEach(() => {
  const registry: any = ComponentRegistry.getInstance();
  registry.registry = {};
  for (const [name, tag] of Object.entries({ Div: 'div', P: 'p', Label: 'label', Span: 'span', Input: 'input', Select: 'select', Option: 'option' })) {
    registry.registry[name] = {
      component: ({ text, children, ...props }: any) => React.createElement(tag, props, tag === 'input' ? undefined : children || text),
      metadata: { name, type: 'basic' },
    };
  }
  registry.registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
});

afterEach(() => {
  utils?.cleanup();
  utils = null;
  (ComponentRegistry.getInstance() as any).registry = {};
});

/**
 * 주어진 쿼리로 필터를 렌더한다.
 *
 * @param query 현재 주소의 쿼리
 */
async function renderFilter(query: Record<string, string>) {
  utils = createLayoutTest({ version: '1.0.0', layout_name: 'rate_filter_range', components: [filters] } as any, {
    translations: { 'sirsoft-koreaexim': ko },
    locale: 'ko',
    queryParams: query,
    initialData: { query },
  });
  const { container } = await utils.render();
  const [from, to] = [...container.querySelectorAll('input[type="date"]')] as HTMLInputElement[];
  return { container, from, to };
}

describe('실행 이력 기간 필터', () => {
  /** @scenario flow=filter
   * @effects invalid_date_range_explained */
  it('시작일·종료일이 서로의 선택 범위를 제한한다', async () => {
    const { from, to } = await renderFilter({ tab: 'history', date_from: '2026-09-10', date_to: '2026-09-20' });
    expect(from.getAttribute('max')).toBe('2026-09-20');
    expect(to.getAttribute('min')).toBe('2026-09-10');
  });

  /** @scenario flow=filter
   * @effects invalid_date_range_explained */
  it('종료일이 시작일보다 앞서면 이유를 필터 아래에 보여 준다', async () => {
    const { container } = await renderFilter({ tab: 'history', date_from: '2026-09-20', date_to: '2026-09-10' });
    const alert = container.querySelector('[role="alert"]');
    expect((ko as Record<string, unknown>).date_range_invalid).toEqual(expect.any(String));
    expect(alert?.textContent).toBe((ko as Record<string, unknown>).date_range_invalid);
  });

  /** @scenario flow=filter
   * @effects invalid_date_range_explained */
  it('기간이 올바르거나 한쪽만 있으면 안내를 띄우지 않는다', async () => {
    for (const query of [
      { tab: 'history', date_from: '2026-09-10', date_to: '2026-09-10' },
      { tab: 'history', date_from: '2026-09-10' },
      { tab: 'history' },
    ]) {
      const { container } = await renderFilter(query);
      expect(container.querySelector('[role="alert"]')).toBeNull();
      utils?.cleanup();
      utils = null;
    }
  });
});

describe('실행 이력 — 뒤집힌 기간은 조회하지 않는다', () => {
  const engine = new DataBindingEngine();

  /** 데이터소스 조건·그리드 데이터 식을 주어진 쿼리로 실제 엔진에서 평가한다. */
  function evaluate(expr: string, query: Record<string, string>, runs: unknown[] = [{ id: 1 }]) {
    const body = expr.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
    return engine.evaluateExpression(body, { query, koreaeximRuns: { data: { data: runs } } } as any, { skipCache: true });
  }

  const runsSource = ((settingsLayout as any).data_sources as any[]).find(source => source.id === 'koreaeximRuns');
  const gridData = String((grid as any).props?.data ?? (grid as any).children?.[0]?.props?.data);

  /** @scenario flow=filter
   * @effects invalid_date_range_explained */
  it('종료일이 시작일보다 앞서면 데이터소스를 부르지 않고, 올바른 기간·한쪽만 있을 때는 부른다', () => {
    expect(runsSource?.if).toEqual(expect.any(String));
    expect(evaluate(runsSource.if, { tab: 'history', date_from: '2026-09-15', date_to: '2026-09-10' })).toBe(false);
    expect(evaluate(runsSource.if, { tab: 'history', date_from: '2026-09-10', date_to: '2026-09-10' })).toBe(true);
    expect(evaluate(runsSource.if, { tab: 'history', date_from: '2026-09-10' })).toBe(true);
    expect(evaluate(runsSource.if, { tab: 'settings' })).toBe(false);
  });

  /** @scenario flow=filter
   * @effects invalid_date_range_explained */
  it('뒤집힌 기간이면 그리드가 직전 조회 결과를 그리지 않는다', () => {
    expect(gridData).toContain('koreaeximRuns');
    expect(evaluate(gridData, { tab: 'history', date_from: '2026-09-15', date_to: '2026-09-10' })).toEqual([]);
    expect(evaluate(gridData, { tab: 'history', date_from: '2026-09-10', date_to: '2026-09-20' })).toEqual([{ id: 1 }]);
  });
});
