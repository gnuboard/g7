/**
 * @file adminClaimListFilterSectionRender.test.tsx
 * @description 관리자 클레임 목록 필터 섹션 — 유형별 렌더 (S8a · D21/D28)
 *
 * 상태 필터는 유형 서브탭마다 어휘가 다르고(교환 14 / 반품 11 / 환불 7 / 취소 2),
 * 귀책·청구는 그 축이 없는 유형에서 아예 나오면 안 된다 — 게이트가 `prohibited` 로
 * 거절하므로 칩 하나를 누르는 순간 목록 전체가 422 가 되고, 화면에는 "결과 없음" 이
 * 아니라 직전 목록이 그대로 남는다. 조건은 `if` 하나에 걸려 있어 구조 단언만으로는
 * 실제로 걸러지는지 알 수 없으므로, 실제 필터 행을 유형별로 렌더해 확인한다.
 *
 * 슬롯 등록(`slot`/`slotOrder`)은 구조 테스트가 덮으므로 여기서는 벗겨 내고 렌더한다 —
 * SlotContainer 를 태우면 검사 대상이 슬롯 배선으로 옮겨간다.
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

import filterSection from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.json';

type Node = Record<string, any>;

const CHIP_TESTID = 'claim-status-chip';

/** 유형별 기대 칩 수 — 게이트 Enum 어휘와의 동일성은 구조 테스트가 고정한다. */
const EXPECTED_CHIPS: Record<string, number> = {
  exchange: 14,
  return: 11,
  refund: 7,
  cancel: 2,
};

/**
 * 필터 행을 id 로 찾아 슬롯 속성을 벗긴 사본을 돌려준다.
 *
 * @param id 필터 행 id
 * @returns 렌더 가능한 노드
 */
function detachRow(id: string): Node {
  const found = ((filterSection as Node).children ?? [])
    .flatMap((n: Node) => (n.id === 'slot_registration_area' ? n.children ?? [] : []))
    .find((n: Node) => n.id === id);

  if (!found) {
    throw new Error(`필터 행 #${id} 를 찾지 못했다`);
  }

  const { slot, slotOrder, ...rest } = found;

  return rest;
}

/**
 * 단일 필터 행만 담은 프로브 레이아웃.
 *
 * @param row 필터 행
 * @returns 레이아웃 JSON
 */
function probe(row: Node): Node {
  return {
    version: '1.0.0',
    layout_name: 'test/admin-claim-filter-row',
    components: [row],
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();

  // ChipCheckbox 스텁 — 실제 컴포넌트는 Label+Input+Icon 조합이며, 여기서 확인할 것은
  // "몇 개가 어떤 라벨로 나왔는가" 뿐이다.
  registry.register('composite', 'ChipCheckbox', ({ value, label, checked }: any) =>
    React.createElement(
      'label',
      { 'data-testid': CHIP_TESTID, 'data-value': value, 'data-checked': String(!!checked) },
      label
    )
  );
  registry.register('composite', 'Select', ({ name, value, options }: any) =>
    React.createElement(
      'select',
      { 'data-testid': 'claim-filter-select', 'data-name': name, value, onChange: () => {} },
      (options ?? []).map((o: any) =>
        React.createElement('option', { key: String(o.value), value: o.value }, o.label)
      )
    )
  );
});

afterEach(() => {
  vi.clearAllMocks();
});

/**
 * 필터 행 하나를 특정 유형 서브탭에서 렌더한다.
 *
 * @param row 필터 행
 * @param type 유형 (미지정 시 서브탭 파라미터 없음 = 기본 교환)
 * @returns 테스트 유틸
 */
async function renderRow(row: Node, type?: string) {
  const utils = createLayoutTest(probe(row) as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    queryParams: type ? { type } : {},
  });
  await utils.render();

  return utils;
}

