/**
 * @file adminListLoadingEmptyState.test.tsx
 * @description 관리자 이커머스 목록 표 — 목록을 받는 동안에는 「없음」 문구를 보이지 않는다
 *
 * 목록 데이터소스는 화면이 먼저 그려진 뒤에 받는다(progressive). 받기 전에는 데이터소스 값이
 * `undefined` 라서 표의 행이 0개로 보이고, 표는 행이 0개이면 빈 문구를 그린다. 그래서 수정
 * 화면에서 「목록」으로 돌아온 직후처럼 목록을 아직 받지 못한 순간에 「등록된 상품이 없습니다」가
 * 먼저 떴다가, 받고 나서야 행이 들어왔다 — 데이터가 있는데도 없다고 말하는 것이다.
 *
 * 각 화면의 실제 레이아웃 JSON 에서 표 노드를 꺼내 하네스로 렌더한다:
 *
 *  1. 받는 중(값이 `undefined`) — 빈 문구가 없고, 표가 흐림 처리된다.
 *  2. 받은 뒤 0건 — 빈 문구가 보이고 흐림이 풀린다.
 *  3. 받은 뒤 1건 이상 — 행이 그려지고 빈 문구가 없다.
 *  4. 받기 실패(값 `null`) — 지금 동작을 잠근다: 흐림이 풀리고 빈 문구가 보인다.
 *  5. 전수 — 화면이 그려진 뒤 받는 데이터소스에 묶인 관리자 표는(빈 문구를 적지 않은 표 포함) 모두 위 규칙을 지킨다.
 *
 * 표 컴포넌트는 스텁이다. 실제 `DataGrid` 와 같은 규칙만 흉내 낸다 — 행이 없으면
 * `emptyMessage ?? '기본 문구'` 를 그린다(빈 문자열이면 아무것도 그리지 않는다).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import productGrid from '../../../layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json';
import orderGrid from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';
import claimGrid from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_claim_datagrid.json';
import couponGrid from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_partial_coupon_datagrid.json';
import couponIssueModal from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.json';
import shippingPolicyGrid from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json';
import mileageTable from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json';
import addressGrid from '../../../layouts/admin/partials/admin_ecommerce_user_address_index/_partial_address_datagrid.json';
import cartIndex from '../../../layouts/admin/admin_ecommerce_cart_index.json';
import inquiryIndex from '../../../layouts/admin/admin_ecommerce_inquiry_index.json';
import wishlistIndex from '../../../layouts/admin/admin_ecommerce_wishlist_index.json';
import reviewIndex from '../../../layouts/admin/admin_ecommerce_product_review_index.json';
import couponListLayout from '../../../layouts/admin/admin_ecommerce_promotion_coupon_list.json';
import settingsLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import shippingPolicyFormLayout from '../../../layouts/admin/admin_ecommerce_shipping_policy_form.json';
import productPresetModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_preset_manage.json';
import orderPresetModal from '../../../layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_manage.json';
import claimPresetModal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_manage.json';
import claimActivityLog from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_activity_log.json';
import orderActivityLog from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_activity_log.json';
import productActivityLog from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_activity_log.json';
import cancelOrderModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_cancel_order.json';
import claimRequestModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_admin_claim_request.json';
import privacyTargetsModal from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_privacy_targets.json';
import extraFeeTemplateModal from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json';

type Node = Record<string, any>;

interface GridCase {
  /** 화면 이름(시험 제목용) */
  screen: string;
  /** 표 노드를 담은 레이아웃 JSON */
  layout: Node;
  /** 표가 묶인 목록 데이터소스 */
  source: string;
  /** 빈 문구 다국어 키 */
  emptyKey: string;
}

