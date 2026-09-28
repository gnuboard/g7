/**
 * @file adminOrderClaimHistoryRender.test.tsx
 * @description 관리자 주문상세 '클레임 내역' 섹션 — 실제 렌더 (S9c · D20)
 *
 * 구조 단언은 "그렇게 적혀 있다" 까지만 본다. 이 섹션이 조용히 깨지는 자리는 그 표현식이
 * 실제로 무엇이 되는가에 있어서 렌더해 확인한다:
 *
 *  1. **`rowSpan` 이 숫자로 도달하는가.** 문자열 표현식이 그대로 남으면 브라우저가 값을
 *     버리고 병합이 사라지는데, 표는 여전히 그려지므로 오류가 나지 않는다.
 *  2. **중첩 반복이 실제로 그룹을 만드는가** — 바깥 신청서, 안쪽 상품. 안쪽 반복이 바깥
 *     `item_var` 를 못 읽으면 행이 0개가 되고 표만 남는다.
 *  3. **서브탭이 재조회 없이 목록을 바꾸는가** (D20).
 *  4. **뱃지 숫자가 서버 `counts` 인가** — `claims` 배열 길이와 다른 값을 심어 구분한다.
 *  5. **취소 행도 번호가 링크인가** (취소도 조회 전용 상세가 열린다) · 이미지 없는 상품의 자리표시.
 *  6. **모바일 390 에서 표가 사라지고 카드가 나오는가** (D20).
 *  7. **권한이 없어 못 불러온 경우와 접수 건이 없는 경우가 구분되는가.**
 *  8. **결제 정보의 클레임 추가결제 합계 행이 0원에서 사라지는가.**
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import shell from '../../../layouts/admin/admin_ecommerce_order_detail.json';
import claimHistory from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_claim_history.json';
import paymentInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';

type Node = Record<string, any>;

const DESKTOP_WIDTH = 1280;
const MOBILE_WIDTH = 390;

/**
 * 현재 뷰포트 너비 (반응형 분기 제어용)
 *
 * `createLayoutTest` 는 `DynamicRenderer` 를 `ResponsiveProvider` 없이 렌더하므로
 * `useResponsive()` 가 Context 기본값(1024px)에 고정된다 — 그 상태로는 `responsive.mobile`
 * 분기가 영영 실행되지 않고, 분기를 지워도 이 테스트가 통과한다. 훅만 갈아 끼워 실제 렌더
 * 경로(`effectiveComponentDef` 의 breakpoint 병합)는 그대로 태운다.
 */
let viewportWidth = DESKTOP_WIDTH;

vi.mock('@core/template-engine/ResponsiveContext', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@core/template-engine/ResponsiveContext')>();

  return {
    ...actual,
    useResponsive: () => ({
      width: viewportWidth,
      isMobile: viewportWidth < 768,
      isTablet: viewportWidth >= 768 && viewportWidth < 1024,
      isDesktop: viewportWidth >= 1024,
      matchedPreset: viewportWidth < 768 ? 'mobile' : viewportWidth < 1024 ? 'tablet' : 'desktop',
    }),
  };
});

/** 상품 2건짜리 교환 신청서 — 1열 병합이 실제로 2행을 덮는지 보는 건 */
const EXCHANGE_TWO_ITEMS: Node = {
  id: 4,
  claim_number: 'EX20260827-000004',
  claim_type: 'exchange',
  status: 'rejected',
  status_label: '교환반려',
  status_variant: 'danger',
  requested_at_formatted: '2026-08-27 10:12',
  requester_name: '김지은',
  reason_label: '단순 변심',
  fault_type_label: '고객 귀책',
  reject_reason: '회수 상품 상태 불량',
  detail_url: '/admin/ecommerce/claims/EX20260827-000004',
  claim_shipping_fee: 0,
  claim_shipping_fee_formatted: '0원',
  total_charge_amount: 0,
  total_charge_amount_formatted: '0원',
  items: [
    {
      product_name: '프리미엄 코튼 티셔츠',
      product_option_name: '차콜/M',
      unit_price_formatted: '29,000원',
      new_option_label: '차콜/L',
      new_unit_price_formatted: '29,000원',
      quantity: 1,
      original_quantity: 2,
      amount: 0,
      amount_formatted: '0원',
      amount_type: 'none',
      status_label: '교환반려',
      status_variant: 'danger',
      thumbnail_url: '/storage/products/1/thumb.jpg',
    },
    {
      product_name: '소프트 터치 니트',
      product_option_name: '아이보리/L',
      unit_price_formatted: '39,000원',
      new_option_label: '아이보리/M',
      new_unit_price_formatted: '39,000원',
      quantity: 1,
      original_quantity: 1,
      amount: 0,
      amount_formatted: '0원',
      amount_type: 'none',
      status_label: '교환반려',
      status_variant: 'danger',
      thumbnail_url: null,
    },
  ],
};