describe('클레임 목록 상태 필터 — 유형별 칩 (D28)', () => {
  /**
   * @scenario claim_type=exchange
   * @effects status_chips_scoped_to_active_subtab, status_vocabulary_matches_request_gate
   */
  it(`교환 서브탭에는 그 유형의 칩 ${EXPECTED_CHIPS.exchange}개만 나온다`, async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'), 'exchange');

    expect(screen.queryAllByTestId(CHIP_TESTID)).toHaveLength(EXPECTED_CHIPS.exchange);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=return
   * @effects status_chips_scoped_to_active_subtab, status_vocabulary_matches_request_gate
   */
  it(`반품 서브탭에는 그 유형의 칩 ${EXPECTED_CHIPS.return}개만 나온다`, async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'), 'return');

    expect(screen.queryAllByTestId(CHIP_TESTID)).toHaveLength(EXPECTED_CHIPS.return);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=refund
   * @effects status_chips_scoped_to_active_subtab, status_vocabulary_matches_request_gate
   */
  it(`환불 서브탭에는 그 유형의 칩 ${EXPECTED_CHIPS.refund}개만 나온다`, async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'), 'refund');

    expect(screen.queryAllByTestId(CHIP_TESTID)).toHaveLength(EXPECTED_CHIPS.refund);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=cancel
   * @effects status_chips_scoped_to_active_subtab, status_vocabulary_matches_request_gate
   */
  it(`취소 서브탭에는 그 유형의 칩 ${EXPECTED_CHIPS.cancel}개만 나온다`, async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'), 'cancel');

    expect(screen.queryAllByTestId(CHIP_TESTID)).toHaveLength(EXPECTED_CHIPS.cancel);
    utils.cleanup();
  });

  it('서브탭 파라미터가 없으면 교환 어휘로 떨어진다 (기본값 교환 — D16)', async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'));

    expect(screen.queryAllByTestId(CHIP_TESTID)).toHaveLength(EXPECTED_CHIPS.exchange);
    utils.cleanup();
  });

  /**
   * @effects status_filter_uses_chip_checkbox_not_dropdown
   */
  it('상태 필터는 드롭다운을 쓰지 않는다 (D21 — ChipCheckbox 다중선택)', async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'), 'exchange');

    expect(document.querySelectorAll('select')).toHaveLength(0);
    utils.cleanup();
  });

  it('교환 서브탭에 반품·환불 어휘가 섞이지 않는다', async () => {
    const utils = await renderRow(detachRow('claimStatus_filter_row'), 'exchange');
    const values = screen.queryAllByTestId(CHIP_TESTID).map((el) => el.getAttribute('data-value'));

    // 회수도착까지는 교환·반품이 공유하지만 재발송 3단계는 교환에만 있다.
    expect(values).toContain('redelivering');
    // 환불 전용 상태는 교환 어휘에 없다.
    expect(values).not.toContain('processing');
    utils.cleanup();
  });
});

describe('클레임 목록 필터 — 축이 없는 유형에서의 비노출 (게이트 prohibited)', () => {
  /**
   * @effects fault_axis_absent_for_cancel
   */
  it.each([
    ['exchange', true],
    ['return', true],
    ['refund', true],
    ['cancel', false],
  ] as [string, boolean][])('귀책 필터: %s 서브탭 노출 = %s', async (type, visible) => {
    const utils = await renderRow(detachRow('faultType_filter_row'), type);

    expect(document.querySelectorAll('input[type="checkbox"][value="customer"]').length > 0).toBe(visible);
    utils.cleanup();
  });

  /**
   * @effects charge_axis_absent_for_refund_and_cancel
   */
  it.each([
    ['exchange', true],
    ['return', true],
    ['refund', false],
    ['cancel', false],
  ] as [string, boolean][])('청구방식 필터: %s 서브탭 노출 = %s', async (type, visible) => {
    const utils = await renderRow(detachRow('chargeMethod_filter_row'), type);

    expect(
      document.querySelectorAll('input[type="checkbox"][value="pay_on_request"]').length > 0
    ).toBe(visible);
    utils.cleanup();
  });
});
