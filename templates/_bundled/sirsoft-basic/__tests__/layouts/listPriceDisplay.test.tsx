/**
 * @file listPriceDisplay.test.tsx
 * @description 정가/할인율 표시 구조 회귀 테스트 — 장바구니(_cart_item)·주문서(_checkout_items) (U5·U4②)
 *
 * 배경:
 * - BaseOrderItemResource 공통 베이스에 정가(list_price/multi_currency_list_price/discount_rate)가 실리며,
 *   장바구니·주문서 파셜이 정가 취소선 + 할인율 칩 + (보조통화 정가)을 표시한다.
 * - 표시 참조 패턴은 상품상세 detail/_price_mobile.json 과 동일(취소선 + 할인율 칩 + if discount_rate>0).
 *
 * 회귀 차단(두 파셜 공통):
 * - 정가 취소선 + 할인율 블록이 if=(discount_rate>0) 게이트로 존재한다(할인 없을 때 미렌더).
 * - 정가 취소선은 multi_currency_list_price[선택통화].formatted ?? list_price_formatted 폴백을 쓴다.
 * - 정가는 line-through 클래스, 할인율 칩은 discount_rate% 텍스트 + 빨강 클래스를 쓴다.
 * - 보조 통화 정가 iteration 은 multi_currency_list_price 를 선택 통화 제외 필터로 순회한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

interface Node {
  comment?: string;
  if?: string;
  text?: string;
  props?: Record<string, any>;
  iteration?: { source?: string; item_var?: string };
  children?: Node[];
  [k: string]: any;
}

function loadJson(relPath: string): Node {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
  if (!node) return acc;
  acc.push(node);
  (node.children ?? []).forEach((c) => flatten(c, acc));
  return acc;
}

function findByComment(node: Node, needle: string): Node | undefined {
  return flatten(node).find((n) => typeof n.comment === 'string' && n.comment.includes(needle));
}

describe.each([
  ['장바구니 (_cart_item)', 'layouts/partials/shop/_cart_item.json'],
  ['주문서 (_checkout_items)', 'layouts/partials/shop/_checkout_items.json'],
])('정가/할인율 표시 구조 — %s', (_label, relPath) => {
  const layout = loadJson(relPath);
  const text = JSON.stringify(layout);
  const listPriceBlock = findByComment(layout, '정가 취소선 + 할인율');
  const secondaryBlock = findByComment(layout, '정가 취소선 - 보조 통화');

  it('정가 취소선 + 할인율 블록이 if=(discount_rate>0) 게이트로 존재한다', () => {
    expect(listPriceBlock).toBeDefined();
    expect(listPriceBlock!.if).toContain('discount_rate');
    expect(listPriceBlock!.if).toContain('> 0');
  });

  it('정가 취소선은 multi_currency_list_price 선택통화 폴백 패턴을 쓴다', () => {
    const nodes = flatten(listPriceBlock);
    const strikethrough = nodes.find((n) =>
      typeof n.text === 'string' && n.text.includes('multi_currency_list_price'));
    expect(strikethrough).toBeDefined();
    expect(strikethrough!.text).toContain("_global.preferredCurrency ?? 'KRW'");
    expect(strikethrough!.text).toContain('list_price_formatted');
    expect(strikethrough!.props?.className).toContain('line-through');
  });

  it('할인율 칩은 discount_rate% 텍스트 + 빨강 클래스를 쓴다', () => {
    const nodes = flatten(listPriceBlock);
    const chip = nodes.find((n) => typeof n.text === 'string' && n.text.includes('discount_rate') && n.text.includes('%'));
    expect(chip).toBeDefined();
    expect(chip!.props?.className).toContain('text-red');
  });

  it('보조 통화 정가 iteration 은 multi_currency_list_price 를 선택통화 제외 필터로 순회한다', () => {
    expect(secondaryBlock).toBeDefined();
    expect(secondaryBlock!.if).toContain('discount_rate');
    expect(secondaryBlock!.iteration?.source).toContain('multi_currency_list_price');
    expect(secondaryBlock!.iteration?.source).toContain("code !== (_global.preferredCurrency ?? 'KRW')");
  });

  it('판매가 표시(multi_currency_selling_price)는 유지된다(비회귀)', () => {
    expect(text).toContain('multi_currency_selling_price');
  });

  it('정가 블록은 라이트/다크 클래스 쌍을 갖는다', () => {
    const nodes = flatten(listPriceBlock);
    const strikethrough = nodes.find((n) => n.props?.className?.includes?.('line-through'));
    expect(strikethrough!.props!.className).toMatch(/dark:/);
  });
});
