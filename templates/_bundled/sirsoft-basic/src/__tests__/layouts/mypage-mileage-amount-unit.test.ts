/**
 * @file mypage-mileage-amount-unit.test.ts
 * @description 마이페이지 마일리지 내역 금액이 단위를 한 번만 붙이는지 (레이아웃 구조 + 표현식 평가).
 *
 * 결함: 내역 행이 서버의 `amount_formatted`(마일리지 통화 서식 — 「240원」) 뒤에 다시 「 P」 를 붙여 「+240원 P」 로 보였다.
 *   같은 화면 위 요약 칸(사용 가능·누적 적립 등)은 숫자에 「 P」 만 붙인다. (이커머스 전수 실측 S4 — S4-D12)
 */

import { describe, it, expect } from 'vitest';

import mileageList from '../../../layouts/partials/mypage/mileage/_list.json';

/** 조건에 맞는 노드를 트리에서 모두 모은다 */
function collect(node: unknown, match: (value: Record<string, unknown>) => boolean, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));

    return out;
  }

  if (node === null || typeof node !== 'object') {
    return out;
  }

  const value = node as Record<string, unknown>;

  if (match(value)) {
    out.push(value);
  }

  Object.values(value).forEach((child) => collect(child, match, out));

  return out;
}

/** `{{ … }}` 가 섞인 문장을 tx 컨텍스트로 평가해 화면 글자를 만든다 */
function render(text: string, tx: unknown): string {
  // eslint-disable-next-line no-new-func
  return text.replace(/\{\{([\s\S]+?)\}\}/g, (_, expr) => String(new Function('tx', `return (${expr});`)(tx)));
}

describe('마일리지 내역 — 금액 단위', () => {
  const amountTexts = collect(mileageList, (value) => typeof value.text === 'string' && value.text.includes('tx.amount') && value.text.trim().endsWith('P')).map((value) => value.text as string);

  /**
   * @scenario sign=plus
   * @effects amount_shows_single_point_unit
   */
  it('적립 행은 「+240 P」 처럼 숫자와 P 하나만 보인다(PC·모바일 두 칸)', () => {
    expect(amountTexts.length).toBe(2);
    for (const text of amountTexts) {
      expect(render(text, { amount: 240, amount_formatted: '240원' }).trim()).toBe('+240 P');
      expect(render(text, { amount: 50000, amount_formatted: '50,000원' }).trim()).toBe('+50,000 P');
    }
  });

  /**
   * @scenario sign=minus
   * @effects amount_shows_single_point_unit
   */
  it('사용·차감 행은 「-1,500 P」 로 보인다', () => {
    for (const text of amountTexts) {
      expect(render(text, { amount: -1500, amount_formatted: '-1,500원' }).trim()).toBe('-1,500 P');
    }
  });
});
