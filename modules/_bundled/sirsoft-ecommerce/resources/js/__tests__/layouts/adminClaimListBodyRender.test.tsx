/**
 * @file adminClaimListBodyRender.test.tsx
 * @description 관리자 클레임 목록 본체 — 유형별 렌더 (S8b · D26)
 *
 * 조건은 `if` 하나에 걸려 있어 구조 단언만으로는 실제로 걸러지는지 알 수 없다. 세 가지를
 * 실제로 렌더해 확인한다:
 *
 *  1. 일괄 바가 취소 서브탭에서 사라지고, 나머지 유형에서는 그 유형의 어휘만 나온다.
 *  2. 금액 셀이 유형마다 실리는 필드가 달라도 서버가 준 표기 문자열을 그대로 그린다 —
 *     화면이 숫자에 기호를 붙이면 기본 통화가 원이 아닌 상점에서 단위만 틀린 금액이 된다.
 *  3. 완료 처리 모달이 `_global` 계약만으로 그려진다 — 목록 데이터소스 없이도 행·금액·
 *     접수 불가 사유가 나와야 세 진입점이 이 파일 하나를 공유할 수 있다 (D26).
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

import bulkSection from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_bulk_action_section.json';
import datagrid from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_claim_datagrid.json';
import completeModal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_claim_complete.json';

type Node = Record<string, any>;

const GRID_ROW_TESTID = 'claim-grid-row';

/** 목록 응답 한 벌 — 유형마다 실리는 금액 필드가 다르다. */
const EXCHANGE_ROW = {
  id: 31,
  claim_number: 'EX20260826000001',
  order_number: '20260826-0001',
  requester_name: '홍길동',
  is_guest_request: false,
  status: 'collecting',
  status_label: '교환회수중',
  status_variant: 'info',
  reason_label: '단순 변심',
  fault_type_label: '고객 귀책',
  item_summary: { product_name: '프리미엄 코튼 티셔츠', item_count: 2, total_quantity: 3 },
  requested_at_formatted: '2026-08-26 10:12',
  completed_at_formatted: null,
  total_charge_amount: 9000,
  total_charge_amount_formatted: '9,000원',
  outstanding_amount: 3000,
  outstanding_amount_formatted: '3,000원',
};

const REFUND_ROW = {
  id: 44,
  claim_number: 'RF20260826000002',
  order_number: '20260826-0002',
  requester_name: null,
  is_guest_request: true,
  status: 'requested',
  status_label: '환불신청',
  status_variant: 'secondary',
  reason_label: null,
  fault_type_label: null,
  item_summary: { product_name: '소프트 터치 니트', item_count: 1, total_quantity: 1 },
  requested_at_formatted: '2026-08-26 11:00',
  completed_at_formatted: null,
  refund_total: 29000,
  refund_total_formatted: '29,000원',
};

/**
 * 목록 응답 봉투를 만든다.
 *
 * @param rows 행 배열
 * @param canUpdateStatus 전이 권한
 * @returns 데이터소스 응답
 */
