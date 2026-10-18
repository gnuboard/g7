/**
 * @file orderProgressStepper.test.tsx
 * @description 마이페이지 주문 진행단계 — 상세 상태 헤더 스텝퍼 · 목록 상단 건수 칸 · 상품 행 뱃지 아이콘
 *
 * 클레임 상세와 같은 구조(원 64px · 아이콘 · 균일 칸 · 꺾쇠 · 모바일 줄바꿈)를 주문에도 세운다.
 * 배열은 서버가 만든다(`progress_steps` · `status_summary`) — 화면이 순서를 다시 적으면 그 사본이
 * 어휘의 두 번째 출처가 되어 상태가 늘어도 화면만 낡은 채 남는다. 어느 쪽도 오류를 남기지 않는다.
 *
 * 잰다:
 *  ① 상태 헤더 아래 진행단계가 서버 배열을 그대로 그린다 (7칸 + 이탈 칸, 상태 속성, 아이콘 토큰)
 *  ② 꺾쇠는 마지막 칸을 뺀 칸 사이에만 서고 Icon 노드에 `hidden` 을 직접 주지 않는다
 *  ③ 목록 상단 건수 칸이 서버 배열(키·라벨·아이콘·건수)로 그려지고, 선택된 칸은 현재 스타일,
 *     0 건 칸은 배지 없이 회색이다
 *  ④ 상품 행 상태 뱃지가 서버 토큰 아이콘을 함께 그린다
 *
 * @scenario surface=mypage_detail, order_stage=in_progress
 * @scenario surface=mypage_detail, order_stage=off_path
 * @scenario surface=mypage_list, order_stage=in_progress
 * @effects order_progress_stepper_renders_server_steps,
 *   order_progress_off_path_appends_last_cell,
 *   order_progress_chevrons_between_cells_only,
 *   order_summary_cells_render_server_summary,
 *   order_summary_selected_cell_highlighted_zero_count_dimmed,
 *   order_status_badge_carries_icon
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

type Node = Record<string, any>;

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();

  // 실제 Icon 처럼 이름·부가 속성(data-*)을 DOM 에 남긴다 — 꺾쇠와 상태 아이콘을 속성으로 센다.
  registry.register('basic', 'Icon', ({ className, name, ...rest }: any) =>
    React.createElement('i', { className: `fa-${name} ${className ?? ''}`.trim(), 'data-icon': name, ...rest })
  );

  return registry;
}

function loadPartial(file: string): Node {
  return JSON.parse(
    fs.readFileSync(path.resolve(__dirname, '../../layouts/partials/mypage/orders/' + file), 'utf-8')
  );
}

function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
  if (!node) return acc;
  acc.push(node);
  for (const child of node.children ?? []) flatten(child, acc);
  return acc;
}

/** 서버(`OrderProgressSteps`)가 만든 배열 — 배송중이 현재 */
const PROGRESS_STEPS = [
  { value: 'pending_payment', label: '결제대기', state: 'done', icon: 'status-payment-pending', variant: 'warning' },
  { value: 'payment_complete', label: '결제완료', state: 'done', icon: 'status-paid', variant: 'info' },
  { value: 'preparing', label: '상품준비중', state: 'done', icon: 'status-preparing', variant: 'info' },
  { value: 'shipping_ready', label: '배송준비중', state: 'done', icon: 'status-dispatch-ready', variant: 'info' },
  { value: 'shipping', label: '배송중', state: 'current', icon: 'status-dispatch', variant: 'primary' },
  { value: 'delivered', label: '배송완료', state: 'upcoming', icon: 'status-delivered', variant: 'success' },
  { value: 'confirmed', label: '구매확정', state: 'upcoming', icon: 'status-completed', variant: 'success' },
];

/** 결제 뒤 취소 — 순서 안에 현재 칸이 없고 이탈 칸이 붙는다 */
const CANCELLED_STEPS = [
  { ...PROGRESS_STEPS[0], state: 'done' },
  { ...PROGRESS_STEPS[1], state: 'done' },
  ...PROGRESS_STEPS.slice(2).map((s) => ({ ...s, state: 'upcoming' })),
  { value: 'cancelled', label: '주문취소', state: 'off_path', icon: 'status-cancelled', variant: 'danger' },
];

