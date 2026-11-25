/**
 * setPaymentMethod 핸들러 테스트
 *
 * 결제수단 선택 시 _local 상태에 선택한 결제수단 ID 를 **그대로** 저장하는 동작을 검증합니다.
 *
 * 과거에는 간편결제(`nicepay_*`)를 서버로 보낼 때 'card' 로 위장해야 했기 때문에
 * `serverPaymentMethod` 를 함께 저장했습니다. 서버 검증(PaymentMethodEnum)이 확장 ID 를
 * 422 로 막았기 때문입니다. 그 위장이 서버로 하여금 간편결제 주문을 "PG 결제가 아닌 주문"
 * 으로 오인하게 만들어, 결제 실패 시 관리자 알림 오발송 + 임시주문 삭제(재결제 불가)를
 * 일으켰습니다.
 *
 * 이제 확장 결제수단이 1급 시민이므로 위장이 없고, `serverPaymentMethod` 도 제거됐습니다.
 *
 * 간편결제 선택 표시는 이커머스 결제수단 목록이 그린다. 주입 대상이 없어 한 번도 그려지지 않던 주문서 간편결제
 * 버튼 조각과, 그 버튼을 「간편결제」 한국어 문구로 찾아 칠하던 DOM 탐색·상태 구독 감시는 7.1.0 에서 걷어냈다(D-6).
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/public-core-api-only.yaml
 *
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { setPaymentMethodHandler } from '../../handlers/setPaymentMethod';

describe('setPaymentMethodHandler', () => {
    let setLocalSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        setLocalSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            state: { setLocal: setLocalSpy },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
    });

    // @scenario concern=payment_method_selection
    // @effects selected_method_saved_unchanged
    it('paymentMethod가 없으면 setLocal을 호출하지 않는다', () => {
        setPaymentMethodHandler({ params: {} });
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    // @scenario concern=payment_method_selection
    // @effects selected_method_saved_unchanged
    it('일반 결제수단(card)은 그대로 저장', () => {
        setPaymentMethodHandler({ params: { paymentMethod: 'card' } });

        expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: 'card' });
    });

    // @scenario concern=payment_method_selection
    // @effects selected_method_saved_unchanged
    it('가상계좌(vbank)도 그대로 저장', () => {
        setPaymentMethodHandler({ params: { paymentMethod: 'vbank' } });

        expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: 'vbank' });
    });

    describe('간편결제 (nicepay_*) — 확장 ID 를 위장하지 않는다', () => {
        // @scenario concern=payment_method_selection
        // @effects selected_method_saved_unchanged
        it.each([
            ['네이버페이', 'nicepay_naverpay'],
            ['카카오페이', 'nicepay_kakaopay'],
            ['애플페이', 'nicepay_applepay'],
        ])('%s 는 확장 ID 그대로 저장된다', (_label, method) => {
            // 애플페이는 iOS 에서만 고를 수 있다(#933) — 아이폰에서 고른 경우를 본다.
            vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue(
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            );

            setPaymentMethodHandler({ params: { paymentMethod: method } });

            expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: method });
            vi.restoreAllMocks();
        });

        /**
         * iOS 가 아닌 기기에서 애플페이를 고르면 저장하지 않고 안내만 띄운다 (#933 — NHN KCP 와 같은 선택 단계 확인).
         */
        // @scenario concern=payment_method_selection
        // @effects apple_pay_blocked_off_ios_before_config
        it('iOS 가 아니면 애플페이를 저장하지 않고 안내 토스트를 띄운다', () => {
            const toastError = vi.fn();
            (window as any).G7Core.toast = { error: toastError };
            vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue(
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            );

            setPaymentMethodHandler({ params: { paymentMethod: 'nicepay_applepay' } });

            expect(setLocalSpy).not.toHaveBeenCalled();
            expect(toastError).toHaveBeenCalledTimes(1);
            vi.restoreAllMocks();
        });

        // @scenario concern=payment_method_selection
        // @effects selected_method_saved_unchanged
        it('serverPaymentMethod 를 더 이상 저장하지 않는다 (card 위장 제거)', () => {
            setPaymentMethodHandler({ params: { paymentMethod: 'nicepay_naverpay' } });

            const saved = setLocalSpy.mock.calls[0][0] as Record<string, unknown>;
            expect(saved).not.toHaveProperty('serverPaymentMethod');
        });
    });

    // @scenario concern=payment_method_selection
    // @effects selected_method_saved_unchanged
    it('G7Core가 없으면 조용히 무시 (optional chaining)', () => {
        delete (window as Record<string, unknown>).G7Core;
        // 던지지 않고 정상 종료해야 함
        expect(() => setPaymentMethodHandler({ params: { paymentMethod: 'card' } })).not.toThrow();
    });
});

describe('주문서 간편결제 버튼 조각 — 걷어낸 상태 유지 (D-6)', () => {
    const EXT_ROOT = path.resolve(__dirname, '../../../..');

    // @scenario concern=payment_method_selection
    // @effects easy_pay_rendered_by_payment_method_catalog_only
    it('주입 대상이 없던 주문서 간편결제 조각이 다시 생기지 않는다', () => {
        // 조각의 주입 대상(nicepay_checkout_payment_section)이 주문서 어디에도 없어 한 번도 그려지지 않았다.
        // 간편결제는 이커머스 결제수단 목록(RegisterEasyPayMethodsListener)이 그린다.
        expect(fs.existsSync(path.join(EXT_ROOT, 'resources/extensions/checkout_easy_pay.g7.html'))).toBe(false);
        expect(fs.existsSync(path.join(EXT_ROOT, 'resources/extensions/checkout_easy_pay.json'))).toBe(false);
    });

    // @scenario concern=payment_method_selection
    // @effects no_locale_dependent_dom_lookup
    it('결제수단 선택 핸들러는 화면 문구로 DOM 을 찾지 않는다', () => {
        // 「간편결제」 한국어 문구로 버튼 묶음을 찾던 탐색은 다른 언어에서 맞지 않았다.
        const source = fs.readFileSync(path.join(EXT_ROOT, 'resources/js/handlers/setPaymentMethod.ts'), 'utf8');
        expect(source).not.toMatch(/textContent|querySelector|getElementById/);
        expect(source).not.toMatch(/간편결제'|네이버페이'|카카오페이'/);
    });
});