/** 상품 1건짜리 교환 신청서 — 신청서 단위 배송비·총 청구액이 실린 건 */
const EXCHANGE_ONE_ITEM: Node = {
  id: 12,
  claim_number: 'EX20260827-000012',
  claim_type: 'exchange',
  status: 'collecting',
  status_label: '교환회수중',
  status_variant: 'info',
  requested_at_formatted: '2026-08-27 09:02',
  requester_name: '김지은',
  reason_label: '색상 변경',
  fault_type_label: '고객 귀책',
  reject_reason: null,
  detail_url: '/admin/ecommerce/claims/EX20260827-000012',
  claim_shipping_fee: 6000,
  claim_shipping_fee_formatted: '6,000원',
  total_charge_amount: 9000,
  total_charge_amount_formatted: '9,000원',
  items: [
    {
      product_name: '프리미엄 코튼 티셔츠',
      product_option_name: '차콜/M',
      unit_price_formatted: '29,000원',
      new_option_label: '블랙/L 각인',
      new_unit_price_formatted: '32,000원',
      quantity: 1,
      original_quantity: 2,
      amount: 3000,
      amount_formatted: '3,000원',
      amount_type: 'charge',
      status_label: '교환회수중',
      status_variant: 'info',
      thumbnail_url: null,
    },
  ],
};

/** 취소 신청서 — 취소도 조회 전용 상세가 있어 서버가 `detail_url` 을 준다 */
const CANCEL_CLAIM: Node = {
  id: 2,
  claim_number: 'CL20260827-000002',
  claim_type: 'cancel',
  status: 'completed',
  status_label: '취소완료',
  status_variant: 'success',
  requested_at_formatted: '2026-08-26 18:30',
  requester_name: '김지은',
  reason_label: '주문 실수',
  fault_type_label: null,
  reject_reason: null,
  detail_url: '/admin/ecommerce/claims/CL20260827-000002',
  items: [
    {
      product_name: '소프트 터치 니트',
      product_option_name: '아이보리/L',
      unit_price_formatted: '45,000원',
      quantity: 1,
      original_quantity: 1,
      amount: 45000,
      amount_formatted: '45,000원',
      amount_type: 'refund',
      status_label: '취소완료',
      status_variant: 'success',
      thumbnail_url: null,
    },
  ],
};

/**
 * D20 그룹 응답 봉투를 만든다.
 *
 * `counts` 는 일부러 `claims` 길이와 다르게 둘 수 있다 — 뱃지가 서버 값을 읽는지
 * 화면이 배열을 다시 세는지 구분하려면 두 값이 갈라져 있어야 한다.
 *
 * @param claims 신청서 배열
 * @param counts 유형별 건수 덮어쓰기
 * @returns 데이터소스 응답
 */
function claimsResponse(claims: Node[] = [], counts: Node = {}) {
  return {
    data: {
      counts: { exchange: 0, return: 0, cancel: 0, refund: 0, ...counts },
      claims,
    },
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  viewportWidth = DESKTOP_WIDTH;
  registry = createMockComponentRegistryWithBasics();

  registry.register('composite', 'Badge', ({ variant, text, children }: any) =>
    React.createElement('span', { 'data-testid': 'claim-badge', 'data-variant': variant }, text ?? children)
  );
  registry.register('basic', 'Icon', ({ name, className }: any) =>
    React.createElement('i', { 'data-testid': 'icon', 'data-icon': name, className })
  );
});

afterEach(() => {
  viewportWidth = DESKTOP_WIDTH;
  vi.clearAllMocks();
});

/**
 * 다음 렌더가 볼 화면 너비를 정한다.
 *
 * @param width 화면 너비 (px)
 */
function setViewportWidth(width: number): void {
  viewportWidth = width;
}

