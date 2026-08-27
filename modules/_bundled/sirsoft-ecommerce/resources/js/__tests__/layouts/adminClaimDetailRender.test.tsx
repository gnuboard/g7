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
    quantity: 1,
    original_quantity: 2,
    unit_price_formatted: '29,000원',
    subtotal_amount_formatted: '29,000원',
    new_unit_price_formatted: '32,000원',
    price_difference: 3000,
    price_difference_formatted: '3,000원',
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
    price_difference: 0,
    price_difference_formatted: '0원',
    status_label: '교환회수중',
    status_variant: 'info',
    thumbnail_url: null,
  },
];

/** 진행단계 (서버가 만든 배열 — 청구액이 없는 교환이라 결제 두 칸이 빠져 있다) */
const PROGRESS_STEPS = [
  { value: 'requested', label: '교환신청', state: 'done' },
  { value: 'approved', label: '교환승인', state: 'done' },
  { value: 'collection_ready', label: '교환회수준비중', state: 'done' },
  { value: 'collecting', label: '교환회수중', state: 'current' },
  { value: 'collected', label: '교환회수도착', state: 'upcoming' },
  { value: 'redelivery_ready', label: '교환발송준비중', state: 'upcoming' },
  { value: 'redelivering', label: '교환발송중', state: 'upcoming' },
  { value: 'redelivered', label: '교환발송도착', state: 'upcoming' },
  { value: 'completed', label: '교환완료', state: 'upcoming' },
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
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_items_grid_renders_server_formatted_amounts
   */
  it('상품 표가 서버 표기 문자열을 그대로 그린다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    const rows = screen.queryAllByTestId(GRID_ROW_TESTID);
    expect(rows).toHaveLength(2);

    const amount = rows[0].querySelector('[data-field="amount"]')?.textContent ?? '';
    expect(amount).toContain('29,000원');
    expect(amount).toContain('32,000원');

    const quantity = rows[0].querySelector('[data-field="quantity"]')?.textContent ?? '';
    expect(quantity).toBe('1 / 2');
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
    expect(rows[0].querySelector('[data-field="amount"]')?.textContent).toContain('32,000원');
    expect(rows[0].querySelector('[data-field="product_info"]')?.textContent).toContain('블랙 / L');

    // 동일 옵션 교환 행: 서버가 값을 채워 보내도 화살표 줄이 없다.
    expect(rows[1].querySelector('[data-field="amount"]')?.textContent).not.toContain('→');
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
