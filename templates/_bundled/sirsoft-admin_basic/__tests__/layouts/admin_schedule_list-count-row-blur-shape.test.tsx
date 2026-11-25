/**
 * @file admin_schedule_list-count-row-blur-shape.test.tsx
 * @description 일정 목록 건수 행 — 흐림을 건수 두 갈래를 감싸는 노드에 두어 받기 전·뒤, 두 갈래 모두 행의 자식이 「건수·옵션」 둘이다
 *
 * 흐림(`blur_until_loaded`)은 대상을 `<div>` 래퍼로 감싸 그린다. 종전에는 건수 두 갈래(끝까지 셈 / 상한에 걸림)가 각자 흐림을
 * 가져 갈래마다 래퍼가 `flex-between` 행의 자식으로 들어갔다(engine-v1.99.2 전에는 거짓 갈래의 빈 래퍼까지 남아 행 자식이 셋).
 * 두 갈래를 감싸는 노드 하나에 흐림을 두면 어느 상태든 행 자식은 래퍼 하나 + 옵션 묶음이다. 실제 레이아웃 JSON 에서 행을 꺼내 그린다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const datagrid = require('../../layouts/partials/admin_schedule_list/_partial_schedule_datagrid.json');

/** id 로 노드를 찾는다 */
function findById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const hit = findById(n, id);
      if (hit) return hit;
    }
    return null;
  }
  if (node.id === id) return node;
  for (const v of Object.values(node)) {
    const hit = findById(v, id);
    if (hit) return hit;
  }
  return null;
}

describe('일정 목록 건수 행의 흐림 래퍼 모양', () => {
  let current: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    current?.cleanup();
    current = null;
  });

  /** 실제 행 노드 — 건수 묶음은 그대로, 옵션 묶음(선택 상자·단추)은 같은 id 의 빈 Div 로 둔다 */
  const rowDef = () => {
    const bar = findById(datagrid, 'table_header_bar');
    return {
      ...bar,
      props: { ...bar.props, 'data-testid': 'row' },
      children: bar.children.map((c: any) => (c.id === 'total_count_group' ? c : { id: c.id, type: 'basic', name: 'Div', props: { 'data-testid': c.id } })),
    };
  };

  const mount = async (initialData: Record<string, any> | undefined) => {
    const Div: React.FC<any> = ({ children, text, ...rest }) => <div {...rest}>{children ?? text}</div>;
    const Span: React.FC<any> = ({ children, text, ...rest }) => <span {...rest}>{children ?? text}</span>;
    const Fragment: React.FC<any> = ({ children }) => <>{children}</>;
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: Div, metadata: { name: 'Div', type: 'basic' } },
      Span: { component: Span, metadata: { name: 'Span', type: 'basic' } },
      Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
    current = createLayoutTest(
      { version: '1.0.0', layout_name: 'schedule_count_row_test', components: [rowDef()] } as any,
      { componentRegistry: registry, initialData },
    );
    await current.render();
  };

  const page = (total_is_exact: boolean) => ({ schedules: { data: { data: [], pagination: { total: 1234, total_is_exact } } } });

  /** 행 자식이 건수 래퍼·옵션 둘이고, 래퍼 안에 건수 갈래가 하나만 그려졌는지 본다 */
  const expectTwoChildRow = (blurred: boolean) => {
    const row = screen.getByTestId('row');
    expect(row.children).toHaveLength(2);
    const wrapper = row.firstElementChild as HTMLElement;
    expect(wrapper.tagName).toBe('DIV');
    expect(wrapper.classList.contains('blur-sm')).toBe(blurred);
    expect(wrapper.querySelectorAll('span')).toHaveLength(1);
    expect(row.lastElementChild?.getAttribute('data-testid')).toBe('list_options');
  };

  /**
   * @scenario load_state=loading
   * @effects blur_wraps_branches_not_row_branch
   */
  it('흐림은 건수 두 갈래가 아니라 그 둘을 감싸는 노드 하나에 있다', () => {
    const group = findById(datagrid, 'total_count_group');
    expect(group.blur_until_loaded).toEqual({ enabled: true, data_sources: 'schedules' });
    expect(group.if).toBeUndefined();
    expect(findById(datagrid, 'total_count').blur_until_loaded).toBeUndefined();
    expect(findById(datagrid, 'total_count_at_least').blur_until_loaded).toBeUndefined();
  });

  /**
   * @scenario load_state=loading
   * @effects blur_wraps_branches_not_row_branch, loading_blurs_zero_counts
   */
  it('받기 전(흐림): 행 자식은 흐린 건수 래퍼·옵션 둘이다', async () => {
    await mount(undefined);
    expectTwoChildRow(true);
  });

  /**
   * @scenario load_state=received
   * @effects blur_wraps_branches_not_row_branch
   */
  it.each([
    ['끝까지 셈', true],
    ['상한에 걸림', false],
  ])('받은 뒤(%s): 행 자식은 건수 래퍼·옵션 둘이고 건수 갈래는 하나만 그려진다', async (_name, exact) => {
    await mount(page(exact as boolean));
    expectTwoChildRow(false);
  });
});
