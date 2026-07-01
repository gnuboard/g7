/**
 * 회귀 테스트 — KG 이니시스 간편결제 매핑 정합성
 *
 * 그누보드5 reference 와 일치하는 식별자/엔드포인트/필드 사용을 강제한다.
 * 본 테스트가 실패하면 KG 이니시스 결제창이 "선택한 결제수단으로는 결제가
 * 불가능합니다" 등의 오류로 거부할 가능성이 높다.
 */
import { describe, it, expect } from 'vitest';
import {
    GOPAYMETHOD_MAP,
    MOBILE_PAYMETHOD_MAP,
    MOBILE_EASY_PAY_RESERVED_HINT,
} from '../../handlers/requestPayment';

describe('PC GOPAYMETHOD_MAP (INIStdPay)', () => {
    /**
     * gnuboard5 shop/inicis/lpay_order.script.php:
     *   gopaymethod = (inicis_settle_case === 'inicis_kakaopay') ? 'onlykakaopay' : 'onlylpay'
     *
     * 'LPAY' / 'KAKAOPAY' 와 같은 대문자 토큰은 KG 이니시스 표준결제창이 받지 않는다.
     */
    it('kginicis_samsung_pay 는 onlyssp 로 매핑되어야 한다', () => {
        expect(GOPAYMETHOD_MAP.kginicis_samsung_pay).toBe('onlyssp');
    });

    it('kginicis_naverpay 는 onlynaverpay 로 매핑되어야 한다', () => {
        expect(GOPAYMETHOD_MAP.kginicis_naverpay).toBe('onlynaverpay');
    });

    it('kginicis_lpay 는 onlylpay 로 매핑되어야 한다 (대문자 LPAY 가 아님)', () => {
        expect(GOPAYMETHOD_MAP.kginicis_lpay).toBe('onlylpay');
    });

    it('kginicis_kakaopay 는 onlykakaopay 로 매핑되어야 한다 (대문자 KAKAOPAY 가 아님)', () => {
        expect(GOPAYMETHOD_MAP.kginicis_kakaopay).toBe('onlykakaopay');
    });

    it('일반 결제수단 매핑은 기존대로 보존', () => {
        expect(GOPAYMETHOD_MAP.card).toBe('Card');
        expect(GOPAYMETHOD_MAP.vbank).toBe('VBank');
        expect(GOPAYMETHOD_MAP.bank).toBe('DirectBank');
        expect(GOPAYMETHOD_MAP.phone).toBe('HPP');
    });
});

describe('모바일 MOBILE_PAYMETHOD_MAP', () => {
    /**
     * 간편결제는 별도 엔드포인트 (/smart/wcard/) 와 P_RESERVED hint 로 식별된다.
     * P_INI_PAYMENT 토큰으로 SAMSUNG/NPAY/LPAY/KAKAOPAY 를 보내는 방식은 잘못된 호환성
     * 가정이었음 — gnuboard5 mobile/shop/samsungpay/orderform.1.php 에는
     * P_INI_PAYMENT 필드 자체가 없다.
     */
    it('간편결제 식별자는 모바일 P_INI_PAYMENT 맵에 존재하지 않아야 한다', () => {
        expect(MOBILE_PAYMETHOD_MAP.kginicis_samsung_pay).toBeUndefined();
        expect(MOBILE_PAYMETHOD_MAP.kginicis_naverpay).toBeUndefined();
        expect(MOBILE_PAYMETHOD_MAP.kginicis_lpay).toBeUndefined();
        expect(MOBILE_PAYMETHOD_MAP.kginicis_kakaopay).toBeUndefined();
    });

    it('일반 결제수단 매핑은 기존대로 보존', () => {
        expect(MOBILE_PAYMETHOD_MAP.card).toBe('CARD');
        expect(MOBILE_PAYMETHOD_MAP.vbank).toBe('VBANK');
        expect(MOBILE_PAYMETHOD_MAP.bank).toBe('BANK');
        expect(MOBILE_PAYMETHOD_MAP.phone).toBe('MOBILE');
    });
});

describe('모바일 MOBILE_EASY_PAY_RESERVED_HINT', () => {
    /**
     * gnuboard5 mobile/shop/samsungpay/order.script.php 의 hint 토큰:
     *   d_samsungpay=Y / d_npay=Y / d_lpay=Y / d_kakaopay=Y
     */
    it('Samsung Pay hint', () => {
        expect(MOBILE_EASY_PAY_RESERVED_HINT.kginicis_samsung_pay).toBe('d_samsungpay=Y');
    });

    it('Naver Pay hint', () => {
        expect(MOBILE_EASY_PAY_RESERVED_HINT.kginicis_naverpay).toBe('d_npay=Y');
    });

    it('L.pay hint', () => {
        expect(MOBILE_EASY_PAY_RESERVED_HINT.kginicis_lpay).toBe('d_lpay=Y');
    });

    it('카카오페이 hint', () => {
        expect(MOBILE_EASY_PAY_RESERVED_HINT.kginicis_kakaopay).toBe('d_kakaopay=Y');
    });

    it('일반 결제수단은 hint 가 없어야 한다 (간편결제 표식 오용 방지)', () => {
        expect(MOBILE_EASY_PAY_RESERVED_HINT.card).toBeUndefined();
        expect(MOBILE_EASY_PAY_RESERVED_HINT.vbank).toBeUndefined();
    });
});
