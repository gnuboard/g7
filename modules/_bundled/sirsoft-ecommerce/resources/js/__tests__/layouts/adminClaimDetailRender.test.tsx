/**
 * @file adminClaimDetailRender.test.tsx
 * @description 관리자 클레임 상세 골격 — 유형별 렌더 (S9a · D17·D14·D24)
 *
 * 조건은 `if` 하나에 걸려 있어 구조 단언만으로는 실제로 걸러지는지 알 수 없다. 실제로
 * 렌더해 확인한다:
 *
 *  1. 배송 섹션이 환불에서 통째로 사라지고, 반품에서는 재발송이 '해당 없음' 으로 남는다.
 *  2. 재발송지 뱃지 2종이 서로 다른 근거로 각각 켜진다 (D14) — 하나만 참인 경우가 실재한다.
 *  3. 진행단계 스텝퍼가 서버가 준 배열과 상태를 그대로 그린다.
 *  4. 관리자 메모 입력이 환불에서는 그려지지 않는다 — 서버가 그 필드를 거절하므로 입력을
 *     그려 두면 운영자가 적은 내용이 오류 없이 버려진다.
 *  5. 금액 셀이 서버가 준 표기 문자열을 그대로 그린다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import itemsSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_items.json';
import shippingSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json';
import reasonBlock from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.json';
import paymentSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.json';

type Node = Record<string, any>;

const GRID_ROW_TESTID = 'claim-item-row';

/** 교환 신청 상품 두 행 (하나는 옵션 변경, 하나는 동일 옵션) */
const EXCHANGE_ITEMS = [
  {
    id: 55,
    product_name: '프리미엄 코튼 티셔츠',
    option_name: '차콜 / M',
    is_option_change: true,
    new_option_label: '블랙 / L',
    // 수량 2 — 소계와 단가가 갈라져야 금액 자리의 좌우 단위 혼재가 드러난다(S17 B8).
    quantity: 2,
    original_quantity: 3,
    unit_price_formatted: '29,000원',
    subtotal_amount_formatted: '58,000원',
    new_unit_price_formatted: '32,000원',
    new_subtotal_amount_formatted: '64,000원',
    // 교환에 얹은 추가옵션 — 재발송 담당자가 새겨 보낼 문구다(S17 B3).
    new_additional_options: [{ name: '각인 서비스', custom_text: 'HJ' }],
    price_difference: 6000,
    price_difference_formatted: '6,000원',
    status_label: '교환회수중',
    status_variant: 'info',
    thumbnail_url: '/storage/products/1/thumb.jpg',
  },
  {
    id: 56,
    product_name: '소프트 터치 니트',
    option_name: '아이보리 / L',
    // 동일 옵션 교환이다. 서버는 이런 행에도 `new_*` 를 원 값으로 채워 내려준다
    // (`ClaimItemResource` 의 `?? $unitPrice` 폴백) — 값의 존재는 교환 대상의 근거가 아니다.
    is_option_change: false,
    new_option_label: null,
    quantity: 1,
    original_quantity: 1,
    unit_price_formatted: '39,000원',
    subtotal_amount_formatted: '39,000원',
    new_unit_price_formatted: '39,000원',
    new_subtotal_amount_formatted: '39,000원',
    new_additional_options: [],
    price_difference: 0,
    price_difference_formatted: '0원',
    status_label: '교환회수중',
    status_variant: 'info',
    thumbnail_url: null,
  },
];

/** 진행단계 (서버가 만든 배열 — 청구액이 없는 교환이라 결제 두 칸이 빠져 있다) */
const PROGRESS_STEPS = [
  { value: 'requested', label: '교환신청', state: 'done', icon: 'status-request', variant: 'secondary' },
  { value: 'approved', label: '교환승인', state: 'done', icon: 'status-approved', variant: 'info' },
  { value: 'collection_ready', label: '교환회수준비중', state: 'done', icon: 'status-pickup-ready', variant: 'primary' },
  { value: 'collecting', label: '교환회수중', state: 'current', icon: 'status-pickup', variant: 'primary' },
  { value: 'collected', label: '교환회수도착', state: 'upcoming', icon: 'status-received', variant: 'primary' },
  { value: 'redelivery_ready', label: '교환발송준비중', state: 'upcoming', icon: 'status-dispatch-ready', variant: 'primary' },
  { value: 'redelivering', label: '교환발송중', state: 'upcoming', icon: 'status-dispatch', variant: 'primary' },
  { value: 'redelivered', label: '교환발송도착', state: 'upcoming', icon: 'status-delivered', variant: 'primary' },
  { value: 'completed', label: '교환완료', state: 'upcoming', icon: 'status-completed', variant: 'success' },
];

/**
 * 상세 응답 봉투를 만든다.
 *
 * @param overrides 신청서 축 덮어쓰기
 * @param envelope 봉투 축 덮어쓰기
 * @returns 데이터소스 응답
 */
