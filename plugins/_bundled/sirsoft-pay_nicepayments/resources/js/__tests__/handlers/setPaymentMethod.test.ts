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
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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

    it('paymentMethod가 없으면 setLocal을 호출하지 않는다', () => {
        setPaymentMethodHandler({ params: {} });
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('일반 결제수단(card)은 그대로 저장', () => {
        setPaymentMethodHandler({ params: { paymentMethod: 'card' } });

        expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: 'card' });
    });

    it('가상계좌(vbank)도 그대로 저장', () => {
        setPaymentMethodHandler({ params: { paymentMethod: 'vbank' } });

        expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: 'vbank' });
    });

    describe('간편결제 (nicepay_*) — 확장 ID 를 위장하지 않는다', () => {
        it.each([
            ['네이버페이', 'nicepay_naverpay'],
            ['카카오페이', 'nicepay_kakaopay'],
            ['애플페이', 'nicepay_applepay'],
        ])('%s 는 확장 ID 그대로 저장된다', (_label, method) => {
            setPaymentMethodHandler({ params: { paymentMethod: method } });

            expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: method });
        });

        it('serverPaymentMethod 를 더 이상 저장하지 않는다 (card 위장 제거)', () => {
            setPaymentMethodHandler({ params: { paymentMethod: 'nicepay_naverpay' } });

            const saved = setLocalSpy.mock.calls[0][0] as Record<string, unknown>;
            expect(saved).not.toHaveProperty('serverPaymentMethod');
        });
    });

    it('G7Core가 없으면 조용히 무시 (optional chaining)', () => {
        delete (window as Record<string, unknown>).G7Core;
        // 던지지 않고 정상 종료해야 함
        expect(() => setPaymentMethodHandler({ params: { paymentMethod: 'card' } })).not.toThrow();
    });
});
