/**
 * @file mypageOrderBaseCurrencyDisplay.test.tsx
 * @description 마이페이지 주문서/결제/취소 화면의 다통화 표기 정합 검증
 *
 * base≠결제 통화 주문(예: base JPY, 결제 KRW)에서 주문/결제/취소 금액의 primary 표기는
 * 주문 시점 기준 통화(base_currency)로 고정하고, 결제 통화(order_currency) 환산액을 병기한다.
 *
 * 회귀 차단:
 *  - primary 를 유저 표시통화(_global.preferredCurrency)로 렌더하던 것 → base 통화 고정
 *  - 보조 통화 iteration 이 preferredCurrency 만 제외하던 것 → 주문 base_currency 제외
 *  - 취소 비교표 primary 가 하드코딩 통화단위였던 것 → base 포맷(formatted) 바인딩
 *  - 배송 카드 배송비가 표시통화 값만 찍어 기준통화 줄이 사라지던 것 (S3-D10 — 앞 사례 일괄수정에서 누락)
 *
 * 소유 시나리오: `order-base-currency-display` (축 매핑은 각 테스트에 있다)
 *
 * 백엔드 정합 SSoT: OrderResource/OrderListResource(base_currency·payment_currency·is_cross_currency),
 *   AdjustmentResult.toPreviewArray(original_snapshot.formatted·refund_formatted).
 */

import { describe, it, expect } from 'vitest';
import paymentJson from '../../layouts/partials/mypage/orders/_payment.json';
import comparisonJson from '../../layouts/partials/mypage/claims/_apply_comparison.json';
import listJson from '../../layouts/partials/mypage/orders/_list.json';
import itemsJson from '../../layouts/partials/mypage/orders/_items.json';
import shippingJson from '../../layouts/partials/mypage/orders/_shipping.json';

/** 트리 전체를 직렬화해 바인딩 표현식을 문자열로 검증한다. */
function serialize(node: unknown): string {
    return JSON.stringify(node);
}

describe('마이페이지 결제 정보 — base 통화 고정 + 결제통화 병기', () => {
    const s = serialize(paymentJson);

    // @scenario surface=payment, currency_binding=base_primary
    // @effects primary_amount_binds_order_base_not_preferred_currency
    it('금액 primary 는 preferredCurrency 가 아니라 base(*_formatted)를 바인딩한다', () => {
        // 회귀: mc_total_amount[preferredCurrency].formatted 를 primary 로 쓰던 패턴 제거
        expect(s).not.toContain("mc_total_amount?.[_global.preferredCurrency ?? _global.defaultCurrency]?.formatted");
        expect(s).toContain('order.data.total_amount_formatted');
    });

    // @scenario surface=payment, currency_binding=preferred_excluded
    // @effects secondary_currency_iteration_excludes_order_base_currency
    it('보조 통화 iteration 은 주문 base_currency 를 제외한다', () => {
        expect(s).toContain("order.data.base_currency ?? _global.preferredCurrency ?? _global.defaultCurrency");
    });

    // @scenario surface=payment, currency_binding=base_primary
    // @effects cross_currency_order_states_charged_amount_in_payment_currency
    it('base≠결제 통화일 때 결제 통화 실청구액을 명시한다', () => {
        expect(s).toContain('payment?.is_cross_currency');
        expect(s).toContain('charged_in_payment_currency');
        expect(s).toContain('payment?.paid_amount_formatted');
    });
});