const CASES: GridCase[] = [
  { screen: '상품 목록', layout: productGrid, source: 'products', emptyKey: 'sirsoft-ecommerce.admin.product.table.no_data' },
  { screen: '주문 목록', layout: orderGrid, source: 'orders', emptyKey: 'sirsoft-ecommerce.admin.order.message.no_orders' },
  { screen: '클레임 목록', layout: claimGrid, source: 'claims', emptyKey: 'sirsoft-ecommerce.admin.claim.message.no_claims' },
  { screen: '쿠폰 목록', layout: couponGrid, source: 'coupons', emptyKey: 'sirsoft-ecommerce.admin.promotion_coupon.table.empty' },
  { screen: '쿠폰 발급 내역 창', layout: couponIssueModal, source: 'couponIssues', emptyKey: 'sirsoft-ecommerce.admin.promotion_coupon.modal.issue_history.empty' },
  { screen: '배송정책 목록', layout: shippingPolicyGrid, source: 'shipping_policies', emptyKey: 'sirsoft-ecommerce.admin.shipping_policy.empty.title' },
  { screen: '마일리지 내역', layout: mileageTable, source: 'transactions', emptyKey: 'sirsoft-ecommerce.admin.mileage_transactions.message.empty' },
  { screen: '회원 배송지 목록', layout: addressGrid, source: 'addresses', emptyKey: 'sirsoft-ecommerce.admin.user_addresses.table.empty' },
  { screen: '장바구니 목록', layout: cartIndex, source: 'carts', emptyKey: 'sirsoft-ecommerce.admin.carts.table.empty' },
  { screen: '상품 문의 목록', layout: inquiryIndex, source: 'inquiries', emptyKey: 'sirsoft-ecommerce.admin.inquiries.table.empty' },
  { screen: '찜 목록', layout: wishlistIndex, source: 'wishlists', emptyKey: 'sirsoft-ecommerce.admin.wishlists.table.empty' },
  { screen: '상품 후기 목록', layout: reviewIndex, source: 'reviews', emptyKey: 'sirsoft-ecommerce.admin.product_review.table.empty' },
];

/** 스텁이 쓰는 기본 빈 문구 — 실제 DataGrid 의 `t('common.no_data')` 자리 */
const DEFAULT_EMPTY = '__default_empty__';

/**
 * 트리에서 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param out 결과 배열
 * @returns 찾은 노드 목록
 */
function findAll(node: any, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
    return out;
  }
  if (predicate(node)) out.push(node);
  for (const value of Object.values(node)) findAll(value, predicate, out);
  return out;
}

/**
 * 레이아웃에서 표 노드 하나를 꺼낸다.
 *
 * @param layout 레이아웃 JSON
 * @returns DataGrid 노드
 */
function gridOf(layout: Node): Node {
  const grids = findAll(layout, (n) => n.name === 'DataGrid');
  expect(grids).toHaveLength(1);
  return grids[0];
}

/**
 * 점 경로 다국어 키를 중첩 객체로 만든다. 값은 키를 알아볼 수 있는 표지 문자열이다.
 *
 * @param keys 다국어 키 목록
 * @returns 번역 사전
 */
function translationsFor(keys: string[]): Record<string, any> {
  const root: Record<string, any> = {};
  for (const key of keys) {
    const parts = key.split('.');
    let cursor = root;
    parts.slice(0, -1).forEach((part) => {
      cursor[part] = cursor[part] ?? {};
      cursor = cursor[part];
    });
    cursor[parts[parts.length - 1]] = `빈문구:${key}`;
  }
  return root;
}

/**
 * 목록 응답 봉투.
 *
 * @param rows 행 배열
 * @returns 데이터소스 값
 */
function listResponse(rows: Node[]) {
  return {
    data: {
      data: rows,
      abilities: {},
      pagination: { current_page: 1, per_page: 20, last_page: 1, total: rows.length, has_more_pages: false },
    },
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'DataGrid', ({ data, emptyMessage }: any) => {
    const rows: any[] = Array.isArray(data) ? data : [];
    return React.createElement(
      'div',
      { 'data-testid': 'grid' },
      rows.length > 0
        ? rows.map((row, index) => React.createElement('div', { key: index, 'data-testid': 'grid-row' }, String(row.id)))
        : React.createElement('div', { 'data-testid': 'grid-empty' }, emptyMessage ?? DEFAULT_EMPTY)
    );
  });
});

/**
 * 표 노드 하나를 데이터소스 값과 함께 렌더한다.
 *
 * @param gridCase 화면
 * @param value 데이터소스 값 — `undefined` 는 「받는 중」(엔진이 화면을 먼저 그릴 때 넣는 값)
 * @returns 렌더 결과와 정리 함수
 */
async function renderGrid(gridCase: GridCase, value: unknown) {
  const { meta, ...grid } = gridOf(gridCase.layout);
  const utils = createLayoutTest(
    { version: '1.0.0', layout_name: `test/loading-empty/${gridCase.source}`, components: [grid] } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: translationsFor([gridCase.emptyKey]),
      // 표현식 안 `$t('…')` 은 렌더 컨텍스트의 템플릿·로케일로 사전을 고른다 — 실제 앱은 이 값을 싣는다
      initialData: { [gridCase.source]: value, $templateId: 'test-template', $locale: 'ko' },
    }
  );
  const { container } = await utils.render();
  const empty = container.querySelector('[data-testid="grid-empty"]');
  return {
    container,
    emptyText: empty ? (empty.textContent ?? '') : null,
    rowCount: container.querySelectorAll('[data-testid="grid-row"]').length,
    blurred: container.querySelector('.blur-sm') !== null,
    cleanup: utils.cleanup,
  };
}