/** 서버(`OrderService::getUserStatusSummary`)가 만든 목록 상단 건수 칸 */
const STATUS_SUMMARY = [
  { key: 'pending_payment', label: '결제대기', icon: 'status-payment-pending', variant: 'warning', count: 3 },
  { key: 'payment_complete', label: '결제완료', icon: 'status-paid', variant: 'info', count: 17 },
  { key: 'preparing', label: '상품준비·배송준비', icon: 'status-preparing', variant: 'info', count: 0 },
  { key: 'shipping', label: '배송중', icon: 'status-dispatch', variant: 'primary', count: 12 },
  { key: 'delivered', label: '배송완료', icon: 'status-delivered', variant: 'success', count: 20 },
  { key: 'confirmed', label: '구매확정', icon: 'status-completed', variant: 'success', count: 8 },
];

const TRANSLATIONS = {
  mypage: {
    orders: { filter: { all: '전체', pending: '결제대기', paid: '결제완료', preparing: '상품준비중', shipped: '배송중', delivered: '배송완료', confirmed: '구매확정', cancelled: '주문취소' }, partial_cancelled_badge: '일부취소', empty: '주문이 없습니다.' },
    order_detail: { order_number: '주문번호', items_title: '주문 상품' },
  },
};

function detailLayout(files: string[]): Node {
  return {
    data_sources: [
      { id: 'order', type: 'api', endpoint: '/api/modules/sirsoft-ecommerce/user/orders/X', method: 'GET', auto_fetch: true },
    ],
    components: files.map(loadPartial),
  };
}

function listLayout(): Node {
  return {
    data_sources: [
      { id: 'orders', type: 'api', endpoint: '/api/modules/sirsoft-ecommerce/user/orders', method: 'GET', auto_fetch: true },
    ],
    components: [loadPartial('_list.json')],
  };
}

function orderData(overrides: Node = {}): Node {
  return {
    data: {
      id: 63,
      order_number: '20260810-0458227287',
      order_status: 'shipping',
      order_status_label: '배송중',
      order_status_variant: 'primary',
      order_status_icon: 'status-dispatch',
      progress_steps: PROGRESS_STEPS,
      is_partially_cancelled: false,
      options: [],
      ...overrides,
    },
  };
}

let cleanup: (() => void) | null = null;

afterEach(() => {
  cleanup?.();
  cleanup = null;
});

async function renderDetail(order: Node, files = ['_status_header.json']) {
  const utils = createLayoutTest(detailLayout(files), {
    componentRegistry: setupRegistry(),
    locale: 'ko',
    translations: TRANSLATIONS,
    initialState: { _local: {}, _global: { defaultCurrency: 'KRW' } },
  });
  utils.mockApi('order', { response: order });
  cleanup = () => utils.cleanup();
  await utils.render();
  return utils;
}