describe('클레임 신청서 주문금액 비교 — base 통화 고정 + 환불 결제통화 병기 (옛 취소 모달 대체)', () => {
    const s = serialize(comparisonJson);

    // @scenario surface=claim_comparison, currency_binding=base_primary
    // @effects primary_amount_binds_order_base_not_preferred_currency
    it('비교표 primary 는 하드코딩 통화단위가 아니라 base 포맷(formatted)을 직독한다', () => {
        expect(s).not.toContain('common.currency_unit');
        expect(s).not.toMatch(/total_paid_amount \?\? 0\)\.toLocaleString\(\)/);
        expect(s).toContain('original_snapshot?.formatted?.total_paid_amount');
        expect(s).toContain('recalculated_snapshot?.formatted?.subtotal_amount');
    });

    // @scenario surface=claim_comparison, currency_binding=preferred_excluded
    // @effects secondary_currency_iteration_excludes_order_base_currency
    it('보조 통화 iteration 은 주문 base_currency 를 제외한다', () => {
        expect(s).toContain('original_snapshot?.base_currency ?? _global.preferredCurrency');
    });

    // @scenario surface=claim_comparison, currency_binding=base_primary
    // @effects cross_currency_order_states_charged_amount_in_payment_currency
    it('환불 예정액은 base 포맷 + 결제통화 환산(refund_formatted / mc_refund_total) 병기를 가진다', () => {
        expect(s).toContain('refund_formatted?.base?.refund_total');
        expect(s).toContain('claimEstimateFormatted?.mc_refund_total');
    });
});

describe('마이페이지 주문 목록 — base 통화 고정', () => {
    const s = serialize(listJson);

    // 목록은 한 줄에 두 성질을 다 단언한다 — 기준줄(primary)과 보조 통화 제외키를 함께 본다.
    // @scenario surface=order_list, currency_binding=base_primary
    // @scenario surface=order_list, currency_binding=preferred_excluded
    // @effects primary_amount_binds_order_base_not_preferred_currency
    // @effects secondary_currency_iteration_excludes_order_base_currency
    it('합계 primary 는 base(*_formatted), 보조 통화는 base_currency 제외', () => {
        expect(s).toContain('order.total_amount_formatted');
        expect(s).toContain("order.base_currency ?? _global.preferredCurrency ?? _global.defaultCurrency");
    });
});

describe('마이페이지 주문 상세 상품 카드 — base 통화 고정', () => {
    const s = serialize(itemsJson);

    // @scenario surface=order_items, currency_binding=base_primary
    // @effects primary_amount_binds_order_base_not_preferred_currency
    it('상품 단가/소계 primary 는 preferredCurrency 가 아니라 base(*_formatted)를 바인딩한다', () => {
        // 회귀: mc_subtotal_price[preferredCurrency].formatted 를 primary 로 쓰던 패턴 제거
        expect(s).not.toContain("mc_subtotal_price?.[_global.preferredCurrency ?? _global.defaultCurrency]?.formatted");
        expect(s).not.toContain("mc_unit_price?.[_global.preferredCurrency ?? _global.defaultCurrency]?.formatted");
        expect(s).toContain('item.unit_price_formatted');
        expect(s).toContain('item.subtotal_price_formatted');
    });

    // @scenario surface=order_items, currency_binding=preferred_excluded
    // @effects secondary_currency_iteration_excludes_order_base_currency
    it('보조 통화 iteration 은 주문 base_currency 를 제외한다', () => {
        expect(s).toContain("order.data.base_currency ?? _global.preferredCurrency ?? _global.defaultCurrency");
    });
});

describe('마이페이지 주문 상세 배송 카드 — base 통화 고정 (S3-D10)', () => {
    const s = serialize(shippingJson);

    // @scenario surface=order_shipping, currency_binding=base_primary
    // @effects primary_amount_binds_order_base_not_preferred_currency
    it('배송비 primary 는 preferredCurrency 단독 선택키를 쓰지 않는다', () => {
        // 회귀(S3-D10): 표시통화를 JPY 로 두면 KRW 주문의 배송 현황이 「배송완료 ¥287」 로만 나오고
        // 2,500원 기준줄이 통째로 사라졌다. 주문은 결제된 기록이므로 보는 사람의 표시통화를 따라가면 안된다.
        expect(s).not.toContain("mc_total_shipping_amount?.[_global.preferredCurrency ?? _global.defaultCurrency]?.formatted");
    });

    // @scenario surface=order_shipping, currency_binding=preferred_excluded
    // @effects secondary_currency_iteration_excludes_order_base_currency
    it('배송비 표기는 주문 base_currency 를 먼저 키잉한다', () => {
        expect(s).toContain("order.data.base_currency ?? _global.preferredCurrency ?? _global.defaultCurrency");
    });
});
