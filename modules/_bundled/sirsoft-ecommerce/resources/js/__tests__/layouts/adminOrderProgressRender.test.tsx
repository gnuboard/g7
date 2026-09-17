/**
 * @file adminOrderProgressRender.test.tsx
 * @description 관리자 주문 상세 — 진행단계 스텝퍼 (탭 위 상시 노출) · 상품 행 뱃지 아이콘
 *
 * 클레임 상세와 같은 구조를 주문에도 세운다. 배열은 서버(`OrderProgressSteps`)가 만들고 회원·비회원
 * 상세와 같다 — 화면이 순서를 다시 적으면 그 사본이 어휘의 두 번째 출처가 된다.
 *
 * @scenario surface=admin_detail, order_stage=in_progress
 * @scenario surface=admin_detail, order_stage=off_path
 * @effects order_progress_stepper_renders_server_steps,
 *   order_progress_off_path_appends_last_cell,
 *   order_progress_chevrons_between_cells_only,
 *   order_progress_card_mounted_above_tabs,
 *   order_status_badge_carries_icon
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import progressPartial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_progress.json';
import orderInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json';
import detailLayout from '../../../layouts/admin/admin_ecommerce_order_detail.json';

type Node = Record<string, any>;

/** 레이아웃 트리의 모든 객체 노드 — `components`·`children`·DataGrid 셀 자식처럼 자식을 담는 키가 여럿이라 값 전체를 훑는다. */
function flatten(node: unknown, acc: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    for (const item of node) flatten(item, acc);
    return acc;
  }
  if (!node || typeof node !== 'object') return acc;
  acc.push(node as Node);
  for (const value of Object.values(node as Node)) flatten(value, acc);
  return acc;
}

const PROGRESS_STEPS = [
  { value: 'pending_payment', label: '결제대기', state: 'done', icon: 'status-payment-pending', variant: 'warning' },
  { value: 'payment_complete', label: '결제완료', state: 'done', icon: 'status-paid', variant: 'info' },
  { value: 'preparing', label: '상품준비중', state: 'current', icon: 'status-preparing', variant: 'info' },
  { value: 'shipping_ready', label: '배송준비중', state: 'upcoming', icon: 'status-dispatch-ready', variant: 'info' },
  { value: 'shipping', label: '배송중', state: 'upcoming', icon: 'status-dispatch', variant: 'primary' },
  { value: 'delivered', label: '배송완료', state: 'upcoming', icon: 'status-delivered', variant: 'success' },
  { value: 'confirmed', label: '구매확정', state: 'upcoming', icon: 'status-completed', variant: 'success' },
];

const HOLD_STEPS = [
  ...PROGRESS_STEPS.slice(0, 2),
  ...PROGRESS_STEPS.slice(2).map((s) => ({ ...s, state: 'upcoming' })),
  { value: 'shipping_hold', label: '배송보류', state: 'off_path', icon: 'status-on-hold', variant: 'warning' },
];

function layoutFor(steps: Node[]): Node {
  return {
    data_sources: [
      { id: 'order', type: 'api', endpoint: '/api/modules/sirsoft-ecommerce/admin/orders/X', method: 'GET', auto_fetch: true },
    ],
    components: [progressPartial],
  };
}

let cleanup: (() => void) | null = null;

afterEach(() => {
  cleanup?.();
  cleanup = null;
});

async function renderSteps(steps: Node[]) {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('basic', 'Icon', ({ className, name, ...rest }: any) =>
    React.createElement('i', { className: `fa-${name} ${className ?? ''}`.trim(), 'data-icon': name, ...rest })
  );

  const utils = createLayoutTest(layoutFor(steps), {
    componentRegistry: registry,
    locale: 'ko',
    initialState: { _local: {} },
    translations: { 'sirsoft-ecommerce': { admin: { order: { detail: { progress_title: '진행 단계' } } } } },
  });
  utils.mockApi('order', { response: { data: { id: 100, order_number: '20260908-0001', order_status: 'preparing', progress_steps: steps } } });
  cleanup = () => utils.cleanup();
  await utils.render();
}