describe('주문 상세 — 상태 헤더 아래 진행단계 스텝퍼', () => {
  /**
   * @scenario surface=mypage_detail, order_stage=in_progress, viewport=desktop
   * @effects order_progress_stepper_renders_server_steps, order_progress_steps_seven_stages, order_progress_steps_carry_icon_and_variant
   */
  it('서버 배열의 칸을 순서·상태·아이콘 그대로 그리고 상태 헤더 아이콘도 토큰을 읽는다', async () => {
    await renderDetail(orderData());

    const cells = document.querySelectorAll('[id^="order_progress_step_"]');
    expect(cells).toHaveLength(7);
    expect([...cells].map((c) => c.getAttribute('data-step-value'))).toEqual(PROGRESS_STEPS.map((s) => s.value));
    expect([...cells].map((c) => c.getAttribute('data-step-state'))).toEqual(PROGRESS_STEPS.map((s) => s.state));

    // 칸마다 원 안에 서버 토큰 아이콘 하나
    for (const step of PROGRESS_STEPS) {
      const cell = document.querySelector(`[data-step-value="${step.value}"]`)!;
      expect(cell.querySelector(`.rounded-full i[data-icon="${step.icon}"]`), step.value).not.toBeNull();
      expect(cell.textContent).toContain(step.label);
    }

    // 현재 칸은 그 상태 색의 테두리·그림자, 지나간 칸은 채움, 남은 칸은 회색
    const current = document.querySelector('[data-step-value="shipping"] .rounded-full')!;
    expect(current.className).toContain('border-indigo-600');
    expect(current.className).toContain('shadow-md');
    expect(document.querySelector('[data-step-value="payment_complete"] .rounded-full.border-2')!.className).toContain('bg-blue-600');
    expect(document.querySelector('[data-step-value="confirmed"] .rounded-full.border-2')!.className).toContain('border-gray-300');

    // 상태 헤더의 큰 아이콘도 서버 토큰
    const header = document.querySelector('.w-12.h-12 i, .w-10.h-10 i');
    expect(header?.getAttribute('data-icon')).toBe('status-dispatch');
  });

  /**
   * @scenario surface=mypage_detail, order_stage=off_path, viewport=desktop
   * @effects order_progress_off_path_appends_last_cell
   */
  it('이탈(취소) 주문은 순서 안에 현재 칸이 없고 마지막에 빨간 이탈 칸이 붙는다', async () => {
    await renderDetail(orderData({ order_status: 'cancelled', order_status_label: '주문취소', order_status_variant: 'danger', order_status_icon: 'status-cancelled', progress_steps: CANCELLED_STEPS }));

    const cells = document.querySelectorAll('[id^="order_progress_step_"]');
    expect(cells).toHaveLength(8);
    expect(document.querySelectorAll('[data-step-state="current"]')).toHaveLength(0);

    const offPath = document.querySelector('[data-step-value="cancelled"]')!;
    expect(offPath.getAttribute('data-step-state')).toBe('off_path');
    expect(offPath.querySelector('.rounded-full')!.className).toContain('bg-red-500');
    expect(offPath.querySelector('i[data-icon="status-cancelled"]')).not.toBeNull();
  });

  /**
   * @scenario surface=mypage_detail, order_stage=in_progress, viewport=mobile
   * @effects order_progress_chevrons_between_cells_only, order_progress_wraps_on_mobile_without_scroll, order_progress_hidden_is_owned_by_wrapper_div_not_icon
   */
  it('꺾쇠는 마지막 칸을 뺀 칸 사이에만 서고(PC 6 · 모바일 4칸/줄 5) Icon 에 hidden 을 직접 주지 않는다', async () => {
    await renderDetail(orderData());

    expect(document.querySelectorAll('[data-step-chevron="pc"]')).toHaveLength(6);
    expect(document.querySelectorAll('[data-step-chevron="mobile"]')).toHaveLength(5);
    expect(document.querySelector('[data-step-value="confirmed"] [data-step-chevron]')).toBeNull();

    // 숨김은 래퍼 Div 가 맡는다 — Icon 에 준 hidden 은 Font Awesome display 규칙에 밀려 숨겨지지 않는다.
    const icons = flatten(loadPartial('_status_header.json')).filter((n) => n.name === 'Icon');
    expect(icons.length).toBeGreaterThan(0);
    expect(icons.some((n) => String(n.props?.className ?? '').split(' ').includes('hidden'))).toBe(false);
    const pcChevron = flatten(loadPartial('_status_header.json')).find((n) => n.props?.['data-step-chevron'] === 'pc')!;
    expect(pcChevron.name).toBe('Div');
    expect(pcChevron.responsive?.portable?.props?.className).toBe('hidden');
  });

  /**
   * @scenario surface=mypage_detail, order_stage=in_progress, viewport=desktop
   * @effects order_progress_absent_when_steps_empty
   */
  it('진행단계 배열이 비어 있으면 스텝퍼 영역 자체가 서지 않는다', async () => {
    await renderDetail(orderData({ progress_steps: [] }));

    expect(document.querySelector('#order_progress_section')).toBeNull();
    expect(document.querySelectorAll('[id^="order_progress_step_"]')).toHaveLength(0);
  });
});

describe('주문 상세 — 상품 행 상태 뱃지 아이콘', () => {
  /**
   * @scenario surface=mypage_detail, order_stage=in_progress, viewport=desktop
   * @effects order_status_badge_carries_icon
   */
  it('상품 행의 상태 뱃지가 서버 토큰 아이콘을 라벨 앞에 그린다', async () => {
    await renderDetail(
      orderData({
        options: [
          {
            id: 194, product_name: 'USB 충전 케이블', option_name: '1m/블랙', quantity: 1,
            option_status: 'shipping', option_status_label: '배송중', option_status_variant: 'primary', option_status_icon: 'status-dispatch',
            unit_price_formatted: '14,000원', subtotal_price_formatted: '14,000원', thumbnail_url: null, additional_options: [],
          },
        ],
      }),
      ['_items.json'],
    );

    const icons = document.querySelectorAll('i[data-icon="status-dispatch"]');
    expect(icons.length).toBeGreaterThan(0);
    const badge = icons[0].closest('span')!;
    expect(badge.className).toContain('rounded-full');
    expect(badge.className).toContain('gap-1');
    expect(badge.textContent).toContain('배송중');
  });
});