function claimResponse(overrides: Node = {}, envelope: Node = {}) {
  return {
    data: {
      claim: {
        id: 31,
        claim_number: 'EX20260827000031',
        claim_type: 'exchange',
        claim_type_label: '교환',
        order_number: '20260827-0001',
        status: 'collecting',
        status_label: '교환회수중',
        status_variant: 'info',
        is_final: false,
        is_guest_request: false,
        requester_name: '김지은',
        requested_at_formatted: '2026-08-27 10:12',
        reason_label: '단순 변심',
        reason_detail: '사이즈가 작아요',
        fault_type_label: '고객 귀책',
        reject_reason: null,
        admin_memo: '회수 후 재검수 필요',
        items: EXCHANGE_ITEMS,
        images: [],
        return_address_snapshot: {
          recipient_name: '반품센터',
          phone: '02-000-0000',
          zipcode: '06000',
          address1: '서울시 강남구',
          address2: '1층',
        },
        redelivery_address_snapshot: {
          recipient_name: '김지은',
          phone: '010-0000-0000',
          zipcode: '13500',
          address1: '경기도 성남시',
          address2: '101동 1001호',
          changed_by_user: false,
        },
        collection_carrier_id: null,
        collection_tracking_number: null,
        collection_tracking_url: null,
        collection_completed_at_formatted: null,
        redelivery_carrier_id: null,
        redelivery_tracking_number: null,
        redelivery_tracking_url: null,
        redelivered_at_formatted: null,
        ...overrides,
      },
      claim_path_segment: 'exchanges',
      abilities: { can_update_status: true, can_update_amounts: true, can_execute_refund: false },
      complete_defaults: { create_refund_request: true, restore_stock: true },
      progress_steps: PROGRESS_STEPS,
      redelivery_address_differs: false,
      carriers: [
        { value: 3, label: 'CJ대한통운', code: 'cj', type: 'domestic', tracking_url: null },
      ],
      ...envelope,
    },
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();

  // 아이콘은 이름·부가 속성이 DOM 에 남아야 진행단계 칸의 그림과 꺾쇠를 셀 수 있다.
  registry.register('basic', 'Icon', ({ name, className, ...rest }: any) =>
    React.createElement('i', { className: `fa-${name} ${className ?? ''}`.trim(), 'data-icon': name, ...rest })
  );

  registry.register('composite', 'DataGrid', ({ columns, data, selectable, selectionScope }: any) =>
    React.createElement(
      'div',
      {
        'data-testid': 'claim-items-grid',
        'data-selectable': String(!!selectable),
        'data-selection-scope': selectionScope,
      },
      (data ?? []).map((row: any, index: number) =>
        React.createElement(
          'div',
          { key: index, 'data-testid': GRID_ROW_TESTID },
          (columns ?? []).map((column: any) =>
            React.createElement(
              'span',
              { key: column.field, 'data-field': column.field },
              renderCell(column, row)
            )
          )
        )
      )
    )
  );

  // 환불 수단 라디오 — 이 테스트가 보는 축은 '서버가 준 선택지를 그렸는가' 와 게이팅뿐이다.
  registry.register('composite', 'RadioGroup', ({ id, options, value, disabled }: any) =>
    React.createElement(
      'div',
      { id, 'data-testid': 'claim-refund-method', 'data-value': value, 'data-disabled': String(!!disabled) },
      (options ?? []).map((option: any, index: number) =>
        React.createElement('span', { key: index, 'data-value': option.value }, option.label)
      )
    )
  );

  // 뱃지는 '어떤 뱃지가 켜졌는가' 만 보므로 레이아웃 id 를 그대로 내보낸다.
  registry.register('composite', 'Badge', ({ id, variant, text, children }: any) =>
    React.createElement(
      'span',
      { id, 'data-testid': 'claim-badge', 'data-variant': variant },
      text ?? children
    )
  );
});

afterEach(() => {
  vi.clearAllMocks();
});

/**
 * 컬럼의 `cellChildren` 표현식을 행 데이터로 아주 얕게 풀어낸다.
 *
 * DataGrid 스텁이 실제 바인딩 엔진을 태우지 않으므로, 이 테스트가 보는 축(금액·옵션 표기)만
 * 판별할 수 있도록 `{{row.*}}` 단일 바인딩과 `if` 조건, 보간 텍스트만 해석한다.
 *
 * @param column 컬럼 정의
 * @param row 행 데이터
 * @returns 셀 문자열
 */
function renderCell(column: any, row: any): string {
  const texts: string[] = [];

  const visit = (node: any): void => {
    if (Array.isArray(node)) {
      node.forEach(visit);

      return;
    }
    if (!node || typeof node !== 'object') return;
    if (typeof node.if === 'string' && !evaluateCondition(node.if, row)) return;

    if (typeof node.text === 'string') {
      const resolved = resolveText(node.text, row);
      if (resolved) texts.push(resolved);
    }
    (node.children ?? []).forEach(visit);
  };

  (column.cellChildren ?? []).forEach(visit);

  return texts.join(' ');
}