describe('관리자 주문 상세 — 진행단계 스텝퍼', () => {
  /**
   * @scenario surface=admin_detail, order_stage=in_progress, viewport=desktop
   * @effects order_progress_stepper_renders_server_steps, order_progress_steps_seven_stages, order_progress_steps_carry_icon_and_variant, order_progress_chevrons_between_cells_only
   */
  it('서버 배열의 일곱 칸을 순서·상태·아이콘 그대로 그린다', async () => {
    await renderSteps(PROGRESS_STEPS);

    const cells = document.querySelectorAll('[id^="order_progress_step_"]');
    expect(cells).toHaveLength(7);
    expect([...cells].map((c) => c.getAttribute('data-step-state'))).toEqual(PROGRESS_STEPS.map((s) => s.state));

    for (const step of PROGRESS_STEPS) {
      const cell = document.querySelector(`[data-step-value="${step.value}"]`)!;
      expect(cell.querySelector(`.rounded-full i[data-icon="${step.icon}"]`), step.value).not.toBeNull();
      expect(cell.textContent).toContain(step.label);
    }

    expect(document.querySelector('[data-step-value="preparing"] .rounded-full')!.className).toContain('border-blue-600');
    expect(document.querySelectorAll('[data-step-chevron="pc"]')).toHaveLength(6);
    expect(document.querySelectorAll('[data-step-chevron="mobile"]')).toHaveLength(5);
    expect(document.body.textContent).toContain('진행 단계');
  });

  /**
   * @scenario surface=admin_detail, order_stage=off_path, viewport=desktop
   * @effects order_progress_off_path_appends_last_cell
   */
  it('배송보류는 순서 밖의 이탈 칸으로 마지막에 붙고 순서 안에 현재 칸이 없다', async () => {
    await renderSteps(HOLD_STEPS);

    expect(document.querySelectorAll('[id^="order_progress_step_"]')).toHaveLength(8);
    expect(document.querySelectorAll('[data-step-state="current"]')).toHaveLength(0);
    const hold = document.querySelector('[data-step-value="shipping_hold"]')!;
    expect(hold.getAttribute('data-step-state')).toBe('off_path');
    expect(hold.querySelector('.rounded-full')!.className).toContain('bg-red-500');
  });

  /**
   * @scenario surface=admin_detail, order_stage=in_progress, viewport=desktop
   * @effects order_progress_absent_when_steps_empty
   */
  it('배열이 비면 카드 자체가 서지 않는다', async () => {
    await renderSteps([]);

    expect(document.querySelector('#order_progress_card')).toBeNull();
  });
});

describe('관리자 주문 상세 — 배선', () => {
  /**
   * @scenario surface=admin_detail, order_stage=in_progress, viewport=desktop
   * @effects order_progress_card_mounted_above_tabs
   */
  it('진행단계 카드는 탭 내비게이션 앞(탭 밖)에 마운트되어 어느 탭에서도 보인다', () => {
    const parent = flatten(detailLayout as Node).find(
      (n) => Array.isArray(n.children) && n.children.some((c: Node) => c.id === 'tab_navigation')
    )!;
    const idx = parent.children.findIndex((c: Node) => c.partial === 'partials/admin_ecommerce_order_detail/_partial_order_progress.json');
    const tabIdx = parent.children.findIndex((c: Node) => c.id === 'tab_navigation');

    expect(idx).toBeGreaterThanOrEqual(0);
    expect(idx).toBeLessThan(tabIdx);
  });

  /**
   * @scenario surface=admin_detail, order_stage=in_progress, viewport=mobile
   * @effects order_progress_iteration_reads_server_array_only, order_progress_hidden_is_owned_by_wrapper_div_not_icon
   */
  it('스텝퍼는 서버 배열만 읽고 Icon 노드에 hidden 을 직접 주지 않는다', () => {
    const nodes = flatten(progressPartial as Node);
    const iteration = nodes.find((n) => n.iteration)!;
    expect(iteration.iteration.source).toBe('{{order?.data?.progress_steps ?? []}}');

    const icons = nodes.filter((n) => n.name === 'Icon');
    expect(icons.length).toBeGreaterThan(0);
    expect(icons.some((n) => String(n.props?.className ?? '').split(' ').includes('hidden'))).toBe(false);
    // responsive.portable 블록도 같은 data-* 를 갖지만 노드가 아니다 — type 을 가진 노드만 본다.
    const chevronNodes = nodes.filter((n) => n.type && n.props?.['data-step-chevron']);
    expect(chevronNodes.length).toBe(2);
    expect(chevronNodes.every((n) => n.name === 'Div')).toBe(true);
  });

  /**
   * @scenario surface=admin_detail, order_stage=in_progress, viewport=desktop
   * @effects order_status_badge_carries_icon
   */
  it('상품 행 상태 뱃지가 서버 토큰을 Badge icon 으로 넘긴다', () => {
    const badge = flatten(orderInfoPartial as Node).find(
      (n) => n.name === 'Badge' && n.text === '{{row.option_status_label}}'
    )!;

    expect(badge.props.icon).toBe("{{row.option_status_icon ?? ''}}");
    expect(badge.props.variant).toBe('{{row.option_status_variant}}');
  });
});