describe('[case:components-datagrid-empty-message-while-loading] 관리자 이커머스 목록 — 받는 중에는 빈 문구가 없다', () => {
  /**
   * 목록을 아직 받지 못했으면(값 `undefined`) 「없음」 문구를 그리지 않고 표를 흐리게 덮는다.
   *
   * @scenario load_state=loading
   * @effects loading_hides_empty_message, loading_blurs_grid
   */
  it.each(CASES)('$screen: 받는 중에는 빈 문구가 없고 표가 흐려진다', async (gridCase) => {
    const result = await renderGrid(gridCase, undefined);

    expect(result.rowCount).toBe(0);
    expect(result.emptyText).toBe('');
    expect(result.blurred).toBe(true);
    result.cleanup();
  });

  /**
   * 받은 결과가 0건이면 그 화면의 빈 문구가 보이고 흐림이 풀린다.
   *
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_message
   */
  it.each(CASES)('$screen: 받은 뒤 0건이면 빈 문구가 보인다', async (gridCase) => {
    const result = await renderGrid(gridCase, listResponse([]));

    expect(result.rowCount).toBe(0);
    expect(result.emptyText).toBe(`빈문구:${gridCase.emptyKey}`);
    expect(result.blurred).toBe(false);
    result.cleanup();
  });

  /**
   * 받은 결과가 있으면 행이 그려지고 빈 문구는 없다.
   *
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_message
   */
  it.each(CASES)('$screen: 받은 뒤 행이 있으면 빈 문구가 없다', async (gridCase) => {
    const result = await renderGrid(gridCase, listResponse([{ id: 11 }, { id: 12 }]));

    expect(result.rowCount).toBe(2);
    expect(result.emptyText).toBeNull();
    expect(result.blurred).toBe(false);
    result.cleanup();
  });

  /**
   * 화면이 처음 받을 때 실패하면 엔진은 그 데이터소스 값을 `null` 로 둔다(「받는 중」이 끝났다는 표시, 오류 내용은
   * `_dataSourceErrors`). 흐림이 풀리고 표는 행 0개로 빈 문구를 그린다 — 이 표들의 오류 자체는 데이터소스 오류 처리가
   * 본문을 오류 화면으로 바꾸거나(`default` → `showErrorPage`) 작성자가 선언한 대체 값(fallback)이 맡는다.
   * 쿠폰 발급 내역 창은 실패하면 빈 문구 대신 창 안 오류 안내를 띄우도록 바꿨다(데이터소스 오류 처리가 남기는 실패 표시) — 아래 별도 시험.
   *
   * @scenario load_state=error
   * @effects error_releases_blur_and_shows_empty_message
   */
  it.each(CASES.filter((c) => c.source !== 'couponIssues'))('$screen: 받기에 실패하면(null) 흐림이 풀리고 빈 문구가 보인다', async (gridCase) => {
    const result = await renderGrid(gridCase, null);

    expect(result.rowCount).toBe(0);
    expect(result.emptyText).toBe(`빈문구:${gridCase.emptyKey}`);
    expect(result.blurred).toBe(false);
    result.cleanup();
  });
});

const LOAD_FAILED_KEY = 'sirsoft-ecommerce.common.error.list_load_failed';
const LOAD_FAILED_TEXT = `빈문구:${LOAD_FAILED_KEY}`;

/**
 * 노드의 하위 트리에서 첫 `$t:` 키를 찾는다(빈 안내 문구 키를 레이아웃에서 그대로 읽기 위해).
 *
 * @param node 노드
 * @returns 다국어 키, 없으면 null
 */
function firstTranslationKey(node: any): string | null {
  for (const n of findAll(node, (x) => typeof x.text === 'string' && x.text.startsWith('$t:'))) {
    return String(n.text).slice(3).split('|')[0];
  }
  return null;
}

/**
 * 레이아웃에서 id 로 노드 하나를 꺼낸다.
 *
 * @param layout 레이아웃 JSON
 * @param id 노드 id(`data-ref`)
 * @returns 노드
 */
function nodeById(layout: Node, id: string): Node {
  const found = findAll(layout, (n) => n.id === id);
  expect(found, `노드 ${id}`).toHaveLength(1);
  const { meta, ...node } = found[0];
  return node;
}