/**
 * 표현식을 행 데이터로 평가한다. (테스트 전용 최소 해석기)
 *
 * @param expression 표현식
 * @param row 행 데이터
 * @returns 평가 결과
 */
function evaluate(expression: string, row: any): unknown {
  // eslint-disable-next-line no-new-func
  return new Function('row', `"use strict"; try { return (${expression}); } catch (e) { return undefined; }`)(row);
}

/**
 * `if` 조건을 평가한다.
 *
 * @param condition 조건 문자열
 * @param row 행 데이터
 * @returns 참·거짓
 */
function evaluateCondition(condition: string, row: any): boolean {
  const match = condition.match(/^\{\{(.*)\}\}$/s);

  return match ? !!evaluate(match[1], row) : true;
}

/**
 * `text` 를 평가한다. 다국어 키는 키 자체를 돌려주고, 보간 텍스트는 부분 치환한다.
 *
 * @param text 텍스트 정의
 * @param row 행 데이터
 * @returns 문자열
 */
function resolveText(text: string, row: any): string {
  if (text.startsWith('$t:')) {
    return text;
  }

  return text.replace(/\{\{([\s\S]*?)\}\}/g, (_all, expression) => {
    const value = evaluate(expression, row);

    return value === undefined || value === null ? '' : String(value);
  }).trim();
}

/**
 * 노드 하나를 프로브 레이아웃으로 감싼다.
 *
 * @param node 노드
 * @returns 레이아웃 JSON
 */
