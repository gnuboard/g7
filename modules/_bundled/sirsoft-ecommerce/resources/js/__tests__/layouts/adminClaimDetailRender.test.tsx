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
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import itemsSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_items.json';
import reasonSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.json';
import shippingSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json';
import reasonBlock from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.json';
import paymentSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.json';
import detailLayout from '../../../layouts/admin/admin_ecommerce_claim_detail.json';

type Node = Record<string, any>;

const GRID_ROW_TESTID = 'claim-item-row';

/** 회수 운송장 (두 행이 한 상자로 나갔다 — 같은 운송장을 두 행에 적는다) */
const COLLECTION_WAYBILL = {
  carrier_id: 3,
  carrier_name: 'CJ대한통운',
  tracking_number: '612345678901',
  tracking_url: 'https://trace.cjlogistics.com/next/tracking.html?wblNo=612345678901',
};

/**
 * 교환 상품 행의 송장 필드를 만든다. (`ClaimItemResource::trackingFields()` 와 같은 키)
 *
 * 송장은 **상품 행**에 있다 — 신청서 헤더에는 송장 칸이 없다.
 *
 * @param overrides 덮어쓸 필드
 * @returns 행 송장 필드
 */
function exchangeRowTracking(overrides: Node = {}): Node {
  return {
    collection_carrier_id: COLLECTION_WAYBILL.carrier_id,
    collection_carrier_name: COLLECTION_WAYBILL.carrier_name,
    collection_tracking_number: COLLECTION_WAYBILL.tracking_number,
    collection_tracking_url: COLLECTION_WAYBILL.tracking_url,
    collection_shipped_at_formatted: '2026-08-28 09:00',
    collected_at_formatted: null,
    collection_state: 'in_transit',
    collection_state_label: '회수중',
    collection_state_variant: 'info',
    is_stock_restored: false,
    is_stock_restorable: false,
    redelivery_carrier_id: null,
    redelivery_carrier_name: null,
    redelivery_tracking_number: null,
    redelivery_tracking_url: null,
    redelivery_shipped_at_formatted: null,
    redelivered_at_formatted: null,
    is_unreturned: false,
    unreturned_at_formatted: null,
    unreturned_reason: null,
    ...overrides,
  };
}

