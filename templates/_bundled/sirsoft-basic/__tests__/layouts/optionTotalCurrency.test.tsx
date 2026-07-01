/**
 * @file optionTotalCurrency.test.tsx
 * @description 상품상세 "총 금액" 선택 통화 환산 구조 회귀 테스트 (D2·D3)
 *
 * 배경:
 * - 상품상세 구매 카드(_purchase_card.json)의 "총 금액"이 선택 통화와 무관하게 KRW 로 고정 표시되던 결함.
 *   ① 옵션 선택형(2개 이상): 합계 value 를 multi_currency 로 올바로 합산하나 `.toLocaleString() + '원'` 단위 하드코딩
 *      → USD 선택 시 "2.55원"(USD 숫자 + 원) 으로 표시.
 *   ② 옵션 없는 상품: `selling_price`(base 정수) * 수량 + '원' → 선택 통화 환산 자체가 없어 항상 base KRW raw.
 * - 수정: 합계 value 를 선택 통화 multi_currency value 로 구한 뒤 handler('formatCurrency', { value, currencyCode })
 *   로 포맷한다(formatCurrency.ts 가 Intl.NumberFormat 으로 통화 기호/소수점 처리).
 *
 * 회귀 차단:
 * - 두 "총 금액" 노드 모두 handler('formatCurrency', ...) 를 통해 선택 통화로 포맷한다.
 * - `.toLocaleString() + '원'` (또는 `+ "원"`) 단위 하드코딩이 없다.
 * - 옵션 없는 상품 합계는 multi_currency_selling_price 선택통화 value 를 우선 사용한다(base 폴백).
 * - 옵션 선택형 합계는 multiCurrencyTotalPrice 선택통화 value 를 합산한다.
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

describe('상품상세 총 금액 선택 통화 환산 (_purchase_card.json)', () => {
  const layout = loadJson('layouts/partials/shop/detail/_purchase_card.json');
  const optionBlock = findByComment(layout, '총 금액 (옵션 선택형');
  const noOptionBlock = findByComment(layout, '총 금액 (옵션 없거나');

  // value(금액) 노드 = 선택 통화 포맷 맵을 조회하는 노드. 라벨 노드($t:...total_amount)와 구분.
  const optionValueNode = flatten(optionBlock).find(
    (n) => typeof n.text === 'string' && n.text.includes('selectedTotalMultiCurrency'));
  const noOptionValueNode = flatten(noOptionBlock).find(
    (n) => typeof n.text === 'string' && n.text.includes('noOptionTotalMultiCurrency'));

  it('옵션 선택형 총 금액은 selectedTotalMultiCurrency 선택통화 포맷 맵을 조회한다 (D2)', () => {
    expect(optionValueNode).toBeDefined();
    expect(optionValueNode!.text).toContain("selectedTotalMultiCurrency?.[_global.preferredCurrency ?? 'KRW']?.formatted");
  });

  it('옵션 선택형 총 금액은 handler() 표현식 호출과 원(KRW) 하드코딩을 쓰지 않는다 (금지패턴/D2 회귀 차단)', () => {
    expect(optionValueNode!.text).not.toContain('handler(');
    expect(optionValueNode!.text).not.toMatch(/\+\s*'원'/);
    expect(optionValueNode!.text).not.toMatch(/\+\s*"원"/);
  });

  it('옵션 없는 상품 총 금액은 noOptionTotalMultiCurrency 또는 단가 formatted 폴백을 쓴다 (D3)', () => {
    expect(noOptionValueNode).toBeDefined();
    expect(noOptionValueNode!.text).toContain("noOptionTotalMultiCurrency?.[_global.preferredCurrency ?? 'KRW']?.formatted");
    // 단가 formatted 폴백(초기 수량 1)
    expect(noOptionValueNode!.text).toContain('multi_currency_selling_price');
  });

  it('옵션 없는 상품 총 금액은 handler() 표현식 호출과 원(KRW) 하드코딩을 쓰지 않는다 (금지패턴/D3 회귀 차단)', () => {
    expect(noOptionValueNode!.text).not.toContain('handler(');
    expect(noOptionValueNode!.text).not.toMatch(/\+\s*'원'/);
    expect(noOptionValueNode!.text).not.toMatch(/\+\s*"원"/);
  });

  it('개별 선택 항목 가격(multiCurrencyTotalPrice formatted)은 유지된다(비회귀)', () => {
    const text = JSON.stringify(layout);
    expect(text).toContain('multiCurrencyTotalPrice');
    expect(text).toContain("?.[_global.preferredCurrency ?? 'KRW']?.formatted");
  });

  it('레이아웃 전체에 handler() 표현식 호출이 없다 (엔진 표현식 컨텍스트는 handler 미제공)', () => {
    expect(JSON.stringify(layout)).not.toContain("handler('");
  });
});
