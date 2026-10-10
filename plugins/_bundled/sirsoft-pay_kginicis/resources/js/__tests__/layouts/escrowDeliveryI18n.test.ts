/**
 * @file escrowDeliveryI18n.test.ts
 * @description KG 이니시스 주문 결제 조회 확장의 에스크로 배송 등록·구매거절 문구가 번역 키를 거치는지 잰다.
 *
 * 택배사 목록의 「기타」와 구매거절 칸의 「일시」가 한국어로 박혀 있어 언어를 바꿔도 한국어로 남았다
 * (택배사 이름은 회사 고유명이라 그대로 둔다).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import paymentQuery from '../../../extensions/admin_order_payment_query.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

const text = JSON.stringify(paymentQuery);

describe('KG 이니시스 결제 조회 — 에스크로 문구 번역', () => {
  // @scenario surface=kginicis_escrow_delivery
  // @effects ui_copy_goes_through_translation_keys, every_used_key_exists_in_ko_and_en
  it('택배사 목록의 「기타」가 번역 키를 쓴다', () => {
    expect(text).toContain('$t:sirsoft-pay_kginicis.admin.escrow_delivery_courier_etc');
    expect(text).not.toContain('"기타"');
    expect(ko.admin.escrow_delivery_courier_etc).toBe('기타');
    expect(typeof en.admin.escrow_delivery_courier_etc).toBe('string');
  });

  // @scenario surface=kginicis_escrow_delivery
  // @effects ui_copy_goes_through_translation_keys, every_used_key_exists_in_ko_and_en
  it('구매거절 칸의 「일시」가 번역 키를 쓴다', () => {
    expect(text).toContain('$t:sirsoft-pay_kginicis.admin.escrow_deny_confirm_buyer_denied_at');
    expect(text).not.toContain('"일시"');
    expect(ko.admin.escrow_deny_confirm_buyer_denied_at).toBe('일시');
    expect(typeof en.admin.escrow_deny_confirm_buyer_denied_at).toBe('string');
  });
});