function probe(node: Node): Node {
  const { meta, ...rest } = node;

  return {
    version: '1.0.0',
    layout_name: 'test/admin-claim-detail',
    data_sources: [
      {
        id: 'claim',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/admin/claims/EX20260827000031',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: [rest],
  };
}

/**
 * 프로브를 특정 응답으로 렌더한다.
 *
 * @param node 노드
 * @param response 상세 응답
 * @returns 테스트 유틸
 */
async function renderProbe(node: Node, response: Record<string, unknown> = claimResponse()) {
  const utils = createLayoutTest(probe(node) as any, {
    componentRegistry: registry as any,
    locale: 'ko',
  });
  utils.mockApi('claim', { response });
  await utils.render();

  return utils;
}

describe('배송 섹션 — 유형별 노출 (D17-②)', () => {
  /**
   * 환불에는 회수·재발송 축이 없다 — 섹션이 통째로 사라져야 탭 배열과 어긋나지 않는다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_shipping_section_absent_for_refund
   */
  it('환불 유형에서는 배송 섹션이 렌더되지 않는다', async () => {
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse({ claim_type: 'refund' }, { claim_path_segment: 'refunds', carriers: [] })
    );

    expect(document.querySelector('[data-section-id="shipping_info"]')).toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario claim_type=return, claim_stage=in_progress
   * @effects claim_shipping_redelivery_marked_not_applicable
   */
  it('반품에서는 회수만 편집 가능하고 재발송은 해당 없음으로 남는다', async () => {
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse({ claim_type: 'return' }, { claim_path_segment: 'returns' })
    );

    expect(document.querySelector('[data-section-id="shipping_info"]')).not.toBeNull();
    expect(document.getElementById('claim_collection_block')).not.toBeNull();
    expect(document.getElementById('cs_008_redelivery_na_text')).not.toBeNull();
    expect(document.getElementById('cs_016_redelivery_save')).toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_shipping_two_blocks_in_one_card
   */
  it('교환에서는 한 카드 안에 회수·재발송 두 항목이 함께 나온다', async () => {
    const utils = await renderProbe(shippingSection as Node);

    const card = document.getElementById('claim_shipping_card');
    expect(card).not.toBeNull();
    expect(card?.querySelector('#claim_collection_block')).not.toBeNull();
    expect(card?.querySelector('#claim_redelivery_block')).not.toBeNull();
    expect(document.getElementById('cs_008_redelivery_na_text')).toBeNull();
    utils.cleanup();
  });
});

describe('재발송지 뱃지 2종 (D14)', () => {
  /**
   * 값이 같아도 고객이 손댄 사실은 남는다 — 두 뱃지가 독립이어야 그 상태를 표현할 수 있다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_redelivery_changed_by_user_badge_alone
   */
  it('주소는 같은데 고객이 손댄 건은 "고객이 수정함" 만 켜진다', async () => {
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse(
        {
          redelivery_address_snapshot: {
            recipient_name: '김지은',
            phone: '010-0000-0000',
            zipcode: '13500',
            address1: '경기도 성남시',
            address2: '101동 1001호',
            changed_by_user: true,
          },
        },
        { redelivery_address_differs: false }
      )
    );

    expect(document.getElementById('cs_010_changed_by_user_badge')).not.toBeNull();
    expect(document.getElementById('cs_009_address_differs_badge')).toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_redelivery_address_differs_badge_alone
   */
  it('운영자가 바꿔 주소만 다른 건은 "배송지 다름" 만 켜진다', async () => {
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse({}, { redelivery_address_differs: true })
    );

    expect(document.getElementById('cs_009_address_differs_badge')).not.toBeNull();
    expect(document.getElementById('cs_010_changed_by_user_badge')).toBeNull();
    utils.cleanup();
  });
});

describe('신청 정보 섹션 — 스텝퍼·상품·완료 카드', () => {
  /**
   * 청구액이 없는 건에는 결제 두 칸이 없다 — 서버가 추린 배열을 그대로 그린다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_progress_stepper_renders_server_steps
   */
  it('진행단계가 서버가 준 칸 수만큼 나오고 현재 칸이 하나다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const labels = [...document.querySelectorAll('[id^="claim_progress_step_"]')].map(
      (el) => el.textContent?.trim()
    );

    expect(labels).toHaveLength(PROGRESS_STEPS.length);
    expect(labels.join(' ')).toContain('교환회수중');
    expect(labels.join(' ')).not.toContain('교환결제대기');
    utils.cleanup();
  });

  /**
   * 칸마다 서버가 준 아이콘이 서고 상태는 DOM 속성으로 드러난다. 꺾쇠는 칸 수보다 하나 적다 —
   * 마지막 칸 뒤에 꺾쇠가 서면 '다음 단계가 더 있다' 로 읽힌다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_progress_steps_carry_icon_and_variant, claim_progress_cells_uniform_width_with_chevrons
   */
  it('진행단계 칸마다 상태 아이콘이 서고 PC 꺾쇠는 칸 수보다 하나 적다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const cells = [...document.querySelectorAll('[id^="claim_progress_step_"]')];
    expect(cells.map((el) => el.getAttribute('data-step-state'))).toEqual(PROGRESS_STEPS.map((s) => s.state));

    const icons = cells.map((el) => el.querySelector('.rounded-full [data-icon]')?.getAttribute('data-icon'));
    expect(icons).toEqual(PROGRESS_STEPS.map((s) => s.icon));

    // 현재 칸(교환회수중)은 primary 팔레트의 '현재' 색을, 지나간 칸은 채움색을 입는다.
    const current = cells[3].querySelector('.rounded-full') as HTMLElement;
    expect(current.className).toContain('border-indigo-600');
    expect(current.className).toContain('bg-indigo-100');
    const done = cells[1].querySelector('.rounded-full') as HTMLElement;
    expect(done.className).toContain('bg-blue-600');

    expect(document.querySelectorAll('[data-step-chevron="pc"]')).toHaveLength(PROGRESS_STEPS.length - 1);
    // 모바일 꺾쇠는 줄 끝(4n번째)을 빼고 선다 — 9칸이면 8 - 2 = 6.
    expect(document.querySelectorAll('[data-step-chevron="mobile"]')).toHaveLength(6);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_items_grid_renders_server_formatted_amounts
   */
  it('상품 표가 서버 표기 문자열을 그대로 그린다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const rows = screen.queryAllByTestId(GRID_ROW_TESTID);
    expect(rows).toHaveLength(2);

    const amount = rows[0].querySelector('[data-field="amount"]')?.textContent ?? '';
    expect(amount).toContain('58,000원');
    expect(amount).toContain('64,000원');

    const quantity = rows[0].querySelector('[data-field="quantity"]')?.textContent ?? '';
    expect(quantity).toBe('2 / 3');
    utils.cleanup();
  });

  /**
   * 차액이 0 인 행에 "차액 0원" 을 붙이면 운영자가 조정이 있었다고 읽는다.
   *
   * @effects claim_items_grid_hides_zero_difference
   */
  it('차액이 0 인 행에는 차액 줄이 없다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const rows = screen.queryAllByTestId(GRID_ROW_TESTID);
    expect(rows[1].querySelector('[data-field="amount"]')?.textContent).not.toContain('price_difference');
    utils.cleanup();
  });

  /**
   * 서버는 옵션 변경이 아닌 행에도 `new_unit_price_formatted` 를 **원 값으로 폴백해** 채운다.
   * 값의 존재로 분기하면 동일 옵션 교환에 `→ 39,000원` 이 붙어, 운영자는 교환 대상이
   * 따로 지정된 것으로 읽는다 — 값은 맞고 의미만 틀린 표시라 오류가 남지 않는다(실측).
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_items_grid_marks_option_change_by_flag
   */
  it('동일 옵션 교환 행에는 교환 대상 줄이 붙지 않는다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const rows = screen.queryAllByTestId(GRID_ROW_TESTID);

    // 옵션 변경 행: 교환 대상 가격과 옵션이 함께 나온다.
    expect(rows[0].querySelector('[data-field="amount"]')?.textContent).toContain('64,000원');
    expect(rows[0].querySelector('[data-field="product_info"]')?.textContent).toContain('블랙 / L');

    // 동일 옵션 교환 행: 서버가 값을 채워 보내도 화살표 줄이 없다.
    expect(rows[1].querySelector('[data-field="amount"]')?.textContent).not.toContain('→');
    utils.cleanup();
  });

  /**
   * 좌항이 소계(58,000)인데 우항에 단가(32,000)를 그리면 같은 줄의 좌우가 서로 다른 단위를
   * 말한다. 수량 1 에서는 값이 같아 드러나지 않고 수량 2 이상에서만 보인다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_items_grid_pairs_subtotal_with_subtotal
   */
  it('교환 후 금액도 단가가 아니라 소계로 그린다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const amount = screen.queryAllByTestId(GRID_ROW_TESTID)[0]
      .querySelector('[data-field="amount"]')?.textContent ?? '';

    expect(amount).toContain('58,000원');
    expect(amount).toContain('64,000원');
    expect(amount).not.toContain('32,000원');
    utils.cleanup();
  });

  /**
   * 서버는 교환에 얹은 추가옵션(각인 문구 등)을 싣고 청구액에도 반영한다. 관리자 화면이
   * 그리지 않으면 재발송 담당자가 새겨 보낼 문구를 어디에서도 확인할 수 없다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_items_grid_shows_exchange_additional_options
   */
  it('교환 추가옵션의 이름과 입력 문구를 그린다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const rows = screen.queryAllByTestId(GRID_ROW_TESTID);
    const info = rows[0].querySelector('[data-field="product_info"]')?.textContent ?? '';

    expect(info).toContain('각인 서비스');
    expect(info).toContain('HJ');

    // 추가옵션이 없는 행에는 줄이 서지 않는다.
    expect(rows[1].querySelector('[data-field="product_info"]')?.textContent).not.toContain('+ ');
    utils.cleanup();
  });

  /**
   * 환불에는 상품 단위 전이 축이 없다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_item_bulk_bar_absent_for_refund_render
   */
  it('환불 유형에서는 상품 단위 일괄 바가 사라진다', async () => {
    const utils = await renderProbe(
      itemsSection as Node,
      claimResponse({ claim_type: 'refund' }, { claim_path_segment: 'refunds' })
    );

    expect(document.getElementById('claim_item_bulk_bar')).toBeNull();
    expect(screen.getByTestId('claim-items-grid').getAttribute('data-selectable')).toBe('false');
    utils.cleanup();
  });

  /**
   * 이미 종결된 신청서에 완료 버튼이 남으면 누를 때마다 전이 게이트에 막힌다.
   *
   * @scenario claim_type=exchange, claim_stage=final
   * @effects claim_completion_card_hidden_when_final
   */
  it('종결된 신청서에는 완료 처리 카드가 없다', async () => {
    const utils = await renderProbe(itemsSection as Node, claimResponse({ is_final: true }));

    expect(document.getElementById('claim_completion_card')).toBeNull();
    utils.cleanup();
  });
});

