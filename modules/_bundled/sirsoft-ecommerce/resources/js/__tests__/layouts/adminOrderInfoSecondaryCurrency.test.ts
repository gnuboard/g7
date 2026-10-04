/**
 * @file adminOrderInfoSecondaryCurrency.test.ts
 * @description 관리자 주문 상세 상품 표의 보조 통화 줄이 주문의 기준 통화를 빼고 나머지를 보이는지 검증
 *
 * 윗줄(판매가·소계·합계)은 주문의 기준 통화 금액이다. 보조 통화 목록이 관리자가 헤더에서 고른
 * 표시 통화를 빼면, 기준 통화가 그와 다른 상점(기준 엔화·표시 원화)에서 원화가 사라지고 엔화가
 * 두 번 섰다(실측 2026-10-03, ¥4,000 / $34.00 / ¥4,000 / 元 / €).
 *
 * @scenario surface=order_info
 * @effects order_info_secondary_currency_excludes_order_base_currency
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

const REPO_ROOT = path.resolve(__dirname, '../../../../../../..');
const orderInfo = JSON.parse(
  fs.readFileSync(
    path.join(
      REPO_ROOT,
      'modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json',
    ),
    'utf-8',
  ),
);

/**
 * 레이아웃 트리를 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적 배열
 * @returns 모든 객체 노드
 */
function flatten(node: any, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const n of node) flatten(n, acc);
    return acc;
  }
  acc.push(node);
  for (const key of Object.keys(node)) {
    const v = node[key];
    if (v && typeof v === 'object') flatten(v, acc);
  }
  return acc;
}

const iterations = flatten(orderInfo).filter(
  (n) => typeof n.iteration?.source === 'string' && n.iteration.source.includes('mc_'),
);

/**
 * 반복 원본 식을 실제 값으로 평가해 남는 통화 코드를 돌려준다.
 *
 * @param source `{{…}}` 반복 원본 식
 * @param ctx 식이 읽는 값
 * @returns 남는 통화 코드 목록
 */
function evaluate(source: string, ctx: { row: any; order: any; _global: any }): string[] {
  const expr = source.replace(/^\{\{|\}\}$/g, '');
  // eslint-disable-next-line no-new-func
  const entries = new Function('row', 'order', '_global', `return (${expr});`)(ctx.row, ctx.order, ctx._global);
  return (entries as Array<[string, unknown]>).map(([code]) => code);
}

const mc = { KRW: { formatted: '38,000원' }, USD: { formatted: '$34.00' }, JPY: { formatted: '¥4,000' }, CNY: { formatted: '元232.00' }, EUR: { formatted: '€31.20' } };

describe('_partial_order_info.json — 보조 통화 줄', () => {
  /**
   * @scenario surface=order_info
   * @effects order_info_secondary_currency_excludes_order_base_currency
   */
  it('보조 통화 반복이 다섯 곳 모두 있다', () => {
    expect(iterations.length).toBe(5);
  });

  /**
   * @scenario surface=order_info
   * @effects order_info_secondary_currency_excludes_order_base_currency
   */
  it('기준 통화가 엔화이고 관리자 표시 통화가 원화면, 원화를 보이고 엔화는 다시 보이지 않는다', () => {
    const ctx = {
      row: { mc_unit_price: mc, mc_subtotal_price: mc },
      order: { data: { base_currency: 'JPY', mc_subtotal_amount: mc } },
      _global: { preferredCurrency: 'KRW', defaultCurrency: 'JPY' },
    };
    for (const node of iterations) {
      const codes = evaluate(node.iteration.source, ctx);
      expect(codes, node.iteration.source).toContain('KRW');
      expect(codes, node.iteration.source).not.toContain('JPY');
    }
  });

  /**
   * @scenario surface=order_info
   * @effects order_info_secondary_currency_excludes_order_base_currency
   */
  it('기준 통화와 표시 통화가 같으면 종전처럼 그 통화만 빠진다', () => {
    const ctx = {
      row: { mc_unit_price: mc, mc_subtotal_price: mc },
      order: { data: { base_currency: 'KRW', mc_subtotal_amount: mc } },
      _global: { preferredCurrency: 'KRW', defaultCurrency: 'KRW' },
    };
    for (const node of iterations) {
      expect(evaluate(node.iteration.source, ctx)).toEqual(['USD', 'JPY', 'CNY', 'EUR']);
    }
  });
});
