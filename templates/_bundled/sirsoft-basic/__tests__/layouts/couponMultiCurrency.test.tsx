/**
 * @file couponMultiCurrency.test.tsx
 * @description 쿠폰 다운로드 모달 다통화 환산 구조 회귀 테스트 (A1-④)
 *
 * 배경:
 * - CouponResource 가 multi_currency_discount_value/min_order_amount(정액만, 정률 null)를 노출하며,
 *   모달이 선택 통화 환산값(formatted)을 표시하고 환산 부재 시 기본통화 숫자+단위로 폴백한다.
 * - 정률(rate) 할인은 통화 무관 → % 표시 유지.
 *
 * 회귀 차단:
 * - 정액 할인은 multi_currency_discount_value[선택통화].formatted 를 우선 표시(있을 때).
 * - 폴백 분기는 환산 부재 시 Number(discount_value).toLocaleString() + currency_unit.
 * - 최소주문금액도 동일 패턴(multi_currency_min_order_amount 우선 + 폴백).
 * - 정률 할인은 discount_value% 텍스트 유지(통화 무관).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const modalText = fs.readFileSync(
  path.resolve(baseDir, 'layouts/partials/shop/_modal_coupon_download.json'),
  'utf8'
);

describe('A1-④ — 쿠폰 모달 다통화 환산', () => {
  it('정액 할인이 multi_currency_discount_value 선택통화 환산을 표시한다', () => {
    expect(modalText).toContain('multi_currency_discount_value');
    expect(modalText).toContain("_global.preferredCurrency ?? 'KRW'");
  });

  it('정액 할인 폴백은 기본통화 숫자 + currency_unit 을 유지한다', () => {
    expect(modalText).toContain('Number(coupon.discount_value).toLocaleString()');
    expect(modalText).toContain('shop.coupon_download.currency_unit');
  });

  it('최소주문금액도 multi_currency_min_order_amount 우선 + 폴백 패턴을 쓴다', () => {
    expect(modalText).toContain('multi_currency_min_order_amount');
    expect(modalText).toContain('Number(coupon.min_order_amount).toLocaleString()');
  });

  it('정률(rate) 할인은 % 표시를 유지한다(통화 무관)', () => {
    expect(modalText).toContain("coupon.discount_type === 'rate'");
    expect(modalText).toContain('{{coupon.discount_value}}%');
  });

  it('정액/정률 분기 if 게이트가 discount_type 으로 분리된다', () => {
    expect(modalText).toContain("coupon.discount_type === 'fixed'");
  });
});