/**
 * 레이아웃 노드들을 데이터소스 값·전역 상태와 함께 렌더하고 화면 글자를 돌려준다.
 *
 * @param nodes 렌더할 노드
 * @param keys 번역할 다국어 키
 * @param data 데이터 컨텍스트에 실을 값
 * @param global 전역 상태
 * @returns 화면 글자·행 수·정리 함수
 */
async function renderNodes(nodes: Node[], keys: string[], data: Record<string, unknown>, global: Record<string, unknown> = {}) {
  const utils = createLayoutTest(
    { version: '1.0.0', layout_name: 'test/load-failed', components: nodes } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: translationsFor([...keys, LOAD_FAILED_KEY]),
      initialState: { _global: global },
      initialData: { ...data, $parent: { _local: {} }, $templateId: 'test-template', $locale: 'ko' },
    }
  );
  const { container } = await utils.render();
  return {
    text: container.textContent ?? '',
    rowCount: container.querySelectorAll('[data-testid="grid-row"]').length,
    blurred: container.querySelector('.blur-sm') !== null,
    cleanup: utils.cleanup,
  };
}

/**
 * 표 행(`<tr>`)은 표 안에서만 그린다.
 *
 * @param node 노드
 * @returns 감싼 노드
 */
function inTable(node: Node): Node {
  return node.name === 'Tr'
    ? { type: 'basic', name: 'Table', children: [{ type: 'basic', name: 'Tbody', children: [node] }] }
    : node;
}

