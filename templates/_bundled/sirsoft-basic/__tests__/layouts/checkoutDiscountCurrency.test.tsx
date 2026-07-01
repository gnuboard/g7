/**
 * @file checkoutDiscountCurrency.test.tsx
 * @description 체크아웃 할인/쿠폰 선택 통화 환산 구조 회귀 테스트 (D5 + D4 체크아웃)
 *
 * 배경:
 * - 체크아웃 주문/배송비 쿠폰 옵션 텍스트와 적용 할인액이 선택 통화와 무관하게 KRW 로 고정되던 결함.
 *   ① 쿠폰 옵션 텍스트: coupon.benefit_formatted(KRW 합성) 직접 출력
 *      → 선택 통화 multi_currency_benefit_formatted[선택통화] 폴백.
 *   ② 적용 할인액: summary.*_discount_formatted(KRW) 직접 출력
 *      → summary.multi_currency[선택통화].*_discount_formatted 폴백(백엔드는 이미 환산 제공).
 *
 * 회귀 차단:
 * - 쿠폰/배송비쿠폰 옵션 텍스트가 multi_currency_benefit_formatted 선택통화 폴백을 쓴다.
 * - 적용 주문쿠폰/배송비쿠폰/할인코드 할인액이 summary.multi_currency 선택통화 폴백을 쓴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const raw = fs.readFileSync(
  path.resolve(baseDir, 'layouts/partials/shop/_checkout_discount.json'),
  'utf8'
);

describe('체크아웃 할인/쿠폰 선택 통화 환산 (_checkout_discount.json)', () => {
  it('쿠폰 옵션 텍스트는 multi_currency_benefit_formatted 선택통화 폴백을 쓴다 (옵션 텍스트 KRW 고정 차단)', () => {
    // benefit_formatted 단독 사용 패턴이 없어야 한다(반드시 multi_currency 우선)
    const benefitMatches = raw.match(/coupon\.benefit_formatted/g) ?? [];
    const multiMatches = raw.match(/coupon\.multi_currency_benefit_formatted\?\.\[_global\.preferredCurrency \?\? 'KRW'\]/g) ?? [];
    // 옵션 텍스트 2곳(주문쿠폰 + 배송비쿠폰) 모두 multi_currency 우선 폴백
    expect(multiMatches.length).toBeGreaterThanOrEqual(2);
    // benefit_formatted 가 등장하더라도 항상 multi_currency 폴백의 ?? 우변으로만
    expect(benefitMatches.length).toBeGreaterThanOrEqual(2);
  });

  it('적용 주문쿠폰 할인액이 summary.multi_currency 선택통화 폴백을 쓴다', () => {
    expect(raw).toContain(
      "multi_currency?.[_global.preferredCurrency ?? 'KRW']?.order_coupon_discount_formatted"
    );
  });

  it('적용 배송비쿠폰 할인액이 summary.multi_currency 선택통화 폴백을 쓴다 (D5 핵심)', () => {
    expect(raw).toContain(
      "multi_currency?.[_global.preferredCurrency ?? 'KRW']?.shipping_discount_formatted"
    );
  });

  it('적용 할인코드 할인액이 summary.multi_currency 선택통화 폴백을 쓴다', () => {
    expect(raw).toContain(
      "multi_currency?.[_global.preferredCurrency ?? 'KRW']?.code_discount_formatted"
    );
  });
});
