/**
 * @file productFormSelectorForbidden.test.tsx
 * @description 상품 등록·수정 폼 — 선택지 데이터 소스가 403 이어도 폼은 그리고 그 칸만 비운다
 *
 * 상품 등록·수정 권한은 있지만 배송정책·상품 라벨 같은 선택지 조회 권한이 없는 운영자에게
 * 폼 본문 전체가 「403 Access Denied」 로 바뀌던 결함(전수 실측 S5, 제품 결정 (b))의 회귀 테스트.
 *
 * 선택지 소스 7개(분류·브랜드·고시 템플릿·공통정보·배송정책·라벨·등급)는 모두
 *  1. 403 을 상위(레이아웃·템플릿) 오류 화면으로 전파하지 않고(suppress),
 *  2. fallback 으로 빈 목록 + `unavailable` 표지를 싣고,
 *  3. 그 칸 아래에 「… 조회 권한이 없습니다」 안내를 그린다 — 「등록된 것이 없다」 안내와 구분한다.
 * 403 이 아닌 실패(500 등)는 종전대로 본문 오류 화면이다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import shell from '../../../layouts/admin/admin_ecommerce_product_form.json';
import basicInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_basic_info.json';
import productNotice from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_notice.json';
import commonInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_common_info.json';
import shipping from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_shipping.json';
import otherInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_other_info.json';

type Node = Record<string, any>;

interface SelectorCase {
  /** 데이터 소스 id */
  source: string;
  /** 칸이 들어 있는 구획 */
  partial: Node;
  /** 권한 없음 안내 노드 id */
  notice: string;
  /** 「등록된 것이 없다」 빈 상태 노드 id (있는 칸만) */
  empty?: string;
}

const CASES: SelectorCase[] = [
  { source: 'categories', partial: basicInfo, notice: 'category_forbidden_notice' },
  { source: 'brands', partial: basicInfo, notice: 'brand_forbidden_notice' },
  { source: 'notice_templates', partial: productNotice, notice: 'notice_template_forbidden_notice' },
  { source: 'common_infos', partial: commonInfo, notice: 'common_info_forbidden_notice', empty: 'common_info_empty_state' },
  { source: 'shipping_policies', partial: shipping, notice: 'shipping_policy_forbidden_notice', empty: 'shipping_empty_state' },
  { source: 'product_labels', partial: otherInfo, notice: 'label_forbidden_notice', empty: 'no_labels_available' },
  { source: 'roles', partial: otherInfo, notice: 'allowed_roles_forbidden_notice' },
];

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

/**
 * 칸의 안내·빈 상태 노드만 프로브 레이아웃으로 감싼다. 데이터 소스는 셸에서 그대로 가져온다 —
 * 사본을 두면 셸이 바뀌어도 이 테스트만 옛 계약으로 통과한다.
 *
 * @param c 칸
 * @returns 레이아웃 JSON
 */
function probe(c: SelectorCase): Node {
  const components = [c.notice, c.empty]
    .filter((id): id is string => !!id)
    .map((id) => byId(c.partial, id))
    .filter((n): n is Node => !!n);

  return {
    version: '1.0.0',
    layout_name: `test/product-form-${c.source}`,
    data_sources: ((shell as Node).data_sources ?? []).filter((d: Node) => d.id === c.source),
    components,
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
});

/**
 * 데이터 소스 id 로 칸을 찾는다.
 *
 * @param source 데이터 소스 id
 * @returns 칸
 */
function caseOf(source: string): SelectorCase {
  const found = CASES.find((c) => c.source === source);
  if (!found) throw new Error(`unknown source: ${source}`);

  return found;
}

/**
 * 칸 하나에 대한 네 가지 단언을 등록한다.
 *
 * @param c 칸
 * @returns void
 */
function defineSelectorSuite(c: SelectorCase): void {
  /**
   * @effects product_form_selector_forbidden_does_not_replace_form
   */
  it('403 은 오류 화면으로 전파하지 않고, 403 이 아닌 실패는 종전대로 오류 화면이다', () => {
    const ds = ((shell as Node).data_sources ?? []).find((d: Node) => d.id === c.source);

    expect(ds?.errorHandling?.['403']?.handler).toBe('suppress');
    expect(ds?.errorHandling?.default?.handler).toBe('showErrorPage');
  });

  /**
   * @effects product_form_selector_forbidden_falls_back_to_empty
   */
  it('403 이면 빈 목록과 unavailable 표지로 떨어진다', () => {
    const ds = ((shell as Node).data_sources ?? []).find((d: Node) => d.id === c.source);

    expect(ds?.fallback?.data?.data).toEqual([]);
    expect(ds?.fallback?.data?.unavailable).toBe(true);
  });

  /**
   * @effects product_form_selector_forbidden_notice_under_field
   */
  it('403 이면 칸 아래에 권한 없음 안내를 그리고 「등록된 것 없음」 은 그리지 않는다', async () => {
    expect(byId(c.partial, c.notice)).toBeTruthy();

    const utils = createLayoutTest(probe(c) as any, {
      componentRegistry: registry as any,
      locale: 'ko',
      initialData: { _computedDefinitions: (shell as Node).computed },
      initialState: { _local: { form: {} } },
    });
    utils.mockApiError(c.source, 403, 'auth.permission_denied');
    await utils.render();

    expect(document.querySelector(`#${c.notice}`)).not.toBeNull();
    if (c.empty) {
      expect(document.querySelector(`#${c.empty}`)).toBeNull();
    }

    utils.cleanup();
  });

  /**
   * @effects product_form_selector_forbidden_notice_under_field
   */
  it('정상 응답이면 권한 없음 안내를 그리지 않는다', async () => {
    const utils = createLayoutTest(probe(c) as any, {
      componentRegistry: registry as any,
      locale: 'ko',
      initialData: { _computedDefinitions: (shell as Node).computed },
      initialState: { _local: { form: {} } },
    });
    utils.mockApi(c.source, { response: { success: true, data: { data: [] } } });
    await utils.render();

    expect(document.querySelector(`#${c.notice}`)).toBeNull();
    if (c.empty) {
      expect(document.querySelector(`#${c.empty}`)).not.toBeNull();
    }

    utils.cleanup();
  });
}

describe('상품 폼 선택지 소스 403 — 폼은 그리고 그 칸만 비운다', () => {
  /**
   * @scenario source=categories
   */
  describe('categories', () => defineSelectorSuite(caseOf('categories')));

  /**
   * @scenario source=brands
   */
  describe('brands', () => defineSelectorSuite(caseOf('brands')));

  /**
   * @scenario source=notice_templates
   */
  describe('notice_templates', () => defineSelectorSuite(caseOf('notice_templates')));

  /**
   * @scenario source=common_infos
   */
  describe('common_infos', () => defineSelectorSuite(caseOf('common_infos')));

  /**
   * @scenario source=shipping_policies
   */
  describe('shipping_policies', () => defineSelectorSuite(caseOf('shipping_policies')));

  /**
   * @scenario source=product_labels
   */
  describe('product_labels', () => defineSelectorSuite(caseOf('product_labels')));

  /**
   * @scenario source=roles
   */
  describe('roles', () => defineSelectorSuite(caseOf('roles')));
});