describe('[case:components-datagrid-empty-message-while-loading] 받기 실패 — 「없음」 대신 오류 안내', () => {
  beforeEach(() => {
    registry.register('composite', 'Modal', ({ children }: any) => React.createElement('div', null, children));
    registry.register('composite', 'Badge', ({ children }: any) => React.createElement('span', null, children));
    registry.register('composite', 'Pagination', () => React.createElement('nav', { 'data-testid': 'pagination' }));
    registry.register('composite', 'ActionMenu', () => null);
    registry.register('basic', 'Icon', () => null);
  });

  describe('쿠폰 발급 내역 창 (다시 받기로만 받는 목록)', () => {
    const issueSource = (couponListLayout as any).data_sources.find((d: any) => d.id === 'couponIssues');
    const modalNode = findAll(couponIssueModal, (n) => n.name === 'Modal')[0];
    const ISSUE_EMPTY_KEY = 'sirsoft-ecommerce.admin.promotion_coupon.modal.issue_history.empty';

    /**
     * 다시 받기(refetch)가 실패하면 엔진은 처음 받기와 같이 값을 `null` 로 두고 `_dataSourceErrors` 에 기록한다. 다른 쿠폰으로
     * 다시 받기를 시작하면 받기 전 값(`undefined`)으로 돌린다. 이 계약 이전 엔진은 실패해도 값을 그대로 둬 앞 쿠폰 내역이 남았다.
     * 창의 오류 안내는 엔진 판과 무관하게 데이터소스 오류 처리가 남기는 실패 표시로 가른다 — 실패하면 남기고, 성공하면 내리고,
     * 창을 열 때 지운다.
     *
     * @scenario load_state=refetch_error
     * @effects refetch_failure_flag_set_by_source_error_handling
     */
    it('데이터소스가 실패하면 표시를 남기고, 성공하면 내리고, 창을 열 때 지운다', () => {
      expect(issueSource.errorHandling?.default).toMatchObject({ handler: 'setState', params: { target: 'global', issueHistoryLoadFailed: true } });
      const onSuccess = [].concat(issueSource.onSuccess ?? []);
      expect(onSuccess).toContainEqual(expect.objectContaining({ handler: 'setState', params: expect.objectContaining({ target: 'global', issueHistoryLoadFailed: false }) }));

      // 창을 여는 곳(행 메뉴 「발급 내역」·발급수량 숫자)마다 지운다
      const openers = findAll(couponGrid, (n) => n.handler === 'setState' && n.params?.issueHistoryCoupon !== undefined);
      expect(openers.length).toBeGreaterThanOrEqual(2);
      for (const opener of openers) expect(opener.params.issueHistoryLoadFailed).toBe(false);
    });

    /**
     * 실패 표시가 있으면 「발급 이력이 없습니다」 대신 오류 안내가 보이고, 행·총건수·쪽 나눔·흐림이 사라진다.
     * 지금 엔진의 실패 값(`null`)과, 값이 그대로 남는 경우(받기 전 `undefined`·앞 쿠폰 내역 — 이전 엔진) 모두 본다.
     *
     * @scenario load_state=refetch_error
     * @effects refetch_failure_shows_error_instead_of_empty
     */
    it.each([
      ['값이 null 인 창(지금 엔진의 실패)', null],
      ['받기 전 값이 남은 창', undefined],
      ['앞 쿠폰 내역이 남은 창', listResponse([{ id: 1 }, { id: 2 }])],
    ])('%s에서 다시 받기가 실패하면 오류 안내만 보인다', async (_label, value) => {
      const result = await renderNodes(modalNode.children, [ISSUE_EMPTY_KEY], { couponIssues: value }, { issueHistoryLoadFailed: true });

      expect(result.text).toContain(LOAD_FAILED_TEXT);
      expect(result.text).not.toContain(`빈문구:${ISSUE_EMPTY_KEY}`);
      expect(result.rowCount).toBe(0);
      expect(result.blurred).toBe(false);
      result.cleanup();
    });

    /**
     * 실패 뒤 다시 받기에 성공하면(표시 내림) 안내가 사라지고 행이 보인다. 0건이면 빈 문구가 보인다.
     *
     * @scenario load_state=loaded_rows
     * @effects refetch_failure_shows_error_instead_of_empty
     */
    it.each([
      ['행 있음', listResponse([{ id: 7 }]), 1, false],
      ['0건', listResponse([]), 0, true],
    ])('실패 뒤 다시 받기에 성공하면(%s) 오류 안내가 없다', async (_label, value, rows, showsEmpty) => {
      const result = await renderNodes(modalNode.children, [ISSUE_EMPTY_KEY], { couponIssues: value }, { issueHistoryLoadFailed: false });

      expect(result.text).not.toContain(LOAD_FAILED_TEXT);
      expect(result.rowCount).toBe(rows);
      expect(result.text.includes(`빈문구:${ISSUE_EMPTY_KEY}`)).toBe(showsEmpty);
      result.cleanup();
    });
  });

  /** 표가 아닌 빈 안내(창·탭·구획) — 실패 신호는 값 `null` 이거나, 다시 받기로만 받는 목록(창)의 전역 실패 표시다(실패 값도 `null`) */
  const SITES = [
    { site: '상품 목록 프리셋 관리 창', layout: productPresetModal, source: null, emptyId: 'preset_empty_message', failedId: 'preset_load_failed', loaded: { data: [] }, signal: 'null' },
    { site: '주문 목록 프리셋 관리 창', layout: orderPresetModal, source: null, emptyId: 'preset_empty_message', failedId: 'preset_load_failed', loaded: { data: [] }, signal: 'null' },
    { site: '클레임 목록 프리셋 관리 창', layout: claimPresetModal, source: null, emptyId: 'preset_empty_message', failedId: 'preset_load_failed', loaded: { data: [] }, signal: 'null' },
    { site: '클레임 상세 활동 로그', layout: claimActivityLog, source: 'claim_logs', emptyId: 'log_empty', failedId: 'log_load_failed', loaded: { data: { data: [] } }, signal: 'null' },
    { site: '주문 상세 활동 로그', layout: orderActivityLog, source: 'order_logs', emptyId: 'log_empty', failedId: 'log_load_failed', loaded: { data: { data: [] } }, signal: 'null' },
    { site: '상품 수정 처리 이력(표)', layout: productActivityLog, source: 'activity_logs', emptyId: 'activity_log_empty', failedId: 'activity_log_load_failed', loaded: { data: { data: [] } }, signal: 'null' },
    { site: '상품 수정 처리 이력(카드)', layout: productActivityLog, source: 'activity_logs', emptyId: 'activity_log_empty_mobile', failedId: 'activity_log_load_failed_mobile', loaded: { data: { data: [] } }, signal: 'null' },
    { site: '주문 취소 창 환불 은행 안내', layout: cancelOrderModal, source: 'paymentSettings', emptyId: 'cancel_refund_bank_empty_hint', failedId: 'cancel_refund_bank_load_failed', loaded: { data: { order_settings: { banks: [] } } }, signal: 'null' },
    { site: '직권 접수 창 환불 은행 안내', layout: claimRequestModal, source: 'paymentSettings', emptyId: 'claim_request_refund_bank_empty_hint', failedId: 'claim_request_refund_bank_load_failed', loaded: { data: { order_settings: { banks: [] } } }, signal: 'null' },
    { site: '개인정보 보유기간 대상 창', layout: privacyTargetsModal, source: 'privacyTargets', emptyId: 'privacy_targets_empty', failedId: 'privacy_targets_load_failed', loaded: { data: { data: [] } }, signal: 'flag', flag: { privacyTargets: { loadFailed: true } } },
    { site: '추가배송비 템플릿 창', layout: extraFeeTemplateModal, source: 'extra_fee_templates', emptyId: 'empty_state', failedId: 'extra_fee_templates_load_failed', loaded: { data: { data: [] } }, signal: 'flag', flag: { extraFeeTemplatesLoadFailed: true } },
  ] as const;

  /**
   * 실패 신호가 `null` 인 곳은 데이터소스 이름을 실패 안내 조건(`{{소스 === null}}`)에서 읽는다 — 같은 이름을 쓰는 화면끼리
   * 이름을 고쳐도(데이터소스 이름 정리) 시험이 따라간다.
   *
   * @param s 자리
   * @returns 데이터소스 이름
   */
  function sourceOf(s: (typeof SITES)[number]): string {
    if (s.source) return s.source;
    const match = String(nodeById(s.layout, s.failedId).if ?? '').match(/^\{\{\s*([A-Za-z_]\w*) === null\s*\}\}$/);
    expect(match, `${s.site} 실패 조건`).not.toBeNull();
    return match![1];
  }

  /**
   * 받는 중(값 없음)에는 빈 안내도 오류 안내도 없다.
   *
   * @scenario load_state=loading
   * @effects loading_hides_empty_message
   */
  it.each(SITES)('$site: 받는 중에는 빈 안내가 없다', async (s) => {
    const empty = nodeById(s.layout, s.emptyId);
    const result = await renderNodes([inTable(nodeById(s.layout, s.failedId)), inTable(empty)], [firstTranslationKey(empty)!], { [sourceOf(s)]: undefined });

    expect(result.text).toBe('');
    result.cleanup();
  });

  /**
   * 받은 결과가 0건이면 그 자리의 빈 안내가 보이고 오류 안내는 없다.
   *
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_message
   */
  it.each(SITES)('$site: 받은 뒤 0건이면 빈 안내가 보인다', async (s) => {
    const empty = nodeById(s.layout, s.emptyId);
    const key = firstTranslationKey(empty)!;
    const result = await renderNodes([inTable(nodeById(s.layout, s.failedId)), inTable(empty)], [key], { [sourceOf(s)]: s.loaded });

    expect(result.text).toContain(`빈문구:${key}`);
    expect(result.text).not.toContain(LOAD_FAILED_TEXT);
    result.cleanup();
  });

  /**
   * 받기에 실패하면 빈 안내 대신 오류 안내가 보인다. 실패 값은 `null` 이고, 다시 받기로만 받는 목록은 전역 실패 표시도 선다.
   *
   * @scenario load_state=error
   * @effects failure_shows_error_instead_of_empty
   */
  it.each(SITES)('$site: 받기에 실패하면 오류 안내가 보이고 빈 안내는 없다', async (s) => {
    const empty = nodeById(s.layout, s.emptyId);
    const key = firstTranslationKey(empty)!;
    const result = s.signal === 'null'
      ? await renderNodes([inTable(nodeById(s.layout, s.failedId)), inTable(empty)], [key], { [sourceOf(s)]: null })
      : await renderNodes([inTable(nodeById(s.layout, s.failedId)), inTable(empty)], [key], { [sourceOf(s)]: null }, (s as any).flag);

    expect(result.text).toContain(LOAD_FAILED_TEXT);
    expect(result.text).not.toContain(`빈문구:${key}`);
    result.cleanup();
  });

  /**
   * 다시 받기로만 받는 목록(개인정보 대상·추가배송비 템플릿)은 데이터소스 오류 처리가 실패 표시를 남기고 성공하면 내린다.
   *
   * @scenario load_state=refetch_error
   * @effects refetch_failure_flag_set_by_source_error_handling
   */
  it.each([
    ['개인정보 보유기간 대상', settingsLayout, 'privacyTargets', 'privacyTargets.loadFailed'],
    ['추가배송비 템플릿', shippingPolicyFormLayout, 'extra_fee_templates', 'extraFeeTemplatesLoadFailed'],
  ])('%s 데이터소스가 실패 표시를 남기고 성공하면 내린다', (_label, layout, id, key) => {
    const source = (layout as any).data_sources.find((d: any) => d.id === id);
    expect(source.errorHandling?.default).toMatchObject({ handler: 'setState', params: { target: 'global', [key]: true } });
    expect([].concat(source.onSuccess ?? [])).toContainEqual(expect.objectContaining({ handler: 'setState', params: expect.objectContaining({ target: 'global', [key]: false }) }));
  });

  /**
   * 쇼핑몰 환경설정은 설정을 못 받으면 폼이 빈 채로 서서 탭마다 빈 안내가 뜬다 — 403 밖의 실패도 오류 화면으로 바꾼다.
   *
   * @scenario load_state=error
   * @effects failure_shows_error_instead_of_empty
   */
  it('쇼핑몰 환경설정은 403 밖의 실패도 오류 화면으로 바꾼다', () => {
    const source = (settingsLayout as any).data_sources.find((d: any) => d.id === 'settings');
    // 처음 받기 실패는 본문 오류 화면, 다시 받기 실패(`error.refetch`)는 토스트로 받아 둔 값을 지킨다 (engine-v1.82.0 문맥)
    const branches = source.errorHandling?.default?.conditions ?? [];
    expect(source.errorHandling?.default?.handler).toBe('conditions');
    expect(branches.find((b: any) => b.if === '{{error.refetch}}')?.then).toMatchObject({ handler: 'toast' });
    expect(branches.find((b: any) => b.if === undefined)?.then).toMatchObject({ handler: 'showErrorPage', params: { target: 'content' } });
  });
});