describe('사유·관리자 메모 (2단)', () => {
  /**
   * 환불신청서에는 메모 컬럼이 없다 — 입력을 그려 두면 운영자가 적은 내용이 오류 없이 버려진다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_admin_memo_input_absent_for_refund
   */
  it('환불 유형에는 메모 입력 대신 해당 없음 안내가 나온다', async () => {
    const utils = await renderProbe(
      reasonBlock as Node,
      claimResponse({ claim_type: 'refund', admin_memo: null }, { claim_path_segment: 'refunds' })
    );

    expect(document.getElementById('cm_001_memo_unavailable')).not.toBeNull();
    expect(document.getElementById('cm_003_memo_textarea')).toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_admin_memo_input_seeded_from_response
   */
  it('교환 유형에는 메모 입력이 나온다', async () => {
    const utils = await renderProbe(reasonBlock as Node);

    expect(document.getElementById('cm_003_memo_textarea')).not.toBeNull();
    expect(document.getElementById('cm_001_memo_unavailable')).toBeNull();
    utils.cleanup();
  });

  /**
   * 반려 사유는 있을 때만 나온다 — 빈 줄이 남으면 운영자가 반려된 건으로 오해한다.
   * 반려는 순서 밖의 이탈 상태라, 이 축이 교환의 off_path 조합을 연다.
   *
   * @scenario claim_type=exchange, claim_stage=off_path
   * @effects claim_reject_reason_row_conditional
   */
  it('반려 사유가 없으면 그 줄이 렌더되지 않는다', async () => {
    const utils = await renderProbe(reasonBlock as Node);
    expect(document.getElementById('cr_003_reject_reason_row')).toBeNull();
    utils.cleanup();

    const rejected = await renderProbe(
      reasonBlock as Node,
      claimResponse({ reject_reason: '상품 훼손 확인' })
    );
    expect(document.getElementById('cr_003_reject_reason_row')).not.toBeNull();
    rejected.cleanup();
  });
});

describe('결제 정보 · 환불 실행 섹션 — 유형별 렌더 (S9b · D17-③·D18·D19)', () => {
  /**
   * 결제 정보 섹션이 읽는 주문 축. 값은 전부 서버가 표기한 문자열이다 — 화면이 기호를
   * 붙이면 기본 통화가 원이 아닌 상점에서 값은 맞고 단위만 틀린 금액이 나간다.
   */
  const ORDER_CONTEXT = {
    id: 1024,
    order_number: '20260827-0001',
    order_status: 'delivered',
    is_partially_cancelled: false,
    base_currency: 'KRW',
    payment_currency: 'KRW',
    is_cross_currency: false,
    payments: [
      {
        payment_number: 'PAY20260827000001',
        payment_type_label: '주문결제',
        payment_method: 'card',
        payment_method_label: '신용카드',
        payment_status: 'paid',
        payment_status_label: '결제완료',
        payment_status_variant: 'success',
        account_info: null,
        is_cross_currency: false,
        payment_currency: 'KRW',
        requested_at_formatted: '2026-08-20 10:00',
        paid_at_formatted: '2026-08-20 10:01',
        due_date_formatted: null,
        paid_amount_formatted: '68,000원',
        event_discount_amount: 0,
        event_discount_amount_formatted: '0원',
        referral_discount_amount: 0,
        referral_discount_amount_formatted: '0원',
      },
    ],
    subtotal_amount_formatted: '68,000원',
    total_shipping_amount_formatted: '3,000원',
    total_discount_amount: 0,
    total_discount_amount_formatted: '0원',
    total_points_used_amount: 0,
    total_points_used_amount_formatted: '0원',
    total_deposit_used_amount: 0,
    total_deposit_used_amount_formatted: '0원',
    total_paid_amount_formatted: '71,000원',
    total_cancelled_amount: 0,
    total_cancelled_amount_formatted: '0원',
    total_refunded_amount: 29000,
    total_refunded_amount_formatted: '29,000원',
    total_refunded_points_amount: 0,
    total_refunded_points_amount_formatted: '0원',
    total_tax_amount_formatted: '64,545원',
    total_vat_amount_formatted: '6,455원',
    total_earned_points_amount: 0,
  };

  /** 취소 가능 금액 산정 결과 (D19) — 두 상한과 참고 축(선점), 그리고 한도를 정한 축이 함께 온다 */
  const CANCELLABLE = {
    amount: 24000,
    amount_formatted: '24,000원',
    domain_refundable: 29000,
    domain_refundable_formatted: '29,000원',
    payment_capacity: 24000,
    payment_capacity_formatted: '24,000원',
    reserved: 5000,
    reserved_formatted: '5,000원',
    binding: 'payment_capacity',
    binding_label_key: 'sirsoft-ecommerce::messages.claims.cancellable_binding.payment_capacity',
    binding_label: '결제수단 잔여 한도가 한도입니다.',
    currency: 'KRW',
    methods: [
      {
        type: 'payment',
        payment_id: 88,
        method: 'card',
        method_label: '신용카드',
        currency: 'KRW',
        remaining_local: 24000,
        remaining_base: 24000,
        remaining_local_formatted: '24,000원',
        remaining_base_formatted: '24,000원',
      },
    ],
    reserved_claims: [
      { type: 'refund', type_label: '환불', id: 12, number: 'RF20260825000003', amount: 5000, amount_formatted: '5,000원' },
    ],
    mc_amount: { KRW: 24000 },
  };

  /**
   * 환불신청서 상세 응답을 만든다.
   *
   * @param envelope 봉투 축 덮어쓰기
   * @returns 데이터소스 응답
   */
  function refundResponse(envelope: Node = {}) {
    return claimResponse(
      {
        claim_type: 'refund',
        claim_type_label: '환불',
        claim_number: 'RF20260827000012',
        status: 'approved',
        status_label: '환불승인',
        refund_method: 'pg',
        refund_method_label: 'PG사 환불',
        refund_amount: 24000,
        refund_amount_formatted: '24,000원',
        refund_points_amount: 0,
        refund_points_amount_formatted: '0원',
        refund_shipping_amount: 0,
        refund_shipping_amount_formatted: '0원',
        refund_total: 24000,
        refund_total_formatted: '24,000원',
        refunded_at_formatted: null,
        currency_code: 'KRW',
        items: [],
      },
      {
        claim_path_segment: 'refunds',
        carriers: [],
        redelivery_address_differs: null,
        abilities: { can_update_status: true, can_update_amounts: true, can_execute_refund: true },
        order: ORDER_CONTEXT,
        settlement: null,
        charge_methods: [],
        refund_methods: [
          { value: 'pg', label: 'PG사 환불' },
          { value: 'bank', label: '무통장 환불' },
          { value: 'points', label: '마일리지 환불' },
        ],
        cancellable: CANCELLABLE,
        ...envelope,
      },
    );
  }

  /**
   * 교환 상세 응답에 결제 정보 축을 얹는다.
   *
   * @param envelope 봉투 축 덮어쓰기
   * @returns 데이터소스 응답
   */
  function exchangeWithPayment(envelope: Node = {}) {
    return claimResponse(
      {
        charge_method: 'pay_on_request',
        charge_method_label: '신청 시 결제',
        claim_shipping_fee: 6000,
        claim_shipping_fee_formatted: '6,000원',
        additional_product_amount: 3000,
        additional_product_amount_formatted: '3,000원',
        refund_product_amount: 0,
        refund_product_amount_formatted: '0원',
        total_charge_amount: 9000,
        total_charge_amount_formatted: '9,000원',
        outstanding_amount: 0,
        outstanding_amount_formatted: '0원',
        currency_code: 'KRW',
        payments: [],
      },
      {
        order: ORDER_CONTEXT,
        settlement: {
          refund_amount: 0,
          refund_amount_formatted: '0원',
          refund_points_amount: 0,
          refund_points_amount_formatted: '0원',
          deducted_shipping_fee: 0,
          deducted_shipping_fee_formatted: '0원',
          blocked_reason: 'claim_refund_not_applicable',
          already_filed: false,
          earn_recovery: false,
          currency_code: 'KRW',
        },
        charge_methods: [
          { value: 'pay_on_request', label: '신청 시 결제' },
          { value: 'deduct_from_refund', label: '환불금 차감' },
        ],
        refund_methods: [],
        cancellable: null,
        ...envelope,
      },
    );
  }

  /**
   * 환불 실행은 환불신청서에만 있는 축이다 — 교환·반품에 그리면 누를 수 있는 컨트롤이
   * 언제나 실패한다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_refund_execute_section_absent_for_non_refund
   */
  it('교환 유형에서는 환불 실행 섹션이 렌더되지 않는다', async () => {
    const utils = await renderProbe(paymentSection as Node, exchangeWithPayment());

    expect(document.querySelector('#section_payment_info')).not.toBeNull();
    expect(document.querySelector('#section_refund_execute')).toBeNull();

    utils.cleanup();
  });

  /**
   * 환불에는 청구 축이 없고 교환·반품에는 환불 실행 축이 없다 — 정산 블록도 같은 축으로
   * 갈린다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_settlement_blocks_split_by_type
   */
  it('환불 유형에서는 환불 실행 섹션과 환불 요약이 렌더된다', async () => {
    const utils = await renderProbe(paymentSection as Node, refundResponse());

    expect(document.querySelector('#section_refund_execute')).not.toBeNull();
    expect(document.querySelector('#cst_refund_block')).not.toBeNull();
    expect(document.querySelector('#cst_charge_block')).toBeNull();
    expect(screen.getByText('24,000원', { selector: '#cst_012_refund_total > *' })).toBeInTheDocument();

    utils.cleanup();
  });

  /**
   * 세 축과 최종 금액은 서버가 산정하고 서버가 표기한다 — 화면이 다시 더하면 실행 가드가
   * 쓰는 값과 화면이 예고하는 값이 갈린다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_cancellable_axes_come_from_server
   */
  it('취소 가능 금액 3축·합계·한도 사유가 서버 값 그대로 렌더된다', async () => {
    const utils = await renderProbe(paymentSection as Node, refundResponse());

    expect(document.querySelector('#cre_001_domain_refundable')?.textContent).toContain('29,000원');
    expect(document.querySelector('#cre_002_payment_capacity')?.textContent).toContain('24,000원');
    expect(document.querySelector('#cre_004_reserved')?.textContent).toContain('5,000원');
    expect(document.querySelector('#cre_006_cancellable_amount')?.textContent).toBe('24,000원');
    expect(document.querySelector('#cre_007_binding_label')?.textContent).toBe(
      '결제수단 잔여 한도가 한도입니다.',
    );
    // 선점 근거 — 한도를 깎지는 않지만(참고 축), 어느 신청서가 얼마를 환불할 예정인지
    // 보이지 않으면 운영자가 처리 순서를 정할 근거가 없다.
    expect(document.querySelector('#cre_005_reserved_claim_0')?.textContent).toContain('RF20260825000003');

    utils.cleanup();
  });

  /**
   * 산정 불가와 "0원" 은 다른 사실이다 — 뭉뚱그리면 운영자가 되돌릴 것이 없다고 읽는다.
   *
   * @scenario claim_type=refund, cancellable=absent
   * @effects claim_cancellable_unavailable_distinguished_from_zero
   */
  it('취소 가능 금액이 없으면 산정 불가 안내만 렌더된다', async () => {
    const utils = await renderProbe(paymentSection as Node, refundResponse({ cancellable: null }));

    expect(document.querySelector('#cre_000_cancellable_unavailable')).not.toBeNull();
    expect(document.querySelector('#cre_cancellable_body')).toBeNull();
    expect(document.querySelector('#cre_006_cancellable_amount')).toBeNull();

    utils.cleanup();
  });

  /**
   * 결제 카드는 원 주문의 결제 사실이다 — 값은 전부 상세 봉투가 나른 서버 표기다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_payment_reads_detail_envelope_only
   */
  it('결제 카드가 상세 봉투의 주문 금액 표기를 그대로 그린다', async () => {
    const utils = await renderProbe(paymentSection as Node, exchangeWithPayment());

    expect(document.querySelector('#cp_001_order_amount_0')?.textContent).toContain('68,000원');
    expect(document.querySelector('#cp_002_final_amount_0')?.textContent).toBe('71,000원');
    // 클레임 발 환불이 누적되면 그 행이 비로소 렌더된다 (§4-3-1).
    expect(document.querySelector('#cp_004_refunded_amount_0')?.textContent).toContain('29,000원');
    expect(document.querySelector('#cp_000_no_payment')).toBeNull();

    utils.cleanup();
  });

  /**
   * 결제행이 없는 주문(전액 할인 등)에서 카드가 통째로 사라지면 운영자는 "아직 안 불러왔다"
   * 와 "결제 건이 없다" 를 구분할 수 없다.
   *
   * @effects claim_payment_shows_empty_notice
   */
  it('결제 건이 없으면 안내가 렌더된다', async () => {
    const utils = await renderProbe(
      paymentSection as Node,
      exchangeWithPayment({ order: { ...ORDER_CONTEXT, payments: [] } }),
    );

    expect(document.querySelector('#cp_000_no_payment')).not.toBeNull();
    expect(document.querySelector('#cp_001_order_amount_0')).toBeNull();

    utils.cleanup();
  });

  /**
   * 접수 불가 사유 둘은 뜻이 다르다 — 이미 접수됐거나(다른 신청서가 맡았다), 애초에 환불
   * 대상이 없다. 감추면 0원 예고가 정상으로 보인다.
   *
   * @scenario claim_type=return, cancellable=absent
   * @effects claim_settlement_blocked_reason_shown
   */
  it('완료 시 환불 예정 블록이 접수 불가 사유를 구분해 알린다', async () => {
    const notApplicable = await renderProbe(paymentSection as Node, exchangeWithPayment());
    expect(document.querySelector('#cst_008_blocked_notice')?.textContent).toBe(
      'sirsoft-ecommerce.admin.claim.detail.settlement.blocked_not_applicable',
    );
    notApplicable.cleanup();

    const alreadyFiled = await renderProbe(
      paymentSection as Node,
      exchangeWithPayment({
        settlement: {
          refund_amount: 0,
          refund_amount_formatted: '0원',
          refund_points_amount: 0,
          refund_points_amount_formatted: '0원',
          deducted_shipping_fee: 0,
          deducted_shipping_fee_formatted: '0원',
          blocked_reason: 'claim_refund_request_exists',
          already_filed: true,
          earn_recovery: false,
          currency_code: 'KRW',
        },
      }),
    );
    expect(document.querySelector('#cst_008_blocked_notice')?.textContent).toBe(
      'sirsoft-ecommerce.admin.claim.detail.settlement.blocked_already_filed',
    );
    alreadyFiled.cleanup();
  });

  /**
   * 금액 수정 게이트는 `claims.update-amounts` 다 — 그 권한이 없으면 입력이 잠긴다.
   * 버튼만 잠그고 입력을 열어 두면 운영자가 고친 값이 저장되지 않은 채 화면에만 남는다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_amount_controls_gate_on_update_amounts
   */
  it('금액 수정 권한이 없으면 환불액 입력이 잠긴다', async () => {
    const utils = await renderProbe(
      paymentSection as Node,
      refundResponse({
        abilities: { can_update_status: true, can_update_amounts: false, can_execute_refund: true },
      }),
    );

    expect(document.querySelector('#cre_008_pg_amount')).toBeDisabled();
    expect(document.querySelector('#cre_009_points_amount')).toBeDisabled();
    expect(document.querySelector('#cre_013_save_amounts')).toBeDisabled();
    // 실행 게이트는 다른 권한이다 — 한쪽으로 묶으면 다른 쪽이 열리거나 잠긴다.
    expect(document.querySelector('#cre_014_execute_button')).not.toBeDisabled();

    utils.cleanup();
  });
});