describe('주문 목록 상단 — 상태별 건수 칸', () => {
  async function renderList(localState: Node = {}) {
    const utils = createLayoutTest(listLayout(), {
      componentRegistry: setupRegistry(),
      locale: 'ko',
      translations: TRANSLATIONS,
      initialState: { _global: { defaultCurrency: 'KRW' }, _local: localState },
    });
    utils.mockApi('orders', {
      response: {
        data: {
          data: [],
          statistics: Object.fromEntries(STATUS_SUMMARY.map((s) => [s.key, s.count])),
          status_summary: STATUS_SUMMARY,
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
        },
      },
    });
    cleanup = () => utils.cleanup();
    await utils.render();
    return utils;
  }

  /**
   * @scenario surface=mypage_list, order_stage=in_progress, viewport=mobile
   * @effects order_summary_cells_render_server_summary, order_summary_wraps_on_mobile_without_scroll
   */
  it('서버 배열의 여섯 칸을 키·라벨·아이콘·건수 배지로 그린다', async () => {
    await renderList();

    const cells = document.querySelectorAll('[id^="order_summary_step_"]');
    expect(cells).toHaveLength(6);
    expect([...cells].map((c) => c.getAttribute('data-summary-key'))).toEqual(STATUS_SUMMARY.map((s) => s.key));

    for (const step of STATUS_SUMMARY) {
      const cell = document.querySelector(`[data-summary-key="${step.key}"]`)!;
      expect(cell.querySelector(`.rounded-full i[data-icon="${step.icon}"]`), step.key).not.toBeNull();
      expect(cell.textContent).toContain(step.label);
    }

    // 건수 배지 — 0 건은 없고, 있는 칸은 그 숫자
    expect(document.querySelector('[data-summary-badge="payment_complete"]')!.textContent).toBe('17');
    expect(document.querySelector('[data-summary-badge="preparing"]')).toBeNull();

    // 꺾쇠 — PC 5 · 모바일 3칸/줄이라 줄 끝(3번째) 을 뺀 4
    expect(document.querySelectorAll('[data-step-chevron="pc"]')).toHaveLength(5);
    expect(document.querySelectorAll('[data-step-chevron="mobile"]')).toHaveLength(4);
  });

  /**
   * @scenario surface=mypage_list, order_stage=in_progress, viewport=desktop
   * @effects order_summary_selected_cell_highlighted_zero_count_dimmed
   */
  it('선택된 칸은 현재 칸 스타일, 건수 있는 칸은 채움, 0 건은 회색이다', async () => {
    await renderList({ ordersStatus: 'shipping' });

    expect(document.querySelector('[data-summary-key="shipping"] .rounded-full.border-2')!.className).toContain('border-indigo-600');
    expect(document.querySelector('[data-summary-key="shipping"] .rounded-full.border-2')!.className).toContain('shadow-md');
    expect(document.querySelector('[data-summary-key="delivered"] .rounded-full.border-2')!.className).toContain('bg-green-600');
    expect(document.querySelector('[data-summary-key="preparing"] .rounded-full.border-2')!.className).toContain('border-gray-300');
  });

  /**
   * @scenario surface=mypage_list, order_stage=in_progress, viewport=desktop
   * @effects order_summary_click_filters_by_status
   */
  it('칸 클릭은 그 상태를 필터로 보내는 목록 안 이동이다 (mergeQuery 유지)', () => {
    const cell = flatten(loadPartial('_list.json')).find((n) => String(n.id ?? '').startsWith('order_summary_step_'))!;
    const click = (cell.actions ?? []).find((a: Node) => a.type === 'click')!;
    const navigate = (click.actions ?? []).find((a: Node) => a.handler === 'navigate')!;

    expect(navigate.params.path).toBe('/mypage/orders');
    expect(navigate.params.mergeQuery).toBe(true);
    expect(navigate.params.query).toEqual({ status: '{{step.key}}', page: '1' });
  });
});