describe('관리자 이커머스 목록 — 전수', () => {
  const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts/admin');

  /**
   * 받는 중 상태가 화면에 나오지 않아 규칙에서 빠지는 표 — 사유와 함께 고정한다(늘어나면 여기서 드러난다).
   * blocking 은 화면보다 먼저 받고, `_local` 은 데이터소스가 아니라 화면 상태다.
   */
  const EXPECTED_EXCLUSIONS = [
    'partials/admin_ecommerce_claim_detail/_partial_claim_items.json (claim: blocking)',
    'partials/admin_ecommerce_order_detail/_partial_order_info.json (order: blocking)',
    'partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json (_local: 화면 상태)',
    'partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json (_local: 화면 상태)',
    'partials/admin_ecommerce_promotion_coupon_form/_partial_usage_conditions.json (_local: 화면 상태)',
  ];

  /**
   * 디렉터리 아래 JSON 레이아웃을 모두 모은다.
   *
   * @param dir 디렉터리
   * @param out 결과
   * @returns 파일 경로 목록
   */
  function walk(dir: string, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full, out);
      else if (entry.name.endsWith('.json')) out.push(full);
    }
    return out;
  }

  /**
   * 모집단은 「화면이 그려진 뒤 받는(blocking 이 아닌) 데이터소스에 묶인 관리자 DataGrid 전부」다 —
   * 빈 문구를 적지 않은 표도 들어간다(그 표는 컴포넌트 기본 문구 「데이터가 없습니다」를 받는 중에 그린다).
   * 각 표는 표본(CASES)에 있고, 빈 문구를 적고 그 문구를 받은 뒤에만 보이며, 받는 중 흐림을 가져야 한다.
   *
   * 기본 문구를 쓰는 표를 컴포넌트가 스스로 처리하게 되면(받는 중에는 기본 문구를 그리지 않음) 이 단언의
   * 「빈 문구를 적어야 한다」 갈래를 그 동작 확인으로 바꾼다. 레이아웃이 적은 조건식은 그 처리와 겹쳐도 해가 없다.
   *
   * @scenario load_state=loading
   * @effects every_progressive_list_grid_gated
   */
  it('화면 뒤에 받는 데이터소스에 묶인 관리자 표는 모두 받는 중 규칙을 지킨다', () => {
    const files = walk(LAYOUT_ROOT);

    // 데이터소스 id → 선언된 받기 방식들(같은 id 를 여러 화면이 다르게 선언할 수 있다)
    const strategies = new Map<string, Set<string>>();
    for (const file of files) {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const source of json.data_sources ?? []) {
        const set = strategies.get(source.id) ?? new Set<string>();
        set.add(source.loading_strategy ?? 'progressive');
        strategies.set(source.id, set);
      }
    }

    const covered = new Set(CASES.map((c) => c.source));
    const population = new Set<string>();
    const exclusions: string[] = [];
    const offenders: string[] = [];

    for (const file of files) {
      const rel = path.relative(LAYOUT_ROOT, file).split(path.sep).join('/');
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const grid of findAll(json, (n) => n.name === 'DataGrid')) {
        // 식 안에서 선언된 데이터소스 이름을 찾는다(실패 표시 같은 `_global` 조건이 앞에 와도 표가 읽는 목록을 고른다)
        const expr = String(grid.props?.data ?? '');
        const identifiers = expr.match(/(?<![\w.$])[A-Za-z_$]\w*/g) ?? [];
        const source = identifiers.find((id) => strategies.has(id)) ?? expr.match(/^\{\{\s*([A-Za-z_$]\w*)/)?.[1] ?? '?';
        if (source.startsWith('_')) {
          exclusions.push(`${rel} (${source}: 화면 상태)`);
          continue;
        }
        const declared = strategies.get(source);
        if (!declared) {
          offenders.push(`${rel} (${source}: 선언된 데이터소스를 찾지 못함)`);
          continue;
        }
        if ([...declared].every((s) => s === 'blocking')) {
          exclusions.push(`${rel} (${source}: blocking)`);
          continue;
        }

        population.add(source);
        const emptyMessage = grid.props?.emptyMessage;
        if (emptyMessage === undefined) {
          offenders.push(`${rel} (${source}: 빈 문구 미지정 — 받는 중에 기본 문구가 보임)`);
          continue;
        }
        // 「받는 중」은 데이터소스 값이 아직 없는 것이다 — 엔진의 흐림 판정(blur_until_loaded)과 같은 기준
        // 실패 표시를 함께 거는 표(`… === undefined || …LoadFailed === true ? '' : …`)도 같은 규칙이다
        const message = String(emptyMessage);
        if (!(message.startsWith(`{{${source} === undefined`) && message.includes(`? '' : $t(`))) {
          offenders.push(`${rel} (${source}: 빈 문구가 받는 중에도 보임)`);
        }
        if (!grid.blur_until_loaded) offenders.push(`${rel} (${source}: 받는 중 흐림 없음)`);
        if (!covered.has(source)) offenders.push(`${rel} (${source}: 렌더 표본에 없음)`);
      }
    }

    expect(offenders).toEqual([]);
    expect(exclusions.sort()).toEqual([...EXPECTED_EXCLUSIONS].sort());
    // 모집단과 렌더 표본이 같은 12개여야 표본이 낡지 않는다
    expect([...population].sort()).toEqual([...covered].sort());
  });

  /**
   * 오류 처리(`errorHandling.default`·`onError`)도 대체 값(`fallback`)도 없는 데이터소스를 읽는 빈 안내는 실패를 가려야 한다 —
   * 조건에 `<소스> !== null`(처음 받기 실패) 또는 실패 표시(`…LoadFailed`·`loadFailed`, 다시 받기 실패)를 둔다.
   * 그렇지 않으면 받기에 실패한 채 「없습니다」를 보여 운영자가 「0건」으로 읽는다.
   *
   * @scenario load_state=error
   * @effects every_unguarded_source_empty_state_masks_failure
   */
  it('오류 처리·대체 값이 없는 데이터소스를 읽는 빈 안내는 모두 실패를 가린다', () => {
    const files = walk(LAYOUT_ROOT);
    const unguarded = new Set<string>();
    const guarded = new Set<string>();
    for (const file of files) {
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const source of json.data_sources ?? []) {
        if (source.type && source.type !== 'api') continue;
        const safe = source.fallback !== undefined || source.onError !== undefined || source.errorHandling?.default !== undefined;
        (safe ? guarded : unguarded).add(source.id);
      }
    }
    // 같은 id 를 화면마다 따로 선언하기도 한다(예: categories) — partial 이 어느 화면 소속인지 이 검사는 모르므로,
    // 선언이 모두 막지 않은 id 만 본다(한쪽이라도 막았으면 그 화면의 오류 처리가 오류 화면을 띄운다)
    const targets = [...unguarded].filter((id) => !guarded.has(id));

    const EMPTY_PATTERN = /length\s*===\s*0|\.length\s*<\s*1/;
    const offenders: string[] = [];
    for (const file of files) {
      const rel = path.relative(LAYOUT_ROOT, file).split(path.sep).join('/');
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const node of findAll(json, (n) => typeof n.if === 'string' && EMPTY_PATTERN.test(n.if))) {
        for (const id of targets) {
          if (!new RegExp(`(^|[^\\w.$])${id}(\\?\\.|\\.)`).test(node.if)) continue;
          const masked = node.if.includes(`${id} !== null`) || /LoadFailed !== true|loadFailed !== true/.test(node.if);
          if (!masked) offenders.push(`${rel} (${node.id ?? '?'}: ${id})`);
        }
      }
    }

    expect(offenders).toEqual([]);
  });
});
