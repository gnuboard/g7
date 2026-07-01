import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearMobilePaymentReturnPending,
    consumeStandardPaySdkReloadFlag,
    hasMobilePaymentReturnPending,
    markMobilePaymentReturnPending,
} from '../paymentDomCleanup';
import {
    clearStandardPaymentCloseReportContext,
    installPaymentCloseMessageListener,
    markStandardPaymentCloseReportContext,
    resetCheckoutSubmittingState,
} from '../paymentCloseMessageListener';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

describe('paymentCloseMessageListener', () => {
    const setLocal = vi.fn();

    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        windowRecord().G7Core = {
            state: {
                setLocal,
            },
        };
        setLocal.mockClear();
        clearStandardPaymentCloseReportContext();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        delete windowRecord().__sirsoftKginicisPaymentCloseListenerInstalled;
        clearStandardPaymentCloseReportContext();
        clearMobilePaymentReturnPending();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('KG closeUrl 메시지를 받으면 체크아웃 제출 상태를 해제한다', () => {
        const staleForm = document.createElement('form');
        staleForm.id = 'kginicis_pay_form_stale';
        document.body.appendChild(staleForm);

        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
                reason: 'inicis-close-url',
            },
        }));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
        expect(document.getElementById('kginicis_pay_form_stale')).toBeNull();
        expect(consumeStandardPaySdkReloadFlag()).toBe(true);
    });

    it('KG closeUrl 메시지를 받으면 활성 주문의 결제창 닫힘을 서버에 보고한다', async () => {
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { setLocal },
        };
        markStandardPaymentCloseReportContext({
            closeReportUrl: '/plugins/sirsoft-pay_kginicis/payment/close-report',
            oid: 'ORD-CLOSE-001',
            price: 10000,
            buyer_email: 'buyer@example.com',
            buyer_phone: '01012345678',
            payment_method: 'card',
        });
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
                reason: 'inicis-close-url',
            },
        }));

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(apiPost).toHaveBeenCalledWith(
            '/plugins/sirsoft-pay_kginicis/payment/close-report',
            {
                oid: 'ORD-CLOSE-001',
                price: 10000,
                buyer_email: 'buyer@example.com',
                buyer_phone: '01012345678',
                payment_method: 'card',
                reason: 'inicis-close-url',
            },
        );
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    it('closeUrl 메시지 없이 결제창 iframe 만 사라져도 close-report 를 호출하지 않는다', async () => {
        // 회귀 가드: iframe 존재 폴링 휴리스틱(B)을 다시 도입하지 않는다.
        // 성공(returnUrl 최상위 전송)·단순 이탈로 iframe 이 사라지는 경우 close-report 가
        // 발화하면 안 된다. 닫힘은 오직 closeUrl 의 postMessage(A)로만 보고된다.
        vi.useFakeTimers();
        const apiPost = vi.fn().mockResolvedValue({ success: true });
        windowRecord().G7Core = {
            api: { post: apiPost },
            state: { setLocal },
        };
        markStandardPaymentCloseReportContext({
            closeReportUrl: '/plugins/sirsoft-pay_kginicis/payment/close-report',
            oid: 'ORD-NO-POLL-001',
            price: 10000,
        });
        installPaymentCloseMessageListener();

        const iframe = document.createElement('iframe');
        iframe.className = 'inipay_iframe';
        iframe.src = 'https://stgstdpay.inicis.com/payMain/pay';
        iframe.style.width = '320px';
        iframe.style.height = '240px';
        document.body.appendChild(iframe);

        await vi.advanceTimersByTimeAsync(3600);

        iframe.remove();
        await vi.advanceTimersByTimeAsync(3600);

        expect(apiPost).not.toHaveBeenCalled();
    });

    it('다른 origin 메시지는 무시한다', () => {
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: 'https://example.com',
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
            },
        }));

        expect(setLocal).not.toHaveBeenCalled();
    });

    it('체크아웃 페이지가 아니면 상태를 변경하지 않는다', () => {
        window.history.pushState({}, '', '/shop/cart');

        resetCheckoutSubmittingState();

        expect(setLocal).not.toHaveBeenCalled();
    });

    it('모바일 결제 페이지에서 브라우저 뒤로 돌아오면 체크아웃 제출 상태를 해제한다', () => {
        markMobilePaymentReturnPending();
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    it('모바일 결제 복귀 표시가 이미 있으면 listener 설치 시점에도 제출 상태를 해제한다', () => {
        markMobilePaymentReturnPending();

        installPaymentCloseMessageListener();

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    it('모바일 결제 복귀 표시가 없으면 pageshow 에서 상태를 건드리지 않는다', () => {
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));

        expect(setLocal).not.toHaveBeenCalled();
    });

    it('모바일 결제 복귀를 여러 번 반복해도 매번 표시를 소비하고 상태를 해제한다', () => {
        installPaymentCloseMessageListener();

        markMobilePaymentReturnPending();
        window.dispatchEvent(new Event('pageshow'));
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });

        setLocal.mockClear();

        markMobilePaymentReturnPending();
        window.dispatchEvent(new Event('pageshow'));
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    it('복귀 시점에 G7Core가 준비되지 않았으면 표시를 유지했다가 다음 이벤트에서 해제한다', () => {
        delete windowRecord().G7Core;
        markMobilePaymentReturnPending();
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));
        expect(hasMobilePaymentReturnPending()).toBe(true);
        expect(setLocal).not.toHaveBeenCalled();

        windowRecord().G7Core = {
            state: {
                setLocal,
            },
        };
        window.dispatchEvent(new Event('focus'));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
        expect(hasMobilePaymentReturnPending()).toBe(false);
    });
});