/**
 * 섹션 하나를 프로브 레이아웃으로 감싼다.
 *
 * 데이터소스는 셸에서 그대로 가져온다 — 사본을 두면 셸이 바뀌어도 이 테스트만 옛 계약으로
 * 계속 통과한다.
 *
 * @param node 렌더할 노드
 * @param dataSourceIds 실을 데이터소스 id 목록
 * @returns 레이아웃 JSON
 */
function probe(node: Node, dataSourceIds: string[]): Node {
  const { meta, ...rest } = node;

  return {
    version: '1.0.0',
    layout_name: 'test/admin-order-claim-history',
    data_sources: ((shell as Node).data_sources ?? []).filter((d: Node) => dataSourceIds.includes(d.id)),
    components: [rest],
  };
}

/**
 * 프로브에 셸의 `computed` 정의를 실어 준다.
 *
 * 이 섹션은 `_computed.claimHistoryRows` / `claimHistoryTypes` 를 읽는데, partial 은 computed 를
 * 정의할 수 없어 그 둘은 셸이 소유한다. `createLayoutTest` 는 레이아웃의 `computed` 를
 * 해석하지 않으므로(getComputed 헬퍼용으로만 읽는다) 정의를 dataContext 에 직접 실어
 * DynamicRenderer 의 재계산 경로를 태운다 — 프로덕션에서 `_local` 변경마다 도는 바로 그
 * 경로이고, 정의는 셸에서 그대로 가져오므로 사본이 생기지 않는다.
 */
const COMPUTED_DEFINITIONS = { _computedDefinitions: (shell as Node).computed };

/**
 * 클레임 내역 섹션을 특정 응답·서브탭으로 렌더한다.
 *
 * @param response 그룹 응답
 * @param claimHistoryType 운영자가 누른 유형 서브탭 (빈 값이면 접수 건이 있는 첫 유형이 선다)
 * @returns 테스트 유틸
 */
async function renderSection(response: Record<string, unknown>, claimHistoryType = '') {
  const utils = createLayoutTest(probe(claimHistory as Node, ['orderClaims']) as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    initialData: COMPUTED_DEFINITIONS,
    initialState: { _local: { claimHistoryType } },
  });
  utils.mockApi('orderClaims', { response });
  await utils.render();

  return utils;
}

/**
 * id 로 레이아웃 노드를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 찾은 노드
 */
function byId(node: unknown, id: string): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = byId(child, id);
      if (found) return found;
    }

    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  if ((node as Node).id === id) return node as Node;

  for (const value of Object.values(node as Node)) {
    const found = byId(value, id);
    if (found) return found;
  }

  return undefined;
}

