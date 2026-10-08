/**
 * 관리자 주문 상세 「현금영수증」 카드 — 발급사를 이름으로 보인다 (S3-D17, 2026-10-07 실측)
 *
 * @description
 * 카드 제목 옆 발급사 칸이 `payment.cash_receipt_provider`(프로바이더 id)를 그대로 그려 「현금영수증 tosspayments」처럼
 * 내부 식별자가 보였다(실측: 무통장 주문 20261007-0258571183 결제정보 탭). 응답의 표시명
 * `cash_receipt_provider_label` 을 그린다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import partial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';

const source = JSON.stringify(partial);

describe('현금영수증 카드 발급사 표기', () => {
    /**
     * @scenario label_source=layout
     *
     * @effects admin_card_renders_provider_label
     */
    it('표시명(cash_receipt_provider_label)을 그린다', () => {
        expect(source).toContain('payment?.cash_receipt_provider_label');
    });

    /**
     * @scenario label_source=layout
     *
     * @effects admin_card_renders_provider_label
     */
    it('프로바이더 id 를 글자로 그리지 않는다', () => {
        expect(source).not.toContain("{{payment?.cash_receipt_provider ?? ''}}");
    });
});
