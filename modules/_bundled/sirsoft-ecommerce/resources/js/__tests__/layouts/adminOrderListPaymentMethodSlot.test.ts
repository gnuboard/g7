/**
 * @file adminOrderListPaymentMethodSlot.test.ts
 * @description 관리자 주문 목록 결제수단 칸이 결제 플러그인용 표시 자리를 열어 두는지 잰다.
 *
 * 이 모듈은 PG 를 모른다. 종전에는 결제 플러그인 셋이 테스트 결제 배지 하나를 얹으려고 11개 컬럼 전체를
 * 복사해 `inject_props` 로 덮었고, 이 모듈이 컬럼을 고치면 그 사본에 묻혀 화면에서 사라졌다.
 * 이제 컬럼 목록은 이 레이아웃이 단일 출처이고, 플러그인은 결제수단 칸의 확장점에 자기 배지만 끼운다.
 * 간편결제 원 수단 라벨은 플러그인이 서버 필터 훅으로 `payment_method_display_label` 을 채운다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');
const datagrid = JSON.parse(
  fs.readFileSync(
    path.join(
      REPO_ROOT,
      'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json',
    ),
    'utf-8',
  ),
);

/**
 * 트리에서 DataGrid 의 columns 를 찾는다.
 *
 * @param node 탐색 노드
 * @returns 컬럼 목록 (없으면 null)
 */
function findColumns(node: any): any[] | null {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findColumns(child);
      if (found) return found;
    }
    return null;
  }
  if (node.name === 'DataGrid' && Array.isArray(node.props?.columns)) return node.props.columns;
  for (const value of Object.values(node)) {
    const found = findColumns(value);
    if (found) return found;
  }
  return null;
}

/**
 * 트리의 모든 노드를 평탄화한다.
 *
 * @param node 탐색 노드
 * @param acc 누적 배열
 * @returns 노드 목록
 */
function flatten(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    node.forEach((child) => flatten(child, acc));
    return acc;
  }
  acc.push(node);
  Object.values(node).forEach((value) => flatten(value, acc));
  return acc;
}

const columns = findColumns(datagrid) ?? [];
const paymentMethod = columns.find((column) => column.field === 'payment_method');

describe('관리자 주문 목록 — 결제수단 칸의 결제 플러그인 자리', () => {
  // @scenario payment_plugin=none
  // @effects order_list_payment_method_cell_opens_a_badge_slot
  it('결제수단 칸에 표시 자리 확장점을 하나 연다', () => {
    expect(paymentMethod).toBeTruthy();
    const slots = flatten(paymentMethod.cellChildren).filter((node) => node.type === 'extension_point');

    expect(slots.map((node) => node.name)).toEqual(['admin_order_list_payment_method_badges']);
  });

  // @scenario payment_plugin=none
  // @effects order_list_payment_method_cell_opens_a_badge_slot
  it('라벨은 플러그인이 채운 표시 라벨을 먼저, 없으면 기본 라벨을 쓴다', () => {
    const texts = flatten(paymentMethod.cellChildren).map((node) => node.text).filter(Boolean);

    expect(texts).toContain('{{row.payment?.payment_method_display_label ?? row.payment?.payment_method_label}}');
  });

  // @scenario payment_plugin=none
  // @effects order_list_payment_method_cell_opens_a_badge_slot
  it('어떤 PG 의 데이터 소스·번역 키도 모른다', () => {
    const json = JSON.stringify(datagrid);

    // 특정 PG 확장 이름은 조각으로 이어 붙인다 — 모듈 소스 전체에서 PG 이름을 찾는 정적 검사
    // (EcommerceSettingsExchangeRateProviderTest)가 이 테스트 파일까지 훑는다.
    const forbidden = new RegExp(['_test_map', 'easy_pay_display_map', 'sirsoft-' + 'pay_', 'sirsoft-' + 'tosspayments'].join('|'));
    expect(json).not.toMatch(forbidden);
  });
});