describe('S9c — 주문상세 클레임 내역 섹션 렌더 (D20)', () => {
  describe('신청서 단위 rowspan 병합', () => {
    /**
     * @effects order_claim_history_merged_cell_spans_item_count
     */
    it('상품 2건 신청서에서 1열이 DOM 에 하나뿐이고 rowspan=2 다', async () => {
      const utils = await renderSection(claimsResponse([EXCHANGE_TWO_ITEMS], { exchange: 1 }));
      const rows = document.querySelectorAll('tbody tr');

      expect(rows).toHaveLength(2);

      const mergedCells = document.querySelectorAll('td[rowspan]');

      expect(mergedCells).toHaveLength(1);
      expect(mergedCells[0].getAttribute('rowspan')).toBe('2');
      expect(mergedCells[0].textContent).toContain('EX20260827-000004');

      // 둘째 행은 병합 셀을 갖지 않는다 (상품 4열만)
      expect(rows[1].querySelectorAll('td')).toHaveLength(4);
      expect(rows[0].querySelectorAll('td')).toHaveLength(5);

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_groups_by_request
     */
    it('신청서 2건이면 tbody 가 2개이고 각자의 rowspan 을 갖는다', async () => {
      const utils = await renderSection(
        claimsResponse([EXCHANGE_TWO_ITEMS, EXCHANGE_ONE_ITEM], { exchange: 2 })
      );

      expect(document.querySelectorAll('tbody')).toHaveLength(2);

      const spans = [...document.querySelectorAll('td[rowspan]')].map((td) => td.getAttribute('rowspan'));

      expect(spans).toEqual(['2', '1']);

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_request_level_amounts_stay_in_merged_cell, order_claim_history_amount_direction_from_amount_type
     */
    it('신청서 단위 배송비·총 청구액은 병합 셀에만 있고 상품 행에는 없다 (S6c 판정 ②)', async () => {
      const utils = await renderSection(claimsResponse([EXCHANGE_ONE_ITEM], { exchange: 1 }));
      const merged = document.querySelector('td[rowspan]');

      expect(merged?.textContent).toContain('6,000원');
      expect(merged?.textContent).toContain('9,000원');

      const [, , , amountCell] = [...document.querySelectorAll('tbody tr td')];

      expect(amountCell.textContent).toContain('3,000원');
      expect(amountCell.textContent).not.toContain('6,000원');

      utils.cleanup();
    });
  });

  describe('유형 서브탭', () => {
    /**
     * 활성 서브탭 id 를 읽는다 — 활성 변형은 채운 배경(bg-blue-600)이고 나머지는 흰 배경이다.
     *
     * @returns 활성 서브탭의 유형 값
     */
    function activeSubtab(): string[] {
      return [...document.querySelectorAll('button[id^="claim_history_subtab_"]')]
        .filter((b) => b.className.includes('bg-blue-600'))
        .map((b) => b.id.replace('claim_history_subtab_', ''));
    }

    /**
     * 종전에는 늘 교환으로 시작해 취소만 있는 주문에서 '접수된 건이 없습니다' 가 먼저 보였다.
     *
     * @effects order_claim_history_default_subtab_is_first_type_with_claims
     */
    it('누른 값이 없으면 접수 건이 있는 첫 유형이 선택돼 그 행이 보인다 (취소만 있는 주문 → 취소)', async () => {
      const utils = await renderSection(claimsResponse([CANCEL_CLAIM], { cancel: 1 }));

      expect(activeSubtab()).toEqual(['cancel']);
      expect(document.body.textContent).toContain('CL20260827-000002');
      expect(document.querySelector('#claim_history_empty')).toBeNull();

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_default_subtab_is_first_type_with_claims
     */
    it('여러 유형에 건이 있으면 교환 → 반품 → 취소 → 환불 순서의 첫 유형이다', async () => {
      // 교환 0 · 반품 2 · 취소 1 · 환불 3 → 반품
      const utils = await renderSection(claimsResponse([CANCEL_CLAIM], { return: 2, cancel: 1, refund: 3 }));
      expect(activeSubtab()).toEqual(['return']);
      utils.cleanup();

      // 교환이 있으면 교환이 먼저다
      const withExchange = await renderSection(
        claimsResponse([EXCHANGE_ONE_ITEM, CANCEL_CLAIM], { exchange: 1, cancel: 1 })
      );
      expect(activeSubtab()).toEqual(['exchange']);
      expect(document.body.textContent).toContain('EX20260827-000012');
      withExchange.cleanup();
    });

    /**
     * @effects order_claim_history_default_subtab_is_first_type_with_claims, order_claim_history_empty_type_shows_notice
     */
    it('넷 다 비었으면 교환이 선택되고 빈 안내가 선다', async () => {
      const utils = await renderSection(claimsResponse([], {}));

      expect(activeSubtab()).toEqual(['exchange']);
      expect(document.querySelector('#claim_history_empty')).not.toBeNull();

      utils.cleanup();
    });

    /**
     * 누른 값이 있으면 counts 와 무관하게 그 값이 선다 — 기본 판정은 누르기 전까지만이다.
     *
     * @effects order_claim_history_default_subtab_is_first_type_with_claims
     */
    it('누른 값이 있으면 그 유형이 선다 — 기본 판정은 누르기 전까지만이다', async () => {
      const utils = await renderSection(claimsResponse([CANCEL_CLAIM], { cancel: 1 }), 'refund');

      expect(activeSubtab()).toEqual(['refund']);
      expect(document.querySelector('#claim_history_empty')).not.toBeNull();

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_rows_filtered_by_claim_type, order_claim_history_subtab_switch_does_not_refetch
     */
    it('선택한 유형의 신청서만 그린다 — 재조회 없이 같은 응답을 거른다', async () => {
      const response = claimsResponse([EXCHANGE_ONE_ITEM, CANCEL_CLAIM], { exchange: 1, cancel: 1 });
      const utils = await renderSection(response, 'exchange');

      expect(document.body.textContent).toContain('EX20260827-000012');
      expect(document.body.textContent).not.toContain('CL20260827-000002');

      utils.cleanup();

      const cancelView = await renderSection(response, 'cancel');

      expect(document.body.textContent).toContain('CL20260827-000002');
      expect(document.body.textContent).not.toContain('EX20260827-000012');

      cancelView.cleanup();
    });

    /**
     * @effects order_claim_history_badges_read_server_counts
     */
    it('뱃지 숫자는 서버 counts 다 — 목록에 실린 건수와 갈라 두어도 counts 를 따라간다', async () => {
      // 상한이 걸려 목록에는 1건만 실렸지만 실제 접수는 7건인 상황
      const utils = await renderSection(claimsResponse([EXCHANGE_ONE_ITEM], { exchange: 7, return: 3 }));
      const badges = [...document.querySelectorAll('button[id^="claim_history_subtab_"] span')]
        .map((span) => span.textContent);

      expect(badges).toContain('7');
      expect(badges).toContain('3');
      expect(badges).not.toContain('1');

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_tab_badge_counts_requests
     */
    it('서브탭 4종이 교환·반품·취소·환불 순으로 그려진다', async () => {
      const utils = await renderSection(claimsResponse([], {}));
      const ids = [...document.querySelectorAll('button[id^="claim_history_subtab_"]')].map((b) => b.id);

      expect(ids).toEqual([
        'claim_history_subtab_exchange',
        'claim_history_subtab_return',
        'claim_history_subtab_cancel',
        'claim_history_subtab_refund',
      ]);

      utils.cleanup();
    });
  });

  describe('신청번호 링크', () => {
    /**
     * @effects order_claim_history_detail_link_opens_new_window, order_claim_history_detail_link_uses_server_url
     */
    it('새 창으로 서버가 준 경로를 연다', async () => {
      const utils = await renderSection(claimsResponse([EXCHANGE_ONE_ITEM], { exchange: 1 }));
      const link = document.querySelector('td[rowspan] a');

      expect(link?.getAttribute('href')).toBe('/admin/ecommerce/claims/EX20260827-000012');
      expect(link?.getAttribute('target')).toBe('_blank');

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_number_links_for_every_type
     */
    it('취소 행도 번호가 새 창 링크다 — 취소는 조회 전용 상세가 열린다', async () => {
      const utils = await renderSection(claimsResponse([CANCEL_CLAIM], { cancel: 1 }), 'cancel');
      const links = document.querySelectorAll('td[rowspan] a');

      // 번호가 곧 링크다 — 별도의 [조회하기] 버튼은 없다
      expect(links).toHaveLength(1);
      expect(links[0].textContent).toContain('CL20260827-000002');
      expect(links[0].getAttribute('href')).toBe('/admin/ecommerce/claims/CL20260827-000002');
      expect(links[0].getAttribute('target')).toBe('_blank');

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_number_is_the_link
     */
    it('서버가 경로를 못 만든 행만 번호가 글자로 남는다', async () => {
      const utils = await renderSection(
        claimsResponse([{ ...EXCHANGE_ONE_ITEM, detail_url: null }], { exchange: 1 })
      );

      expect(document.querySelectorAll('td[rowspan] a')).toHaveLength(0);
      expect(document.querySelector('td[rowspan]')?.textContent).toContain('EX20260827-000012');

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_thumbnail_placeholder_when_missing
     */
    it('이미지 없는 상품에는 자리표시 아이콘이 서고 Img 는 없다', async () => {
      const utils = await renderSection(claimsResponse([CANCEL_CLAIM], { cancel: 1 }), 'cancel');

      expect(document.querySelectorAll('tbody [data-icon="image"]')).toHaveLength(1);
      expect(document.querySelectorAll('tbody img')).toHaveLength(0);

      utils.cleanup();

      const withImage = await renderSection(
        claimsResponse([{ ...CANCEL_CLAIM, items: [{ ...CANCEL_CLAIM.items[0], thumbnail_url: '/storage/p/1.jpg' }] }], { cancel: 1 }),
        'cancel'
      );

      expect(document.querySelectorAll('tbody img')).toHaveLength(1);
      expect(document.querySelectorAll('tbody [data-icon="image"]')).toHaveLength(0);

      withImage.cleanup();
    });
  });

  describe('조회 전용 (D20)', () => {
    /**
     * @effects order_claim_history_is_read_only, order_claim_history_has_no_write_actions
     */
    it('렌더된 DOM 에 입력 요소가 없다', async () => {
      const utils = await renderSection(
        claimsResponse([EXCHANGE_TWO_ITEMS, EXCHANGE_ONE_ITEM], { exchange: 2 })
      );

      expect(document.querySelectorAll('input, select, textarea')).toHaveLength(0);
      // 버튼은 유형 서브탭 4개뿐이다
      expect(document.querySelectorAll('button')).toHaveLength(4);

      utils.cleanup();
    });
  });

  describe('빈 상태와 못 불러온 상태', () => {
    /**
     * @effects order_claim_history_empty_type_shows_notice
     */
    it('접수된 건이 없으면 안내를 그리고 표를 그리지 않는다', async () => {
      const utils = await renderSection(claimsResponse([], {}));

      expect(document.querySelector('#claim_history_empty')).not.toBeNull();
      expect(document.querySelectorAll('table')).toHaveLength(0);
      expect(document.querySelector('#claim_history_unavailable')).toBeNull();

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_unavailable_distinguished_from_empty, order_claim_history_datasource_failure_does_not_break_screen
     */
    it('클레임 열람 권한이 없어 403 이면 "없음" 이 아니라 "못 불러왔다" 를 말한다', async () => {
      const utils = createLayoutTest(probe(claimHistory as Node, ['orderClaims']) as any, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: COMPUTED_DEFINITIONS,
        initialState: { _local: { claimHistoryType: 'exchange' } },
      });
      utils.mockApiError('orderClaims', 403, 'auth.permission_denied');
      await utils.render();

      expect(document.querySelector('#claim_history_unavailable')).not.toBeNull();
      expect(document.querySelector('#claim_history_empty')).toBeNull();
      expect(document.querySelectorAll('table')).toHaveLength(0);

      utils.cleanup();
    });
  });

  describe('모바일 390 (D20)', () => {
    /**
     * @effects order_claim_history_mobile_switches_to_cards
     */
    it('표가 사라지고 신청서 단위 카드가 그 수만큼 나온다', async () => {
      setViewportWidth(MOBILE_WIDTH);

      const utils = await renderSection(
        claimsResponse([EXCHANGE_TWO_ITEMS, EXCHANGE_ONE_ITEM], { exchange: 2 })
      );

      expect(document.querySelectorAll('table')).toHaveLength(0);
      expect(document.querySelectorAll('[id^="claim_history_card_header_"]')).toHaveLength(2);

      utils.cleanup();
    });

    /**
     * @effects order_claim_history_mobile_card_header_matches_merged_cell
     */
    it('카드 헤더가 PC 병합셀과 같은 정보를 담는다', async () => {
      setViewportWidth(MOBILE_WIDTH);

      const mobile = await renderSection(claimsResponse([EXCHANGE_ONE_ITEM], { exchange: 1 }));
      const cardHeader = document.querySelector('[id^="claim_history_card_header_"]')?.textContent ?? '';

      mobile.cleanup();

      setViewportWidth(DESKTOP_WIDTH);

      const desktop = await renderSection(claimsResponse([EXCHANGE_ONE_ITEM], { exchange: 1 }));
      const merged = document.querySelector('td[rowspan]')?.textContent ?? '';

      desktop.cleanup();

      for (const fragment of ['EX20260827-000012', '교환회수중', '김지은', '색상 변경', '6,000원', '9,000원']) {
        expect(cardHeader).toContain(fragment);
        expect(merged).toContain(fragment);
      }
    });
  });

  describe('결제 정보 — 클레임 추가결제 합계 행', () => {
    /**
     * 결제 정보의 합계 행 하나만 프로브로 렌더한다.
     *
     * @param total 클레임 추가결제 실수납 합계
     * @returns 테스트 유틸
     */
    async function renderClaimPaidRow(total: number) {
      const row = byId(paymentInfo, 'claim_additional_paid_row_{{payIdx}}') as Node;
      const utils = createLayoutTest(
        {
          version: '1.0.0',
          layout_name: 'test/admin-order-claim-paid-row',
          data_sources: [
            {
              id: 'order',
              type: 'api',
              endpoint: '/api/modules/sirsoft-ecommerce/admin/orders/20260827-0001',
              method: 'GET',
              auto_fetch: true,
            },
          ],
          components: [row],
        } as any,
        { componentRegistry: registry as any, locale: 'ko' }
      );
      utils.mockApi('order', {
        response: {
          data: {
            claim_additional_paid_total: total,
            claim_additional_paid_total_formatted: `${total.toLocaleString('ko-KR')}원`,
          },
        },
      });
      await utils.render();

      return utils;
    }

    /**
     * @effects order_payment_info_claim_additional_paid_row_present, order_claim_history_amounts_come_from_server
     */
    it('실수납이 있으면 서버 표기를 + 부호와 함께 그린다', async () => {
      const utils = await renderClaimPaidRow(9000);

      expect(document.querySelector('[id^="claim_additional_paid_row"]')?.textContent).toContain('+9,000원');

      utils.cleanup();
    });

    /**
     * @effects order_payment_info_claim_additional_paid_row_hidden_when_zero
     */
    it('0원이면 행 자체를 렌더하지 않는다', async () => {
      const utils = await renderClaimPaidRow(0);

      expect(document.querySelector('[id^="claim_additional_paid_row"]')).toBeNull();

      utils.cleanup();
    });
  });

  describe('유형 서브탭 × 접수 유무', () => {
    /**
     * 그 유형에 접수된 건이 하나 있는 응답을 만든다.
     *
     * 응답은 전 유형을 한 배열에 싣고 화면이 거르므로, 지금 보는 서브탭이 비어 있어도 다른
     * 유형에는 건이 있을 수 있다 — 그 상태를 만들기 위해 언제나 다른 유형 한 건을 함께 싣는다.
     *
     * @param claimType 유형
     * @param present 그 유형의 접수 유무
     * @returns 그룹 응답
     */
    function responseFor(claimType: string, present: boolean) {
      const other = { ...CANCEL_CLAIM, claim_number: 'CL20260827-000099', claim_type: 'cancel' };
      const mine = {
        ...EXCHANGE_ONE_ITEM,
        claim_number: `XX-${claimType}`,
        claim_type: claimType,
        // 네 유형 모두 서버가 상세 경로를 준다 (취소도 조회 전용 상세)
        detail_url: `/admin/ecommerce/claims/XX-${claimType}`,
      };

      const claims = claimType === 'cancel'
        ? (present ? [mine] : [{ ...EXCHANGE_ONE_ITEM }])
        : (present ? [mine, other] : [other]);

      return claimsResponse(claims, {
        [claimType]: present ? 1 : 0,
        ...(claimType === 'cancel' ? {} : { cancel: 1 }),
      });
    }

    // 아래 루프가 유형 4종 × 접수 유무 2종을 모두 돈다. `it` 제목이 템플릿 리터럴이라
    // 조합별 docblock 을 붙일 자리가 없어 마킹을 여기에 모아 둔다 — 조합 목록과 루프의
    // 축 배열이 어긋나면 이 목록이 거짓이 되므로 함께 본다.
    // @scenario claim_type=exchange, claims_present=yes
    // @effects order_claim_history_rows_filtered_by_claim_type
    // @scenario claim_type=exchange, claims_present=no
    // @effects order_claim_history_empty_type_shows_notice
    // @scenario claim_type=return, claims_present=yes
    // @effects order_claim_history_rows_filtered_by_claim_type
    // @scenario claim_type=return, claims_present=no
    // @effects order_claim_history_empty_type_shows_notice
    // @scenario claim_type=cancel, claims_present=yes
    // @effects order_claim_history_rows_filtered_by_claim_type
    // @scenario claim_type=cancel, claims_present=no
    // @effects order_claim_history_empty_type_shows_notice
    // @scenario claim_type=refund, claims_present=yes
    // @effects order_claim_history_rows_filtered_by_claim_type
    // @scenario claim_type=refund, claims_present=no
    // @effects order_claim_history_empty_type_shows_notice
    for (const claimType of ['exchange', 'return', 'cancel', 'refund']) {
      it(`${claimType}: 접수된 건이 있으면 그 유형의 행만 그린다`, async () => {
        const utils = await renderSection(responseFor(claimType, true), claimType);

        expect(document.body.textContent).toContain(`XX-${claimType}`);
        expect(document.querySelector('#claim_history_empty')).toBeNull();
        expect(document.querySelectorAll('tbody')).toHaveLength(1);

        utils.cleanup();
      });

      it(`${claimType}: 접수된 건이 없으면 다른 유형에 건이 있어도 안내를 그린다`, async () => {
        const utils = await renderSection(responseFor(claimType, false), claimType);

        expect(document.querySelector('#claim_history_empty')).not.toBeNull();
        expect(document.querySelectorAll('table')).toHaveLength(0);
        expect(document.querySelector('#claim_history_unavailable')).toBeNull();

        utils.cleanup();
      });
    }
  });
});