function claimsResponse(rows: Node[], canUpdateStatus = true) {
  return {
    data: {
      data: rows,
      counts: { exchange: 12, return: 5, refund: 3, cancel: 8 },
      abilities: { can_update_status: canUpdateStatus },
      pagination: { current_page: 1, per_page: 20, last_page: 1, total: rows.length, has_more_pages: false },
    },
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();

  // DataGrid 스텁 — 여기서 확인할 것은 "어떤 셀이 어떤 문자열로 나왔는가" 뿐이라
  // 컬럼 정의를 그대로 렌더한다. 실제 컴포넌트를 태우면 검사 대상이 그리드 내부로 옮겨간다.
  registry.register('composite', 'DataGrid', ({ columns, data, selectable, selectionScope }: any) =>
    React.createElement(
      'div',
      {
        'data-testid': 'claim-datagrid',
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

  registry.register('composite', 'Select', ({ name, value, options }: any) =>
    React.createElement(
      'select',
      { 'data-testid': 'claim-bulk-select', 'data-name': name, value, onChange: () => {} },
      (options ?? []).map((o: any) =>
        React.createElement('option', { key: String(o.value), value: o.value }, o.label)
      )
    )
  );

  registry.register('composite', 'StatusBadge', ({ label }: any) =>
    React.createElement('span', { 'data-testid': 'claim-status-badge' }, label)
  );

  registry.register('composite', 'RadioGroup', ({ name, value, options }: any) =>
    React.createElement(
      'div',
      { 'data-testid': 'claim-refund-radio', 'data-name': name, 'data-value': value },
      (options ?? []).map((o: any) =>
        React.createElement('span', { key: o.value, 'data-option': o.value }, o.label)
      )
    )
  );

  registry.register('composite', 'Modal', ({ title, children }: any) =>
    React.createElement('div', { 'data-testid': 'claim-modal', 'data-title': title }, children)
  );
});

afterEach(() => {
  vi.clearAllMocks();
});

/**
 * 컬럼의 `cellChildren` 표현식을 행 데이터로 아주 얕게 풀어낸다.
 *
 * DataGrid 스텁이 실제 바인딩 엔진을 태우지 않으므로, 이 테스트가 보는 축(금액 표기)만
 * 판별할 수 있도록 `{{row.*}}` 단일 바인딩과 `if` 조건만 해석한다.
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
 * `{{...}}` 단일 바인딩을 행 데이터로 평가한다. (테스트 전용 최소 해석기)
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
 * `text` 를 평가한다. 다국어 키는 키 자체를 돌려준다.
 *
 * @param text 텍스트 정의
 * @param row 행 데이터
 * @returns 문자열
 */
function resolveText(text: string, row: any): string {
  if (text.startsWith('$t:')) {
    return text;
  }

  const match = text.match(/^\{\{(.*)\}\}$/s);
  if (!match) return text;

  const value = evaluate(match[1], row);

  return value === undefined || value === null ? '' : String(value);
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
    layout_name: 'test/admin-claim-list-body',
    data_sources: [
      {
        id: 'claims',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/admin/claims',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: [rest],
  };
}

/**
 * 프로브를 특정 유형·상태에서 렌더한다.
 *
 * @param node 노드
 * @param options 렌더 옵션
 * @returns 테스트 유틸
 */
async function renderProbe(
  node: Node,
  options: { type?: string; claims?: Record<string, unknown>; globalState?: Record<string, unknown> } = {}
) {
  const utils = createLayoutTest(probe(node) as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    queryParams: options.type ? { type: options.type } : {},
    initialState: options.globalState ? { _global: options.globalState } : undefined,
  });
  utils.mockApi('claims', { response: options.claims ?? claimsResponse([]) });
  await utils.render();

  return utils;
}

describe('취소 행 — 재활성 뱃지 (Q29)', () => {
  const CANCEL_ROW = {
    id: 71,
    claim_number: 'CN20261005000001',
    order_number: '20261005-0001',
    requester_name: '홍길동',
    is_guest_request: false,
    status: 'completed',
    status_label: '취소완료',
    status_variant: 'success',
    item_summary: { product_name: '프리미엄 코튼 티셔츠', item_count: 1, total_quantity: 2 },
    requested_at_formatted: '2026-10-05 10:00',
    completed_at_formatted: '2026-10-05 10:00',
    refund_total: 12000,
    refund_total_formatted: '12,000원',
    reactivated_at_formatted: null,
    is_partially_reactivated: false,
  };

  /**
   * 재활성 기록이 있는 취소 행만 뱃지가 나오고, 일부만 되살렸으면 「일부」 뱃지가 나온다.
   *
   * @scenario revival=whole_order
   * @effects claim_list_cancel_row_shows_reactivated_badge_with_time, claim_list_cancel_row_shows_partial_reactivated_badge
   */
  it('재활성된 취소 행에 일시와 함께 뱃지가 나온다', async () => {
    const utils = await renderProbe(datagrid as Node, {
      type: 'cancel',
      claims: claimsResponse([
        { ...CANCEL_ROW, reactivated_at_formatted: '2026-10-05 14:00' },
        { ...CANCEL_ROW, id: 72, claim_number: 'CN20261005000002', reactivated_at_formatted: '2026-10-05 15:00', is_partially_reactivated: true },
        { ...CANCEL_ROW, id: 73, claim_number: 'CN20261005000003' },
      ]),
    });

    const cells = screen.getAllByTestId(GRID_ROW_TESTID).map((row) => row.querySelector('[data-field="claim_number"]')?.textContent ?? '');

    expect(cells[0]).toContain('reactivated_badge');
    expect(cells[0]).not.toContain('reactivated_partial_badge');
    expect(cells[1]).toContain('reactivated_partial_badge');
    expect(cells[2]).not.toContain('reactivated');
    utils.cleanup();
  });
});

describe('일괄 변경 바 — 유형별 노출과 어휘 (S8b)', () => {
  /**
   * 취소는 게이트의 `type` 어휘에 없다 — 바를 그리면 누를 수 있는 버튼이 언제나 422 다.
   *
   * @effects bulk_bar_absent_for_cancel
   */
  it('취소 서브탭에는 일괄 바가 아예 나오지 않는다', async () => {
    const utils = await renderProbe(bulkSection as Node, {
      type: 'cancel',
      claims: claimsResponse([]),
    });

    expect(screen.queryAllByTestId('claim-bulk-select')).toHaveLength(0);
    utils.cleanup();
  });

  /**
   * @scenario claim_type=exchange
   * @effects bulk_status_vocabulary_matches_request_gate
   */
  it.each([
    ['exchange', 11],
    ['return', 8],
    ['refund', 3],
  ])('%s 서브탭에는 그 유형의 지정 가능 상태 %d종만 나온다', async (type, expected) => {
    const utils = await renderProbe(bulkSection as Node, {
      type,
      claims: claimsResponse([]),
    });

    const selects = screen.queryAllByTestId('claim-bulk-select');
    expect(selects).toHaveLength(1);
    // 첫 옵션은 placeholder 다.
    expect(selects[0].querySelectorAll('option')).toHaveLength(expected + 1);
    utils.cleanup();
  });

  /**
   * 교환 서브탭의 일괄 상태 선택지에는 교환 어휘만 있다 — 반품·환불 상태가 섞이면 게이트가 422 로 거절한다.
   *
   * @scenario claim_type=exchange
   * @effects bulk_status_vocabulary_matches_request_gate
   */
  it('교환 서브탭에 반품·환불 어휘가 섞이지 않는다', async () => {
    const utils = await renderProbe(bulkSection as Node, {
      type: 'exchange',
      claims: claimsResponse([]),
    });

    const values = [...screen.getByTestId('claim-bulk-select').querySelectorAll('option')].map(
      (o) => (o as HTMLOptionElement).value
    );

    expect(values).toContain('redelivering');
    expect(values).not.toContain('processing');
    utils.cleanup();
  });
});

describe('DataGrid — 금액 셀은 서버 표기를 그대로 그린다', () => {
  /**
   * 교환·반품은 청구액이, 환불·취소는 환불 합계가 실린다 — 화면이 유형을 알 필요 없이
   * 행이 가진 필드로 갈린다.
   *
   * @effects amounts_render_from_server_formatted_only
   */
  it('교환 행은 청구액과 미수납 잔액을 표기 문자열로 낸다', async () => {
    const utils = await renderProbe(datagrid as Node, {
      type: 'exchange',
      claims: claimsResponse([EXCHANGE_ROW]),
    });

    const amountCell = screen.getByTestId(GRID_ROW_TESTID).querySelector('[data-field="amount"]');

    expect(amountCell?.textContent).toContain('9,000원');
    expect(amountCell?.textContent).toContain('column.outstanding_short');
    utils.cleanup();
  });

  /**
   * @scenario claim_type=refund, completion_choice=not_applicable
   * @effects amounts_render_from_server_formatted_only
   */
  it('환불 행은 환불 합계를 표기 문자열로 낸다', async () => {
    const utils = await renderProbe(datagrid as Node, {
      type: 'refund',
      claims: claimsResponse([REFUND_ROW]),
    });

    const amountCell = screen.getByTestId(GRID_ROW_TESTID).querySelector('[data-field="amount"]');

    expect(amountCell?.textContent).toContain('29,000원');
    utils.cleanup();
  });

  /**
   * 미수납 판정은 상태 라벨이 아니라 잔액으로 한다 — 분할 수납 건은 결제대기 라벨 그대로라
   * 라벨만 보면 구분되지 않는다.
   *
   * @effects unpaid_badge_reads_outstanding_amount_not_status_label
   */
  it('잔액이 0이면 미수납 표기가 나오지 않는다', async () => {
    const utils = await renderProbe(datagrid as Node, {
      type: 'exchange',
      claims: claimsResponse([
        { ...EXCHANGE_ROW, outstanding_amount: 0, outstanding_amount_formatted: '0원' },
      ]),
    });

    const amountCell = screen.getByTestId(GRID_ROW_TESTID).querySelector('[data-field="amount"]');

    expect(amountCell?.textContent).toContain('9,000원');
    expect(amountCell?.textContent).not.toContain('column.outstanding_short');
    utils.cleanup();
  });

  /**
   * @effects selection_scope_declared_page
   */
  it('선택 범위가 page 로 열리고, 권한이 없으면 선택 자체가 닫힌다', async () => {
    const allowed = await renderProbe(datagrid as Node, {
      type: 'exchange',
      claims: claimsResponse([EXCHANGE_ROW]),
    });

    expect(screen.getByTestId('claim-datagrid').getAttribute('data-selection-scope')).toBe('page');
    expect(screen.getByTestId('claim-datagrid').getAttribute('data-selectable')).toBe('true');
    allowed.cleanup();

    const denied = await renderProbe(datagrid as Node, {
      type: 'exchange',
      claims: claimsResponse([EXCHANGE_ROW], false),
    });

    expect(screen.getByTestId('claim-datagrid').getAttribute('data-selectable')).toBe('false');
    denied.cleanup();
  });

  /**
   * @scenario claim_type=cancel, completion_choice=not_applicable
   * @effects selection_closed_for_cancel_subtab
   */
  it('취소 서브탭에서는 선택이 열리지 않는다 (일괄 전이 대상 아님)', async () => {
    const utils = await renderProbe(datagrid as Node, {
      type: 'cancel',
      claims: claimsResponse([REFUND_ROW]),
    });

    expect(screen.getByTestId('claim-datagrid').getAttribute('data-selectable')).toBe('false');
    utils.cleanup();
  });
});

describe('완료 처리 모달 — _global 계약만으로 그려진다 (D26)', () => {
  /** 모달이 읽는 전역 상태 한 벌 */
  const COMPLETE_STATE = {
    claimCompleteContext: { claimType: 'return', targets: [{ id: 31 }], execute: { url: '/x', method: 'POST' } },
    claimCompleteCreateRefundRequest: true,
    claimCompletePreview: {
      claims: [
        {
          id: 31,
          claim_number: 'RT20260826000031',
          completable_item_ids: [55],
          completable_item_count: 1,
          stock_restore_count: 1,
          stock_discard_count: 0,
          earn_recovery: true,
          coupon_restore: false,
        },
      ],
      skipped: [],
      totals: { claim_count: 1, refund_amount_total_formatted: '55,000원' },
    },
    claimCompleteRows: [
      {
        id: 31,
        claim_number: 'RT20260826000031',
        requester_name: '박서준',
        item_label: '헤비 후드 XL',
        completable_item_count: 1,
        refund_applicable: true,
        refund_amount_formatted: '55,000원',
        blocked_reason: null,
        restore: true,
      },
    ],
  };

  /**
   * 목록 데이터소스를 하나도 주지 않고 렌더한다 — 상세·완료 버튼 진입점의 조건과 같다.
   *
   * @effects completion_modal_reads_only_shared_global_contract
   */
  it('목록 데이터소스 없이도 대상 행과 금액이 나온다', async () => {
    const utils = await renderProbe(completeModal as Node, { globalState: COMPLETE_STATE });

    expect(screen.getByTestId('claim-modal').textContent).toContain('RT20260826000031');
    expect(screen.getByTestId('claim-modal').textContent).toContain('헤비 후드 XL');
    expect(screen.getByTestId('claim-modal').textContent).toContain('55,000원');
    // 대상이 한 통화로 모이면 서버가 낸 합계 표기를 쓰는 문구가 나온다.
    expect(screen.getByTestId('claim-modal').textContent).toContain('complete.refund_now_desc_amount');
    utils.cleanup();
  });

  /**
   * 접수가 성립하지 않는 사유는 두 가지이고 운영자에게 뜻이 다르다 — 사유 없이 0원 접수를
   * 예고하면 실제 접수가 422 가 되어 미리보기와 실행이 갈린다.
   *
   * @effects blocked_reasons_shown_separately
   */
  it('이미 접수된 건은 금액 대신 그 사유를 낸다', async () => {
    const utils = await renderProbe(completeModal as Node, {
      globalState: {
        ...COMPLETE_STATE,
        claimCompleteRows: [
          {
            ...COMPLETE_STATE.claimCompleteRows[0],
            refund_applicable: false,
            refund_amount_formatted: null,
            blocked_reason: 'claim_refund_request_exists',
          },
        ],
      },
    });

    const text = screen.getByTestId('claim-modal').textContent ?? '';
    expect(text).toContain('complete.blocked_exists');
    expect(text).not.toContain('55,000원');
    utils.cleanup();
  });

  /**
   * 여러 통화가 섞이면 서버가 합계 표기를 내지 않는다 — 그때 화면이 숫자를 더해 기호를
   * 붙이면 단위만 틀린 합계가 나간다.
   *
   * @effects mixed_currency_total_falls_back_to_count_only
   */
  it('합계 표기가 없으면 건수만 말한다', async () => {
    const utils = await renderProbe(completeModal as Node, {
      globalState: {
        ...COMPLETE_STATE,
        claimCompletePreview: {
          ...COMPLETE_STATE.claimCompletePreview,
          totals: { claim_count: 1, refund_amount_total_formatted: null },
        },
      },
    });

    const text = screen.getByTestId('claim-modal').textContent ?? '';
    expect(text).not.toContain('complete.refund_now_desc_amount');
    expect(text).toContain('complete.refund_now_desc');
    utils.cleanup();
  });

  /**
   * @effects skipped_claims_reported_in_modal
   */
  it('조회되지 않은 신청서가 있으면 안내한다', async () => {
    const utils = await renderProbe(completeModal as Node, {
      globalState: {
        ...COMPLETE_STATE,
        claimCompletePreview: {
          ...COMPLETE_STATE.claimCompletePreview,
          skipped: [{ id: 999999, message: '해당 신청서를 찾을 수 없습니다.' }],
        },
      },
    });

    expect(screen.getByTestId('claim-modal').textContent).toContain('complete.skipped_notice');
    utils.cleanup();
  });

  /**
   * @effects completion_modal_title_follows_claim_type
   */
  it('제목이 유형을 따라간다', async () => {
    const returnModal = await renderProbe(completeModal as Node, { globalState: COMPLETE_STATE });
    expect(screen.getByTestId('claim-modal').getAttribute('data-title')).toContain('complete.title_return');
    returnModal.cleanup();

    const exchangeModal = await renderProbe(completeModal as Node, {
      globalState: {
        ...COMPLETE_STATE,
        claimCompleteContext: { ...COMPLETE_STATE.claimCompleteContext, claimType: 'exchange' },
      },
    });
    expect(screen.getByTestId('claim-modal').getAttribute('data-title')).toContain('complete.title_exchange');
    exchangeModal.cleanup();

    // 완료 처리 모달은 교환·반품 둘만 연다. 셋째 값이 오면(계약 위반) 반품 제목을 폴백으로
    // 쓰지 않는다 — 교환·반품이 아닌 대상에 '반품완료 처리' 라는 틀린 안내가 나간다.
    const otherModal = await renderProbe(completeModal as Node, {
      globalState: {
        ...COMPLETE_STATE,
        claimCompleteContext: { ...COMPLETE_STATE.claimCompleteContext, claimType: 'refund' },
      },
    });
    const title = screen.getByTestId('claim-modal').getAttribute('data-title');
    expect(title).toContain('complete.title_generic');
    expect(title).not.toContain('complete.title_return');
    otherModal.cleanup();
  });

  /**
   * 대상이 없는데 [완료 처리] 가 눌리면 빈 요청이 나간다.
   *
   * @effects submit_disabled_without_targets
   */
  it('대상이 없으면 안내를 내고 실행 버튼이 잠긴다', async () => {
    const utils = await renderProbe(completeModal as Node, {
      globalState: {
        ...COMPLETE_STATE,
        claimCompleteRows: [],
        claimCompletePreview: { claims: [], skipped: [], totals: {} },
      },
    });

    expect(screen.getByTestId('claim-modal').textContent).toContain('complete.no_target');

    // 대상을 특정한다 — `button[disabled]` 로 잡으면 문서 안 아무 버튼이나 잠겨도 통과해,
    // 취소 버튼에 다른 disabled 조건이 붙는 순간 실행 버튼과 무관한 단언이 된다.
    const submit = document.querySelector('#cc_022_submit_button');
    const cancel = document.querySelector('#cc_021_cancel_button');

    expect(submit).not.toBeNull();
    expect(submit).toBeDisabled();

    // 취소는 살아 있어야 한다 — 대상이 없다고 모달에 갇히면 나갈 길이 없다.
    expect(cancel).not.toBeNull();
    expect(cancel).not.toBeDisabled();
    utils.cleanup();
  });
});

describe('완료 처리 모달 — 선교환 미회수 안내 (S8 · Q10)', () => {
  /**
   * 미회수 후보가 있는 선교환 완료 상태
   *
   * @param withCandidate 미회수 후보를 실을지
   * @returns 전역 상태
   */
  function exchangeCompleteState(withCandidate: boolean) {
    return {
      claimCompleteContext: { claimType: 'exchange', targets: [{ id: 41 }], execute: { url: '/x', method: 'POST' } },
      claimCompleteCreateRefundRequest: false,
      claimCompletePreview: {
        claims: [
          {
            id: 41,
            claim_number: 'EX20261005000041',
            completable_item_ids: [77],
            completable_item_count: 1,
            stock_restore_count: 0,
            stock_discard_count: 0,
            unreturned_eligible_items: withCandidate ? [{ id: 77, label: '헤비 후드 XL' }] : [],
          },
        ],
        skipped: [],
        totals: { claim_count: 1 },
      },
      claimCompleteRows: [],
    };
  }

  /**
   * 미회수로 완료하면 상품 대금을 청구하지 않는다 — 운영자가 모른 채 완료하지 않도록 체크 영역에서 말한다.
   *
   * @effects advance_unreturned_completion_warns_no_charge
   */
  it('미회수 후보가 있으면 「고객에게 청구되지 않습니다」 안내가 나오고, 없으면 나오지 않는다', async () => {
    let utils = await renderProbe(completeModal as Node, { globalState: exchangeCompleteState(true) });
    expect(screen.getByTestId('claim-modal').textContent).toContain('complete.unreturned_no_charge_notice');
    utils.cleanup();

    utils = await renderProbe(completeModal as Node, { globalState: exchangeCompleteState(false) });
    expect(screen.getByTestId('claim-modal').textContent).not.toContain('complete.unreturned_no_charge_notice');
    utils.cleanup();
  });
});

describe('반품·교환 행 — 환불 진행 배지 (보완 5 · Q7)', () => {
  const RETURN_ROW = {
    id: 81,
    claim_number: 'RT20261005000001',
    order_number: '20261005-0002',
    requester_name: '홍길동',
    is_guest_request: false,
    status: 'completed',
    status_label: '반품완료',
    status_variant: 'success',
    item_summary: { product_name: '프리미엄 코튼 티셔츠', item_count: 1, total_quantity: 1 },
    requested_at_formatted: '2026-10-05 10:00',
    completed_at_formatted: '2026-10-05 12:00',
    total_charge_amount: 0,
    total_charge_amount_formatted: '0원',
    outstanding_amount: 0,
    has_pending_refund: false,
    refund_progress: null,
  };

  /**
   * 최신 환불의 상태 배지와 「외 N건」 이 나오고, 환불이 없으면 배지가 없다.
   *
   * @scenario claim_type=return, completion_choice=file_later
   * @effects claim_list_refund_progress_badge_navigates_to_refund
   */
  it('최신 환불 상태와 외 N건, 없으면 없음', async () => {
    const utils = await renderProbe(datagrid as Node, {
      type: 'return',
      claims: claimsResponse([
        { ...RETURN_ROW, refund_progress: { claim_number: 'RF20261005000002', status: 'requested', status_label: '환불신청', status_variant: 'info', status_icon: 'status-requested', count: 2 } },
        { ...RETURN_ROW, id: 82, claim_number: 'RT20261005000002' },
      ]),
    });

    const cells = screen.getAllByTestId(GRID_ROW_TESTID).map((row) => row.querySelector('[data-field="claim_number"]')?.textContent ?? '');

    expect(cells[0]).toContain('환불신청');
    expect(cells[0]).toContain('refund_progress_more');
    expect(cells[1]).not.toContain('환불신청');
    utils.cleanup();
  });

  /**
   * 배지를 누르면 그 환불신청서 상세로 간다(목록 조건 승계).
   *
   * @scenario claim_type=return, completion_choice=file_later
   * @effects claim_list_refund_progress_badge_navigates_to_refund
   */
  it('배지는 환불신청서 상세로 이동한다', () => {
    const json = JSON.stringify(datagrid);
    expect(json).toContain('/admin/ecommerce/claims/{{row.refund_progress.claim_number}}');
  });
});
