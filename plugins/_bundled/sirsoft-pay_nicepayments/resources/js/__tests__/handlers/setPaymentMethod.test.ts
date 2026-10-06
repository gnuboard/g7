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
 * 간편결제 선택 표시 감시(initEasyPayWatcher)는 공개 API `G7Core.state.subscribe()` 로 걸고 해제 함수를 쥔다 —
 * 코어가 재초기화 때 initPlugin 을 다시 부르면 앞 구독을 풀고 새로 건다(#639).
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/public-core-api-only.yaml
 *
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { disposeEasyPayWatcher, initEasyPayWatcher, setPaymentMethodHandler } from '../../handlers/setPaymentMethod';

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
            setPaymentMethodHandler({ params: { paymentMethod: method } });

            expect(setLocalSpy).toHaveBeenCalledWith({ paymentMethod: method });
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

describe('initEasyPayWatcher — 공개 상태 구독', () => {
    let listeners: Set<() => void>;
    let local: Record<string, unknown>;

    beforeEach(() => {
        listeners = new Set();
        local = {};
        (window as Record<string, unknown>).G7Core = {
            state: {
                subscribe: vi.fn((listener: () => void) => {
                    listeners.add(listener);

                    return () => listeners.delete(listener);
                }),
                getLocal: () => local,
            },
        };
        document.body.innerHTML = `
            <div id="nicepay_checkout_payment_section">
                <p>간편결제</p>
                <div><button style="box-shadow: 0 0 0 2px #fff">카카오페이</button></div>
            </div>`;
    });

    afterEach(() => {
        disposeEasyPayWatcher();
        delete (window as Record<string, unknown>).G7Core;
        document.body.innerHTML = '';
    });

    // @scenario concern=state_subscription
    // @effects subscription_released_before_resubscribe
    it('재초기화로 다시 불려도 구독은 하나만 남는다 — 앞 구독의 해제 함수를 부른다', () => {
        initEasyPayWatcher();
        initEasyPayWatcher();
        initEasyPayWatcher();

        expect((window as any).G7Core.state.subscribe).toHaveBeenCalledTimes(3);
        expect(listeners.size).toBe(1);

        disposeEasyPayWatcher();
        expect(listeners.size).toBe(0);
    });

    // @scenario concern=state_subscription
    // @effects easy_pay_highlight_cleared_on_core_method
    it('코어 결제수단으로 바뀌면 간편결제 선택 표시를 지운다 — 선택값은 G7Core.state.getLocal() 로 읽는다', () => {
        initEasyPayWatcher();
        const button = document.querySelector('button') as HTMLButtonElement;

        local = { paymentMethod: 'nicepay_kakaopay' };
        listeners.forEach((listener) => listener());
        expect(button.style.boxShadow).not.toBe('');

        local = { paymentMethod: 'card' };
        listeners.forEach((listener) => listener());
        expect(button.style.boxShadow).toBe('');
    });

    // @scenario concern=state_subscription
    // @effects subscription_released_before_resubscribe
    it('공개 구독 API 가 없으면 아무것도 걸지 않는다', () => {
        (window as Record<string, unknown>).G7Core = { state: {} };

        expect(() => initEasyPayWatcher()).not.toThrow();
    });
});
