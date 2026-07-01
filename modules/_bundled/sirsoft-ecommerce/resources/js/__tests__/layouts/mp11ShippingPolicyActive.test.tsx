/**
 * @file mp11ShippingPolicyActive.test.tsx
 * @description A11 배송정책 목록 빈 화면 + 상품폼 활성 정책 노출 레이아웃 회귀 테스트
 *
 * 검증 항목:
 * - 배송정책 목록: DataGrid partial(if length>0) ↔ empty_state(if !loading && length===0) 상보 경계
 * - 상품폼: shipping_policies 데이터소스 params.is_active=true
 * - 상품폼: shippingPolicyOptions computed union (비활성 부여 정책 포함)
 * - 상품폼: 비활성 정책 안내 배너 + assignedPolicyInactive computed
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const layoutsDir = path.resolve(__dirname, '../../../../resources/layouts/admin');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(layoutsDir, relPath), 'utf8'));
}

/** 객체 트리 전체(slots/children/cases 등 모든 키)를 재귀 순회하며 조건 노드를 수집한다. */
function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (!Array.isArray(node) && predicate(node)) acc.push(node);
  for (const key of Object.keys(node)) {
    collectNodes(node[key], predicate, acc);
  }
  return acc;
}

const listLayout = loadJson('admin_ecommerce_shipping_policy_list.json');
const datagridPartial = loadJson('partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json');
const productForm = loadJson('admin_ecommerce_product_form.json');
const shippingPartial = loadJson('partials/admin_ecommerce_product_form/_partial_shipping.json');

// engine-v1.50.4 / 빈 상태 레퍼런스 일관화: DataGrid 가 emptyMessage 로 빈 상태를 직접 처리하고
// 별도 empty_state 카드 및 데이터-존재 if 분기는 제거됨 (쿠폰/주문/상품 리스트와 동일 패턴).
describe('A11 배송정책 목록 빈 화면 경계 (DataGrid emptyMessage 단독)', () => {
  it('DataGrid partial 은 if 분기 없이 항상 렌더된다', () => {
    const datagridRefs = collectNodes(
      listLayout,
      (n) => n.partial === 'partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json',
    );
    expect(datagridRefs.length).toBe(1);
    expect(datagridRefs[0].if).toBeUndefined();
  });

  it('별도 empty_state 카드는 제거되고 DataGrid emptyMessage 가 빈 상태를 처리한다', () => {
    const emptyState = collectNodes(listLayout, (n) => n.id === 'empty_state');
    expect(emptyState.length).toBe(0);
    expect(datagridPartial.props.emptyMessage).toContain('empty.title');
  });
});

describe('A11 상품폼 활성 배송정책 노출', () => {
  it('shipping_policies 데이터소스에 is_active=true 필터가 적용된다', () => {
    const ds = (productForm.data_sources ?? []).find((d: any) => d.id === 'shipping_policies');
    expect(ds).toBeTruthy();
    expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/admin/shipping-policies');
    expect(ds.params?.is_active).toBe(true);
  });

  it('shippingPolicyOptions computed 가 비활성 부여 정책을 union 한다', () => {
    const computed = productForm.computed ?? {};
    expect(computed.shippingPolicyOptions).toBeTruthy();
    expect(computed.shippingPolicyOptions).toContain('product.data.shipping_policy');
    expect(computed.assignedPolicyInactive).toContain('is_active === false');
  });

  it('상품폼 배송 partial 은 computed union 목록을 참조한다 (raw 활성목록 직접 참조 아님)', () => {
    const iterations = collectNodes(
      shippingPartial,
      (n) => n.iteration && typeof n.iteration.source === 'string',
    );
    const policyIterations = iterations.filter((n) => n.iteration.item_var === 'policy');
    expect(policyIterations.length).toBeGreaterThanOrEqual(1);
    for (const it of policyIterations) {
      expect(it.iteration.source).toContain('_computed.shippingPolicyOptions');
    }
  });

  it('비활성 정책 안내 배너가 assignedPolicyInactive 조건으로 노출된다', () => {
    const warning = collectNodes(shippingPartial, (n) => n.id === 'shipping_policy_inactive_warning');
    expect(warning.length).toBe(1);
    expect(warning[0].if).toContain('_computed.assignedPolicyInactive');
  });
});