/** 교환 신청 상품 두 행 (하나는 옵션 변경, 하나는 동일 옵션) */
const EXCHANGE_ITEMS = [
  {
    ...exchangeRowTracking(),
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
    ...exchangeRowTracking(),
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

/**
 * 운송장 묶음 (`ClaimTrackingGroups::for($claim, forAdmin: true)` 와 같은 모양)
 *
 * 회수는 한 운송장에 두 행이 담긴 한 묶음이고 아직 도착 전이라 [도착 처리] 가 켜진다. 재발송은 아직 없다.
 */
const TRACKING_GROUPS = {
  collection: [
    {
      key: `collection:${COLLECTION_WAYBILL.carrier_id}|${COLLECTION_WAYBILL.tracking_number}`,
      direction: 'collection',
      no_tracking: false,
      ...COLLECTION_WAYBILL,
      items: [
        { claim_item_id: 55, product_name: '프리미엄 코튼 티셔츠', option_name: '차콜 / M', quantity: 2, status_label: '교환회수중', arrived: false },
        { claim_item_id: 56, product_name: '소프트 터치 니트', option_name: '아이보리 / L', quantity: 1, status_label: '교환회수중', arrived: false },
      ],
      all_arrived: false,
      can_deliver: true,
      blocked_reason: null,
    },
  ],
  redelivery: [],
};

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
        // 송장은 상품 행(`items[]`)에 있고, 배송 정보 탭은 운송장별 묶음(`tracking_groups`)을 그린다 —
        // 헤더에는 송장 칸이 없다(헤더에 남은 것은 신청서 전체의 도착 시각뿐이다).
        tracking_groups: TRACKING_GROUPS,
        return_addresses: [],
        collection_completed_at_formatted: null,
        redelivered_at_formatted: null,
        exchange_mode: 'standard',
        exchange_mode_label: '일반 교환',
        is_advance_exchange: false,
        collection_due_date_formatted: null,
        is_collection_overdue: false,
        collection_overdue_days: 0,
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

  // 첨부 업로더 — 이 테스트가 보는 축은 '섰는가 · 잠겼는가 · 몇 장을 받았는가' 뿐이다.
  registry.register('composite', 'FileUploader', ({ id, disabled, initialFiles }: any) =>
    React.createElement('div', {
      id,
      'data-testid': 'claim-file-uploader',
      'data-disabled': String(!!disabled),
      'data-initial-count': String((initialFiles ?? []).length),
    })
  );

  // 뱃지는 '어떤 뱃지가 켜졌는가' 만 보므로 레이아웃 id 를 그대로 내보낸다.
  registry.register('composite', 'Badge', ({ id, variant, color, text, children }: any) =>
    React.createElement(
      'span',
      // `color` 는 Badge 가 `variant` 보다 **먼저** 적용하는 prop 이다 — 둘 중 하나만
      // 내보내면 그 쪽을 안 쓰는 화면의 뱃지가 전부 같은 모양으로 측정된다.
      { id, 'data-testid': 'claim-badge', 'data-variant': variant, 'data-color': color },
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
const CLAIM_DETAIL_PARTIALS_DIR = path.resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_claim_detail');

/**
 * partial 안의 partial 참조 문자열을 모은다 (배열·객체 재귀).
 */
function collectPartialRefs(node: any, out: string[] = []): string[] {
  if (Array.isArray(node)) node.forEach((child) => collectPartialRefs(child, out));
  else if (node && typeof node === 'object') {
    if (typeof node.partial === 'string') out.push(node.partial);
    Object.values(node).forEach((value) => collectPartialRefs(value, out));
  }
  return out;
}

describe('[case:backend-43] 클레임 상세 partial 안의 partial 참조 — 같은 디렉터리 기준 상대 경로 (서버 병합기 규약)', () => {
  // 회귀(2026-09-11 관리자 화면 실측, #599 후속): `partials/admin_ecommerce_claim_detail/…` 로 적은 참조는
  // 서버 병합기(ValidatesLayoutFiles::resolvePartialPath)가 partial 디렉터리 기준으로 못 찾고 layouts 루트
  // (admin/ 없이)로 되돌려 다시 찾다 실패한다 — 운영(APP_DEBUG=false)에서는 빈 배열로 조용히 사라져
  // 정산 카드·취소 카드의 주문금액 비교가 한 번도 그려지지 않았다. 최상위 레이아웃의 참조만 `partials/…` 다.
  /**
   * @scenario claim_type=exchange, claim_stage=final
   * @effects admin_claim_detail_nested_partial_refs_are_sibling_relative
   */
  it('partial 파일 전건의 partial 참조가 `partials/` 로 시작하지 않고 같은 디렉터리에 실재한다', () => {
    const files = fs.readdirSync(CLAIM_DETAIL_PARTIALS_DIR).filter((name) => name.endsWith('.json'));
    expect(files.length).toBeGreaterThan(0);

    for (const name of files) {
      const json = JSON.parse(fs.readFileSync(path.resolve(CLAIM_DETAIL_PARTIALS_DIR, name), 'utf-8'));
      for (const ref of collectPartialRefs(json)) {
        expect(ref, `${name} → ${ref}`).not.toMatch(/^partials[\\/]/);
        expect(fs.existsSync(path.resolve(CLAIM_DETAIL_PARTIALS_DIR, ref)), `${name} → ${ref} 파일 없음`).toBe(true);
      }
    }
  });

  /**
   * @scenario claim_type=exchange, claim_stage=final
   * @effects admin_claim_detail_nested_partial_refs_are_sibling_relative
   */
  it('정산 카드와 취소 신청서 카드가 같은 비교표 partial 을 형제 경로로 싣는다', () => {
    expect(collectPartialRefs(paymentSection)).toContain('_partial_claim_comparison.json');
    expect(collectPartialRefs(reasonBlock)).toContain('_partial_claim_comparison.json');
  });
});

/**
 * 비교표 partial 참조만 파일에서 읽어 인라인한다 — 하네스는 `{ "partial": … }` 을 해석하지 않는다.
 * 다른 partial 참조는 종전대로 두어(렌더되지 않음) 기존 단언의 전제를 바꾸지 않는다.
 */
function inlinePartials(node: any): any {
  if (Array.isArray(node)) return node.map(inlinePartials);
  if (node && typeof node === 'object') {
    if (typeof node.partial === 'string' && node.partial.endsWith('_partial_claim_comparison.json')) {
      // partial 안의 partial 은 같은 디렉터리 기준 상대 경로다(서버 병합기 규약) — 참조 문자열이 아니라
      // 그 디렉터리에서 파일명으로 읽는다.
      const file = path.resolve(CLAIM_DETAIL_PARTIALS_DIR, path.basename(node.partial));
      const { meta, ...partialNode } = JSON.parse(fs.readFileSync(file, 'utf-8'));
      return inlinePartials(partialNode);
    }
    const out: Record<string, any> = {};
    for (const [key, value] of Object.entries(node)) out[key] = inlinePartials(value);
    return out;
  }
  return node;
}

function probe(node: Node, localState: Record<string, unknown> = {}): Node {
  const { meta, ...rest } = inlinePartials(node);

  return {
    version: '1.0.0',
    layout_name: 'test/admin-claim-detail',
    // 셸이 하는 것과 같은 방식으로 `_local` 을 깐다 — 선택 상태에만 나타나는 분기
    // (교집합 0건 안내 등)는 이 씨앗 없이는 렌더 축에서 잴 수 없다.
    ...(Object.keys(localState).length > 0
      ? { init_actions: [{ handler: 'setState', params: { target: '_local', ...localState } }] }
      : {}),
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
async function renderProbe(
  node: Node,
  response: Record<string, unknown> = claimResponse(),
  localState: Record<string, unknown> = {}
) {
  const utils = createLayoutTest(probe(node, localState) as any, {
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
    // 반품 행에는 재발송 키가 없다(`ClaimItemResource` 는 교환 행에만 싣는다) — 묶음도 회수뿐이다.
    const returnItems = EXCHANGE_ITEMS.map((item) => Object.fromEntries(
      Object.entries(item).filter(([key]) => !key.startsWith('redelivery_') && !key.startsWith('redelivered_')
        && !key.startsWith('unreturned_') && key !== 'is_unreturned'),
    ));
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse(
        { claim_type: 'return', items: returnItems, tracking_groups: { collection: TRACKING_GROUPS.collection, redelivery: [] } },
        { claim_path_segment: 'returns' }
      )
    );

    expect(document.querySelector('[data-section-id="shipping_info"]')).not.toBeNull();
    expect(document.getElementById('claim_collection_block')).not.toBeNull();
    expect(document.getElementById('cs_006_collection_group_0')).not.toBeNull();
    expect(document.getElementById('cs_008_redelivery_na_text')).not.toBeNull();
    // 재발송 묶음·빈 안내는 교환 전용 블록 안에만 있다.
    expect(document.getElementById('claim_redelivery_block')).toBeNull();
    expect(document.getElementById('cs_014_redelivery_empty')).toBeNull();
    expect(document.querySelector('[id^="cs_015_redelivery_group_"]')).toBeNull();
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

describe('배송 정보 탭 — 운송장 묶음 렌더 (상품 행 송장 · R9)', () => {
  /**
   * 한 운송장에 두 행이 담긴 회수 묶음이 **한 줄**로 서고, 택배사·운송장·서버 조회 링크·담긴 상품 × 수량을
   * 그린다. 재발송은 아직 묶음이 없어 빈 안내가 선다. 이 탭에는 입력칸이 없다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_shipping_renders_waybill_group_rows_and_link
   */
  it('회수 운송장 묶음 하나가 택배사·운송장·조회 링크·상품 × 수량으로 선다', async () => {
    const utils = await renderProbe(shippingSection as Node);

    const group = document.getElementById('cs_006_collection_group_0');
    expect(group).not.toBeNull();
    expect(document.getElementById('cs_006_collection_group_1')).toBeNull();
    expect(document.getElementById('cs_005_collection_empty')).toBeNull();

    const text = group?.textContent ?? '';
    expect(text).toContain('CJ대한통운');
    expect(text).toContain('612345678901');
    expect(text).toContain('프리미엄 코튼 티셔츠 (차콜 / M) × 2');
    expect(text).toContain('소프트 터치 니트 (아이보리 / L) × 1');
    expect(group?.querySelector('a')?.getAttribute('href')).toBe(COLLECTION_WAYBILL.tracking_url);

    // 도착 전이고 서버가 옮길 수 있다고 판정했다 — [도착 처리] 가 켜지고 막힌 이유 문구가 없다.
    const deliver = group?.querySelector('button');
    expect(deliver).not.toBeNull();
    expect(deliver?.hasAttribute('disabled')).toBe(false);

    // 재발송 묶음은 아직 없다.
    expect(document.getElementById('cs_014_redelivery_empty')).not.toBeNull();
    expect(document.querySelector('[id^="cs_015_redelivery_group_"]')).toBeNull();
    expect(document.querySelectorAll('#claim_shipping_card input, #claim_shipping_card select')).toHaveLength(0);
    utils.cleanup();
  });

  /**
   * 서버가 이 묶음을 옮길 수 없다고 판정하면(이미 도착 · 상태 불가) [도착 처리] 가 꺼지고 그 이유를 버튼 옆에
   * 적는다 — 꺼진 버튼만 두면 운영자는 권한 문제로 읽는다. 도착한 묶음은 「도착」 뱃지를 단다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_shipping_deliver_disabled_with_reason_when_server_blocks
   */
  it('`can_deliver` 가 거짓이면 [도착 처리] 가 꺼지고 막힌 이유가 곁에 선다', async () => {
    const arrived = {
      ...TRACKING_GROUPS.collection[0],
      items: TRACKING_GROUPS.collection[0].items.map((item) => ({ ...item, arrived: true })),
      all_arrived: true,
      can_deliver: false,
      blocked_reason: '이미 도착 처리된 운송장입니다.',
    };
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse({ tracking_groups: { collection: [arrived], redelivery: [] } })
    );

    const group = document.getElementById('cs_006_collection_group_0');
    expect(group?.querySelector('button')?.hasAttribute('disabled')).toBe(true);
    expect(group?.textContent ?? '').toContain('이미 도착 처리된 운송장입니다.');
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

describe('재발송지 주소 — 해외 주문 (S2-D8)', () => {
  /**
   * 해외 재발송지는 도시·주를 따로 담는다 — 운영자가 보는 주소에서 빠지면 교환품을 보낼 수 없다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_redelivery_address_shows_the_international_city_and_state
   */
  it('재발송지 주소 줄에 도시·주가 함께 나온다', async () => {
    const utils = await renderProbe(
      shippingSection as Node,
      claimResponse({
        redelivery_address_snapshot: {
          recipient_name: 'Jane Doe',
          phone: '+1-212-555-0101',
          zipcode: '10118',
          address1: '350 5th Ave',
          address2: 'Suite 2',
          city: 'New York',
          state: 'NY',
          country_code: 'US',
          changed_by_user: false,
        },
      })
    );

    expect(document.getElementById('cs_012_redelivery_address')?.textContent ?? '').toContain('10118 350 5th Ave Suite 2 New York NY');
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
   * 환불에도 상품 단위 전이 축이 열렸다 (§4) — 승인·보류·반려를 상품마다 가를 수 있다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_item_bulk_bar_present_for_refund_render
   */
  it('환불 유형에도 상품 단위 일괄 바가 선다', async () => {
    const utils = await renderProbe(
      itemsSection as Node,
      claimResponse({ claim_type: 'refund' }, { claim_path_segment: 'refunds' })
    );

    expect(document.getElementById('claim_item_bulk_bar')).not.toBeNull();
    expect(screen.getByTestId('claim-items-grid').getAttribute('data-selectable')).toBe('true');
    utils.cleanup();
  });

  /**
   * 취소만 빠진다 — 생성이 곧 확정이라 옮길 상태가 없다.
   *
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_item_bulk_bar_absent_for_cancel_render
   */
  it('취소 유형에서는 상품 단위 일괄 바가 사라진다', async () => {
    const utils = await renderProbe(
      itemsSection as Node,
      claimResponse({ claim_type: 'cancel' }, { claim_path_segment: '' })
    );

    expect(document.getElementById('claim_item_bulk_bar')).toBeNull();
    expect(screen.getByTestId('claim-items-grid').getAttribute('data-selectable')).toBe('false');
    utils.cleanup();
  });

  /**
   * 고른 상품들이 **함께 갈 수 있는 상태가 하나도 없으면** 셀렉트를 감추고 이유를 적는다.
   *
   * 빈 셀렉트만 남기면 운영자는 화면이 덜 그려진 줄 알고 새로고침을 반복한다. 구조만 맞고
   * 렌더가 비면 아무 글자도 나오지 않으므로, 이 축은 렌더에서 재야 한다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_item_no_common_transition_notice_render
   */
  it('교집합이 0건이면 상태 셀렉트 대신 안내가 선다', async () => {
    const utils = await renderProbe(itemsSection as Node, claimResponse(), {
      selectedClaimItems: [101, 102],
      itemTransitionOptions: [],
    });

    expect(document.getElementById('ci_002_item_status')).toBeNull();

    // 안내는 실제로 그려진다(빈 노드가 아니다). 프로브는 모듈 언어팩을 싣지 않으므로
    // 문구 자체가 아니라 **노드가 서는지**를 잰다 — 문구의 ko/en 실재는 구조 테스트가 맡는다.
    const notice = document.getElementById('ci_003_no_common_transition');
    expect(notice).not.toBeNull();
    expect(notice!.textContent?.trim()).not.toBe('');
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

describe('신청서 단위 전이 바 · 진행 일시 (#599 후속 F1·F16)', () => {
  /**
   * 상세에 신청서 단위 전이 진입점이 통째로 없던 결함(F1).
   *
   * 승인·반려·보류는 **목록의 일괄 바**에서만 가능했다 — 운영자는 상세를 열어 내용을 확인한
   * 뒤 목록으로 되돌아가 그 줄을 다시 찾아야 했고, 환불 유형은 상세에 어떤 전이 컨트롤도 없어
   * [환불하기]가 요구하는 `approved` 에 도달할 방법이 그 화면에 없었다(F2 와 겹친 막다른 길).
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_detail_has_a_status_transition_bar
   */
  it('서버가 준 전이 선택지가 있으면 전이 바가 선다', async () => {
    const utils = await renderProbe(
      itemsSection as Node,
      claimResponse(
        { claim_type: 'refund', claim_type_label: '환불', status: 'requested', status_label: '환불신청' },
        {
          claim_path_segment: 'refunds',
          transition_options: [
            { value: 'approved', label: '환불승인' },
            { value: 'rejected', label: '환불반려' },
          ],
        }
      )
    );

    expect(document.getElementById('claim_status_transition_card')).not.toBeNull();
    expect(document.getElementById('cts_001_status_select')).not.toBeNull();
    expect(document.getElementById('cts_003_apply')).not.toBeNull();
    utils.cleanup();
  });

  /**
   * 전이할 곳이 없으면 바를 세우지 않는다 — 취소신청서는 전이 축이 없다(생성이 곧 확정이다).
   *
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_detail_hides_the_transition_bar_without_targets
   */
  it('전이 선택지가 비어 있으면 전이 바가 서지 않는다', async () => {
    const utils = await renderProbe(
      itemsSection as Node,
      claimResponse(
        { claim_type: 'cancel', claim_type_label: '취소', status: 'completed', status_label: '취소완료' },
        { claim_path_segment: 'cancels', transition_options: [] }
      )
    );

    expect(document.getElementById('claim_status_transition_card')).toBeNull();
    utils.cleanup();
  });

  /**
   * 승인·반려·철회 **일시**가 상세에 보인다 (F16).
   *
   * 리소스는 세 값을 처음부터 내보냈는데 어느 화면도 읽지 않아, 운영자는 이 신청서가 **언제**
   * 승인·반려됐는지 화면 어디에서도 알 수 없었다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_detail_shows_approval_rejection_withdrawal_timestamps
   */
  it('승인 일시가 있으면 진행 카드에 표시된다', async () => {
    const utils = await renderProbe(
      itemsSection as Node,
      claimResponse({ approved_at_formatted: '2026-09-11 14:05' })
    );

    const block = document.getElementById('claim_progress_timestamps');
    expect(block).not.toBeNull();
    expect(block?.textContent).toContain('2026-09-11 14:05');
    utils.cleanup();
  });

  /**
   * 값이 하나도 없으면 빈 칸을 세우지 않는다 — 아직 일어나지 않은 일이 '기록 누락' 으로 읽힌다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_detail_omits_empty_progress_timestamps
   */
  it('일시가 하나도 없으면 그 블록이 서지 않는다', async () => {
    const utils = await renderProbe(itemsSection as Node);

    expect(document.getElementById('claim_progress_timestamps')).toBeNull();
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

describe('취소 상세 — 조회 전용 (취소 정보 카드 · 메모 안내 · 배송 섹션 없음)', () => {
  /** 취소 신청서 응답 — 취소 행 + 취소 발 환불 행의 값이 한 신청서에 실린다. */
  function cancelResponse(overrides: Node = {}) {
    return claimResponse(
      {
        claim_number: 'CN20260902-000009',
        claim_type: 'cancel',
        claim_type_label: '취소',
        status: 'completed',
        status_label: '취소완료',
        status_variant: 'success',
        is_final: true,
        cancel_type: 'partial',
        cancel_type_label: '부분취소',
        refund_status: 'completed',
        refund_status_label: '환불완료',
        refund_amount_formatted: '12,000원',
        cancelled_at_formatted: '2026-09-02 20:34',
        admin_memo: null,
        reject_reason: null,
        items: [{ ...EXCHANGE_ITEMS[1], is_option_change: false }],
        ...overrides,
      },
      {
        claim_path_segment: null,
        carriers: [],
        progress_steps: [
          { value: 'requested', label: '취소신청', state: 'done', icon: 'status-request', variant: 'secondary' },
          { value: 'completed', label: '취소완료', state: 'current', icon: 'status-completed', variant: 'success' },
        ],
      }
    );
  }

  /**
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_detail_cancel_info_card_rendered
   */
  it('취소 정보 카드가 서버 값을 그대로 그린다', async () => {
    const utils = await renderProbe(reasonBlock as Node, cancelResponse());

    expect(document.getElementById('claim_cancel_info_card')).not.toBeNull();
    expect(document.getElementById('cc_001_cancel_type')?.textContent).toBe('부분취소');
    expect(document.getElementById('cc_002_refund_status')?.textContent).toBe('환불완료');
    expect(document.getElementById('cc_003_refund_amount')?.textContent).toBe('12,000원');
    expect(document.getElementById('cc_004_cancelled_at')?.textContent).toBe('2026-09-02 20:34');
    utils.cleanup();
  });

  /**
   * 재활성 기록이 있으면 머리 뱃지와 취소 정보 카드의 재활성 줄이 서고, 없으면 둘 다 없다(Q29).
   *
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_detail_header_shows_reactivated_badge, claim_detail_cancel_card_shows_reactivated_time_and_quantity
   */
  it('재활성 뱃지와 취소 카드 재활성 줄', async () => {
    const findById = (node: unknown, id: string): Node | undefined => {
      if (Array.isArray(node)) {
        for (const child of node) {
          const hit = findById(child, id);
          if (hit) return hit;
        }
        return undefined;
      }
      if (node && typeof node === 'object') {
        if ((node as Node).id === id) return node as Node;
        for (const value of Object.values(node as Node)) {
          const hit = findById(value, id);
          if (hit) return hit;
        }
      }
      return undefined;
    };
    const badgeParent = { type: 'basic', name: 'Div', children: [
      findById(detailLayout, 'cd_006b_reactivated_badge'),
      findById(detailLayout, 'cd_006c_reactivated_partial_badge'),
    ] };

    const reactivated = cancelResponse({
      reactivated_at_formatted: '2026-10-05 14:00',
      reactivated_quantity: 1,
      total_cancel_quantity: 2,
      is_partially_reactivated: true,
    });

    const header = await renderProbe(badgeParent as Node, reactivated);
    expect(document.getElementById('cd_006b_reactivated_badge')).toBeNull();
    expect(document.getElementById('cd_006c_reactivated_partial_badge')).not.toBeNull();
    header.cleanup();

    const card = await renderProbe(reasonBlock as Node, reactivated);
    expect(document.getElementById('cc_005_reactivated_row')).not.toBeNull();
    expect(document.getElementById('cc_005_reactivated_at')?.textContent).toContain('reactivated_value');
    expect(document.getElementById('cc_006_reactivated_hint')).not.toBeNull();
    card.cleanup();

    const plain = await renderProbe(reasonBlock as Node, cancelResponse());
    expect(document.getElementById('cc_005_reactivated_row')).toBeNull();
    plain.cleanup();

    const plainHeader = await renderProbe(badgeParent as Node, cancelResponse());
    expect(document.getElementById('cd_006b_reactivated_badge')).toBeNull();
    expect(document.getElementById('cd_006c_reactivated_partial_badge')).toBeNull();
    plainHeader.cleanup();
  });

  /**
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_detail_cancel_is_read_only
   */
  it('취소에는 메모 입력 대신 취소 전용 안내가 나오고, 교환에는 취소 카드가 없다', async () => {
    const utils = await renderProbe(reasonBlock as Node, cancelResponse());

    expect(document.getElementById('cm_003_memo_textarea')).toBeNull();
    expect(document.getElementById('cm_001_memo_unavailable')).toBeNull();
    expect(document.getElementById('cm_001b_memo_unavailable_cancel')).not.toBeNull();
    utils.cleanup();

    const exchange = await renderProbe(reasonBlock as Node);

    expect(document.getElementById('claim_cancel_info_card')).toBeNull();
    expect(document.getElementById('cm_001b_memo_unavailable_cancel')).toBeNull();
    exchange.cleanup();
  });

  /**
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_detail_cancel_is_read_only
   */
  it('취소에서는 배송 섹션이 사라지고 상품 표는 선택 불가다', async () => {
    const shipping = await renderProbe(shippingSection as Node, cancelResponse());

    expect(document.querySelector('[data-section-id="shipping_info"]')).toBeNull();
    shipping.cleanup();

    const items = await renderProbe(itemsSection as Node, cancelResponse());

    expect(screen.getAllByTestId(GRID_ROW_TESTID)).toHaveLength(1);
    expect(document.querySelector('[data-testid="claim-items-grid"]')?.getAttribute('data-selectable')).toBe('false');
    // 진행단계는 서버가 준 두 칸 그대로
    expect(document.querySelectorAll('[id^="claim_progress_step_"]')).toHaveLength(2);
    items.cleanup();
  });
});

describe('접수 시점 주문금액 비교표 — 정산 카드 · 취소 카드 (#599 후속 P8)', () => {
  /** 접수 시점 비교 스냅샷(전/후) — 값은 응답에만 있고 다른 줄에서 만들 수 없는 것으로 고른다. */
  const COMPARISON = {
    original: {
      item_count: 2, total_list_price_amount: 58000, subtotal_amount: 58000, total_paid_amount: 58000,
      total_vat_amount: 5272, total_earned_points_amount: 580, base_currency: 'KRW',
      formatted: { total_list_price_amount: '58,000원', subtotal_amount: '58,000원', total_paid_amount: '58,000원', total_vat_amount: '5,272원' },
    },
    recalculated: {
      item_count: 1, total_list_price_amount: 29000, subtotal_amount: 29000, total_paid_amount: 29000,
      total_vat_amount: 2636, total_earned_points_amount: 290, base_currency: 'KRW',
      formatted: { total_list_price_amount: '29,000원', subtotal_amount: '29,000원', total_paid_amount: '29,000원', total_vat_amount: '2,636원' },
    },
    mc_original: { mc_total_paid_amount_formatted: { KRW: '58,000원', USD: '$49.30' } },
    mc_recalculated: { mc_total_paid_amount_formatted: { KRW: '29,000원', USD: '$24.65' } },
    substituted_items: [],
  };

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects admin_claim_detail_comparison_in_settlement_card
   */
  it("'이 클레임의 정산' 카드 첫머리에 저장 스냅샷 표가 서고 후열 변화 행만 강조된다", async () => {
    const utils = await renderProbe(paymentSection as Node, claimResponse({
      calculation_comparison: {
        ...COMPARISON,
        substituted_items: [{ order_option_id: 310, quantity: 1, product_option_id: 77, unit_price: 29000, new_unit_price: 32000, line_key: -310, option_label: '블랙 / L', unit_price_formatted: '29,000원', new_unit_price_formatted: '32,000원' }],
      },
    }));

    const card = document.getElementById('claim_settlement_card');
    const table = card?.querySelector('[data-testid="claim-detail-comparison-table"]');
    const rows = Array.from(table?.querySelectorAll('[data-row]') ?? []).map((el) => el.getAttribute('data-row'));

    expect(rows).toHaveLength(15);
    expect(rows[rows.length - 1]).toBe('substituted');
    expect(table?.textContent).toContain('5,272원');
    expect(table?.textContent).toContain('2,636원');
    expect(table?.textContent).toContain('$24.65');
    expect(table?.textContent).toContain('29,000원 → 32,000원');

    // 후열 값 = 행의 마지막 셀(Div)의 첫 Span (그 아래 보조통화 줄이 더 있을 수 있다).
    const afterValue = (row: string) => {
      const cells = table!.querySelectorAll(`[data-row="${row}"] > div`);
      return cells[cells.length - 1].querySelector('span') as HTMLElement;
    };
    expect(afterValue('total_paid_amount').className).toContain('text-red-600');
    expect(afterValue('base_shipping_amount').className).not.toContain('text-red-600');
    // 표는 카드 제목 바로 아래 — 정산 숫자의 근거가 먼저 선다. 마운트 Div(유형 가드)를 한 겹 거친다.
    // 두 단언으로 나눈다 — jsdom 은 `h2 + div#id > div[...]` 처럼 형제+자식 결합자를 이어 붙인 셀렉터를
    // 스코프 querySelector 에서 놓친다(각각은 매치된다).
    const mount = card?.querySelector('h2 + div#cst_comparison_mount');
    expect(mount).not.toBeNull();
    expect(mount?.querySelector(':scope > div[data-testid="claim-detail-comparison"]')).not.toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario claim_type=cancel, claim_stage=final
   * @effects admin_claim_settlement_card_hides_comparison_for_cancel
   */
  it('취소 신청서에는 정산 카드가 서지 않는다 (제목만 남은 빈 카드 회귀 — F10)', async () => {
    // 회귀 둘을 한 자리에서 잠근다.
    //   ⓐ 2026-09-11 관리자 화면 실측: 두 카드가 같은 비교표를 무조건 싣자 취소 상세 한 화면에
    //      같은 표가 두 번 섰다. 취소의 표는 취소 정보 카드(_partial_claim_reason)가 소유한다.
    //   ⓑ #599 후속 F10: 카드 안쪽 블록이 전부 취소를 배제하는데 카드에는 `if` 가 없어,
    //      **제목만 남은 빈 카드**가 화면에 섰다. 이제 카드째 서지 않는다.
    const utils = await renderProbe(paymentSection as Node, claimResponse({
      claim_number: 'CN20260902-000009',
      claim_type: 'cancel',
      claim_type_label: '취소',
      status: 'completed',
      calculation_comparison: COMPARISON,
    }));

    expect(document.getElementById('claim_settlement_card')).toBeNull();
    expect(document.querySelector('[data-testid="claim-detail-comparison"]')).toBeNull();
    utils.cleanup();
  });

  /**
   * @scenario claim_type=cancel, claim_stage=final
   * @effects admin_claim_cancel_card_comparison
   */
  it('취소 신청서 카드에도 같은 표가 환불 금액 행 아래에 선다', async () => {
    const utils = await renderProbe(reasonBlock as Node, claimResponse({
      claim_number: 'CN20260902-000009',
      claim_type: 'cancel',
      claim_type_label: '취소',
      status: 'completed',
      status_label: '취소완료',
      is_final: true,
      cancel_type_label: '부분취소',
      refund_status_label: '환불완료',
      refund_amount_formatted: '29,000원',
      cancelled_at_formatted: '2026-09-02 20:34',
      admin_memo: null,
      items: [{ ...EXCHANGE_ITEMS[1], is_option_change: false }],
      calculation_comparison: COMPARISON,
    }, { claim_path_segment: null, carriers: [], progress_steps: [] }));

    const card = document.getElementById('claim_cancel_info_card');
    const table = card?.querySelector('[data-testid="claim-detail-comparison-table"]');

    expect(table).not.toBeNull();
    expect(table?.querySelectorAll('[data-row]')).toHaveLength(14);
    expect(table?.textContent).toContain('claim.detail.comparison.col_before_cancel');
    // 환불 금액 행 다음, 취소 일시 행 앞.
    const order = Array.from(card!.querySelectorAll('[id="cc_003_refund_amount"], [data-testid="claim-detail-comparison"], [id="cc_004_cancelled_at"]'))
      .map((el) => el.getAttribute('data-testid') ?? el.getAttribute('id'));
    expect(order).toEqual(['cc_003_refund_amount', 'claim-detail-comparison', 'cc_004_cancelled_at']);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects admin_claim_detail_comparison_in_settlement_card
   */
  it('스냅샷이 없는 구형 신청서는 안내 한 줄만 선다', async () => {
    const utils = await renderProbe(paymentSection as Node, claimResponse({ calculation_comparison: null }));

    const card = document.getElementById('claim_settlement_card');
    expect(card?.querySelector('[data-testid="claim-detail-comparison-empty"]')).not.toBeNull();
    expect(card?.querySelector('[data-testid="claim-detail-comparison-table"]')).toBeNull();
    expect(card?.querySelectorAll('[data-row]')).toHaveLength(0);
    utils.cleanup();
  });
});

describe('첨부 — 사유 카드의 업로더·출처 스트립 (§6-3 · §6-4)', () => {
  /** 첨부 두 장(고객 1 · 운영자 1)을 실은 상세 응답. */
  const withImages = () =>
    claimResponse(
      {
        images: [
          {
            id: 11,
            original_name: 'customer.jpg',
            original_filename: 'customer.jpg',
            url: '/storage/a.jpg',
            size: 10240,
            size_formatted: '10 KB',
            sort_order: 0,
            order: 0,
            is_image: true,
            uploader_type: 'customer',
            uploader_type_label: '고객',
            uploader_name: '김지은',
            created_at: '2026-09-14 10:00',
          },
          {
            id: 12,
            original_name: 'admin.png',
            original_filename: 'admin.png',
            url: '/storage/b.png',
            size: 20480,
            size_formatted: '20 KB',
            sort_order: 1,
            order: 1,
            is_image: true,
            uploader_type: 'admin',
            uploader_type_label: '운영자',
            uploader_name: '관리자',
            created_at: '2026-09-14 11:00',
          },
        ],
      },
      { attachment_settings: { max_images: 5, image_max_size_kb: 2048, allowed_extensions: ['jpg', 'png'], accept: '.jpg,.png' } }
    );

  /**
   * 취소 신청서에는 증빙 첨부 축이 없다 — 블록이 남으면 올릴 수 없는 입력이 선다.
   *
   * @scenario claim_type=cancel, claim_stage=final
   * @effects claim_attachment_block_absent_for_cancel_render
   */
  it('취소 유형에서는 첨부 블록이 렌더되지 않는다', async () => {
    const utils = await renderProbe(
      reasonSection as Node,
      claimResponse({ claim_type: 'cancel' }, { claim_path_segment: '', attachment_settings: null })
    );

    expect(document.getElementById('cr_005_images_block')).toBeNull();
    expect(screen.queryByTestId('claim-file-uploader')).toBeNull();
    utils.cleanup();
  });

  /**
   * 사진이 한 장도 없어도 **업로더는 서 있어야** 운영자가 첫 장을 올릴 수 있다.
   *
   * 목록이 비었다고 블록째 감추면 "증빙을 넣을 자리가 없는 화면" 이 된다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_attachment_uploader_stands_even_with_no_images_render
   */
  it('첨부가 0장이어도 블록과 업로더는 선다', async () => {
    const utils = await renderProbe(reasonSection as Node);

    expect(document.getElementById('cr_005_images_block')).not.toBeNull();
    const uploader = screen.getByTestId('claim-file-uploader');
    expect(uploader.getAttribute('data-initial-count')).toBe('0');
    utils.cleanup();
  });

  /**
   * 전이 권한이 없으면 업로더가 잠긴다 — 열어 두면 올린 뒤 서버가 403 으로 거절한다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_attachment_uploader_disabled_without_permission_render
   */
  it('can_update_status 가 false 면 업로더가 비활성이다', async () => {
    const utils = await renderProbe(
      reasonSection as Node,
      claimResponse({}, { abilities: { can_update_status: false, can_update_amounts: false, can_execute_refund: false } })
    );

    expect(screen.getByTestId('claim-file-uploader').getAttribute('data-disabled')).toBe('true');
    utils.cleanup();
  });

  /**
   * 출처 스트립이 고객·운영자를 **다른 뱃지로** 가른다.
   *
   * 분쟁에서 "누가 낸 증거인가" 가 판정을 가르므로 두 주체가 같은 모양으로 보이면 안 된다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_attachment_origin_badges_differ_by_uploader_render
   */
  it('출처 스트립이 고객·운영자 뱃지를 구분해 그린다', async () => {
    const utils = await renderProbe(reasonSection as Node, withImages());

    const strip = document.getElementById('cr_007_images_origin_list');
    expect(strip).not.toBeNull();

    const badges = Array.from(strip!.querySelectorAll('[data-testid="claim-badge"]'));

    // 첨부마다 한 줄 — 반복 선언이 실제로 돌아야 두 줄이 선다.
    expect(badges).toHaveLength(2);

    // 두 주체가 **다른 모양**이어야 한다. 같은 색이면 분쟁에서 "누가 낸 증거인가" 를 화면이
    // 말해 주지 못한다. 어느 prop 으로 색을 주든(색상명 또는 의미 토큰) 달라야 한다.
    const looks = badges.map((el) => `${el.getAttribute('data-color') ?? ''}/${el.getAttribute('data-variant') ?? ''}`);
    expect(new Set(looks).size).toBe(2);
    expect(badges.map((el) => el.textContent)).toEqual(['고객', '운영자']);
    utils.cleanup();
  });

  /**
   * 주체 미상(`unknown`)이면 뱃지를 **그리지 않는다** — 조용한 거짓 대신 조용한 공백이다.
   *
   * 백필이 단정하지 못한 첨부에 아무 뱃지나 붙으면, 운영자가 올린 사진이 「고객이 낸 증거」로
   * 읽혀 분쟁 판정이 뒤집힌다. 서버는 `uploader_type_label` 을 null 로 보내고 화면은 그 줄의
   * 뱃지만 비운다 — 줄 자체는 남아야 파일이 목록에서 사라지지 않는다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_attachment_unknown_origin_renders_no_badge
   */
  it('주체 미상 첨부에는 출처 뱃지를 그리지 않는다', async () => {
    const utils = await renderProbe(
      reasonSection as Node,
      claimResponse({
        images: [
          {
            id: 13,
            original_name: 'legacy.jpg',
            original_filename: 'legacy.jpg',
            url: '/storage/c.jpg',
            size: 30720,
            size_formatted: '30 KB',
            sort_order: 0,
            order: 0,
            is_image: true,
            uploader_type: 'unknown',
            uploader_type_label: null,
            uploader_name: null,
            created_at: '2026-09-14 12:00',
          },
        ],
      })
    );

    const strip = document.getElementById('cr_007_images_origin_list');
    expect(strip).not.toBeNull();

    // 파일은 목록에 남는다.
    expect(strip!.textContent).toContain('legacy.jpg');

    // 다만 뱃지는 한 개도 서지 않는다.
    expect(strip!.querySelectorAll('[data-testid="claim-badge"]')).toHaveLength(0);
    utils.cleanup();
  });
});

describe('신청자 출처 뱃지·요청자 링크 (계획 676)', () => {
  /**
   * 신청자 이름·뱃지·링크 세 노드가 형제로 놓인 헤더 블록을 꺼낸다.
   *
   * 레이아웃 경로를 직접 타는 이유는 이 세 노드가 partial 이 아니라 본문 헤더에 있어서다.
   * id 로 찾으면 조상 체인의 `if` 가 빠져 실제 화면과 다른 조건에서 재게 된다.
   *
   * @returns 세 노드를 담은 부모 Div
   */
  function requesterBlock(): Node {
    const content = (detailLayout as Node).slots.content[0];
    return content.children[0].children[0].children[0].children[1];
  }

  /**
   * 회원 주문이면 이름이 **링크**로 서고 그 회원 화면을 가리킨다.
   *
   * 되돌림 축: 링크 노드를 Span 으로 되돌리거나 링크 조건을 `requested_by` 로 바꾸면 anchor 가
   * 사라져 red. 링크 대상은 주문 주인의 **uuid**(`requester_user_uuid`)다 — 관리자 회원 화면은
   * uuid 로 열리고, 숫자 id 로 만든 링크는 404 였다(#599 validate P7 11-#1 — 종전 이 테스트가
   * 숫자 `/admin/users/853` 을 계약으로 잠갔다).
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects requester_link_targets_the_order_owner_not_the_filer
   */
  it('회원 신청은 이름이 링크로 서고 뱃지가 고객 신청이다', async () => {
    const utils = await renderProbe(
      requesterBlock(),
      claimResponse({
        requester_name: 'MEAS599-S2-M',
        requester_user_id: 853,
        requester_user_uuid: '01990000-0000-7000-8000-000000000853',
        request_source: 'customer',
        request_source_label: '고객 신청',
      })
    );

    const link = document.getElementById('cd_008_requester');
    expect(link).not.toBeNull();
    expect(link!.tagName).toBe('A');
    expect(link!.textContent).toContain('MEAS599-S2-M');

    // 평문 이름은 서지 않는다 — 둘이 같이 서면 이름이 두 번 나온다.
    expect(document.getElementById('cd_008_requester_plain')).toBeNull();

    expect(document.getElementById('cd_008b_request_source')!.textContent).toContain('고객 신청');
    utils.cleanup();
  });

  /**
   * **운영자 대리 접수도 회원 주문이면 링크가 선다** — 접수자가 아니라 주문의 주인이 대상이다.
   *
   * 이 축이 핵심이다. `requested_by` 로 걸면 대리 접수 건은 그 값이 null 이라 **회원 주문인데
   * 링크가 사라진다.** 실측(2026-09-16) `RF20260915-1707536717` = `requested_by` NULL ·
   * 주문 766 의 주인(회원 id 3) → 그 회원의 uuid 경로.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects requester_link_targets_the_order_owner_not_the_filer
   */
  it('운영자 대리 접수도 회원 주문이면 링크가 서고 뱃지는 판매자 접수다', async () => {
    const utils = await renderProbe(
      requesterBlock(),
      claimResponse({
        requester_name: 'MEAS599-S1-M',
        requester_user_id: 3,
        requester_user_uuid: '01990000-0000-7000-8000-000000000003',
        request_source: 'admin',
        request_source_label: '판매자 접수',
      })
    );

    const link = document.getElementById('cd_008_requester');
    expect(link).not.toBeNull();
    expect(link!.tagName).toBe('A');
    expect(document.getElementById('cd_008b_request_source')!.textContent).toContain('판매자 접수');
    utils.cleanup();
  });

  /**
   * 비회원 주문은 **평문 이름**이다 — 누를 곳 없는 링크를 보이면 회원이 있다고 오해한다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects requester_link_targets_the_order_owner_not_the_filer
   */
  it('비회원 신청은 링크가 아니라 평문 이름으로 서고 뱃지가 비회원 신청이다', async () => {
    const utils = await renderProbe(
      requesterBlock(),
      claimResponse({
        requester_name: 'MEAS599-S2-R-G',
        requester_user_id: null,
        requester_user_uuid: null,
        request_source: 'guest',
        request_source_label: '비회원 신청',
      })
    );

    expect(document.getElementById('cd_008_requester')).toBeNull();

    const plain = document.getElementById('cd_008_requester_plain');
    expect(plain).not.toBeNull();
    expect(plain!.tagName).toBe('SPAN');
    expect(plain!.textContent).toContain('MEAS599-S2-R-G');

    expect(document.getElementById('cd_008b_request_source')!.textContent).toContain('비회원 신청');
    utils.cleanup();
  });
});

describe('정산 칸 추가결제 건 — 부분 환급액 (3차 검증 인터뷰 2026-09-26)', () => {
  const payment = (refunded: number) => ({
    id: 7,
    payment_number: 'CP20260926-0230330903',
    payment_method_label: '마일리지',
    payment_status: 'paid',
    payment_status_label: '결제완료',
    payment_status_variant: 'success',
    total_amount: 11000,
    total_amount_formatted: '11,000원',
    refunded_amount: refunded,
    refunded_amount_formatted: refunded > 0 ? '5,000원' : '0원',
  });

  /**
   * 차액 상품을 반려해 추가결제 일부가 되돌아가도 결제 건 상태는 `paid` 로 남는다. 줄에 결제 금액만 있으면
   * 운영자는 환급이 없었던 것으로 읽는다 — 구매자 화면(`claim.detail.payment_refunded`)처럼 환급액을 곁에 적는다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_settlement_payment_row_shows_partial_refund
   */
  it.each([
    [5000, true],
    [0, false],
  ])('환급액 %s 이면 환급 표시 %s', async (refunded, shown) => {
    const utils = await renderProbe(
      paymentSection as Node,
      claimResponse({ status: 'approved', status_label: '교환승인', payments: [payment(refunded)] }),
    );
    const row = document.getElementById('cst_007_payment_0');

    expect(row).not.toBeNull();
    expect(row!.textContent).toContain('11,000원');
    // 이 스위트는 번역을 싣지 않는다 — 키가 그대로 그려지는지로 노출을 재고, 금액 인자는 아래 구조 단언이 잰다.
    expect(row!.textContent!.includes('settlement.payment_refunded')).toBe(shown);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_settlement_payment_row_shows_partial_refund
   */
  it('환급 줄은 결제 건의 서버 표기 환급액을 인자로 싣는다', () => {
    const texts: string[] = [];
    const walk = (n: any): void => {
      if (Array.isArray(n)) { n.forEach(walk); return; }
      if (n && typeof n === 'object') {
        if (typeof n.text === 'string') texts.push(n.text);
        Object.values(n).forEach(walk);
      }
    };
    walk(paymentSection);

    expect(texts).toContain("$t:sirsoft-ecommerce.admin.claim.detail.settlement.payment_refunded|amount={{claimPayment.refunded_amount_formatted ?? ''}}");
  });
});

describe('연결 신청서 (보완 5)', () => {
  /**
   * 레이아웃 트리에서 id 로 노드를 찾는다.
   *
   * @param node 시작 노드
   * @param id 찾을 id
   * @returns 찾은 노드
   */
  function nodeById(node: unknown, id: string): Node | undefined {
    if (Array.isArray(node)) {
      for (const child of node) {
        const hit = nodeById(child, id);
        if (hit) return hit;
      }

      return undefined;
    }
    if (node && typeof node === 'object') {
      if ((node as Node).id === id) return node as Node;
      for (const value of Object.values(node as Node)) {
        const hit = nodeById(value, id);
        if (hit) return hit;
      }
    }

    return undefined;
  }

  const linkedRefund = (n: number, live: boolean) => ({
    claim_type: 'refund',
    claim_type_label: '환불',
    claim_number: `RF2026092100000${n}`,
    status: live ? 'requested' : 'rejected',
    status_label: live ? '환불신청' : '반려',
    status_variant: live ? 'info' : 'danger',
    is_live: live,
    requested_at_formatted: '2026-09-21 10:00',
    detail_url: `/admin/ecommerce/claims/RF2026092100000${n}`,
    refund_method_label: 'PG사 환불',
    refund_total_formatted: `${n},000원`,
    refunded_at_formatted: null,
  });

  /**
   * 반품 정산 카드는 후속 환불신청서 전부를 서버 표기 그대로 그리고, 비면 안내를 띄운다.
   *
   * @scenario claim_type=return, claim_stage=final
   * @effects claim_linked_refunds_listed_from_source_axis, claim_linked_refunds_empty_notice
   */
  it('반품 정산 카드에 후속 환불 목록 · 빈 안내', async () => {
    let utils = await renderProbe(
      paymentSection as Node,
      claimResponse({ claim_type: 'return', claim_number: 'RT20260921000001', status: 'completed' }, {
        linked_claims: { source: null, refunds: [linkedRefund(1, false), linkedRefund(2, true)], latest_refund: linkedRefund(2, true), result_cancel: null },
      }),
    );
    expect(document.getElementById('cst_013_linked_refund_0')?.textContent).toContain('RF20260921000001');
    expect(document.getElementById('cst_013_linked_refund_1')?.textContent).toContain('2,000원');
    expect(document.getElementById('cst_015_linked_refunds_empty')).toBeNull();
    utils.cleanup();

    utils = await renderProbe(
      paymentSection as Node,
      claimResponse({ claim_type: 'return', claim_number: 'RT20260921000001', status: 'completed' }, {
        linked_claims: { source: null, refunds: [], latest_refund: null, result_cancel: null },
      }),
    );
    expect(document.getElementById('cst_015_linked_refunds_empty')).not.toBeNull();
    utils.cleanup();
  });

  /**
   * 헤더 연결 줄 — 환불이면 원 신청서와 처리 결과 취소, 연결이 없으면 줄 전체가 사라진다.
   *
   * @scenario claim_type=exchange, claim_stage=final
   * @effects claim_header_links_source_and_result_cancel
   */
  it('헤더 연결 줄 — 원 신청서 · 처리 결과 취소 · 없으면 숨김', async () => {
    const row = nodeById(detailLayout, 'cd_019_linked_claims_row') as Node;
    expect(row).toBeDefined();

    let utils = await renderProbe(
      row,
      claimResponse({ claim_type: 'refund', claim_number: 'RF20260921000002' }, {
        linked_claims: {
          source: { claim_type: 'return', claim_type_label: '반품', claim_number: 'RT20260921000001', status_label: '반품완료', status_variant: 'success', detail_url: '/admin/ecommerce/claims/RT20260921000001' },
          refunds: [],
          latest_refund: null,
          result_cancel: { claim_type: 'cancel', claim_number: 'CN20260921000009', detail_url: '/admin/ecommerce/claims/CN20260921000009' },
        },
      }),
    );
    expect(document.getElementById('cd_020_source_claim_link')?.textContent).toContain('RT20260921000001');
    expect(document.getElementById('cd_023_result_cancel_link')?.textContent).toContain('CN20260921000009');
    utils.cleanup();

    utils = await renderProbe(row, claimResponse({}, { linked_claims: { source: null, refunds: [], latest_refund: null, result_cancel: null } }));
    expect(document.getElementById('cd_019_linked_claims_row')).toBeNull();
    utils.cleanup();
  });

  /**
   * 링크는 서버가 준 `detail_url` 을 새 창으로 연다 — 화면이 경로를 조립하지 않는다.
   *
   * @scenario claim_type=return, claim_stage=final
   * @effects claim_linked_refunds_listed_from_source_axis
   */
  it('open-window 경로는 detail_url 이다', () => {
    const json = JSON.stringify([nodeById(detailLayout, 'cd_019_linked_claims_row'), nodeById(paymentSection, 'cst_linked_refunds_block')]);
    expect(json).toContain('linkedRefund.detail_url');
    expect(json).toContain('claim.data.linked_claims.source.detail_url');
    expect(json).not.toContain('/admin/ecommerce/claims/{{');
  });
});
