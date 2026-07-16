/* eslint-disable @typescript-eslint/no-explicit-any */

import {
    applePayUnsupportedMessage,
    isIosMobileDevice,
    isNhnKcpApplePayMethod,
} from '../support/applePayDevice';

const METHOD_TO_TEXT: Record<string, string> = {
    nhnkcp_payco:          'PAYCO',
    nhnkcp_naverpay:       '네이버페이',
    nhnkcp_naverpay_point: '네이버페이 포인트',
    nhnkcp_kakaopay:       '카카오페이',
    nhnkcp_applepay:       'Apple Pay',
};

const SELECTED_SHADOW = '0 0 0 2px #ffffff, 0 0 0 5px rgba(0,0,0,0.55)';

function getEasyPayButtonContainer(): Element | null {
    const section = document.querySelector('.nhnkcp-easy-pay-btns');
    return section ?? null;
}

function clearEasyPayButtonStyles(): void {
    const container = getEasyPayButtonContainer();
    if (!container) return;
    container.querySelectorAll<HTMLButtonElement>('button').forEach(btn => {
        btn.style.boxShadow = '';
        btn.style.outline = '';
    });
}

function updateEasyPayButtonStyles(selectedMethod: string): void {
    const container = getEasyPayButtonContainer();
    if (!container) return;

    const selectedText = METHOD_TO_TEXT[selectedMethod];
    container.querySelectorAll<HTMLButtonElement>('button').forEach(btn => {
        if (btn.textContent?.trim() === selectedText) {
            btn.style.boxShadow = SELECTED_SHADOW;
            btn.style.outline = 'none';
        } else {
            btn.style.boxShadow = '';
            btn.style.outline = '';
        }
    });
}

export function setPaymentMethodHandler(action: any): void {
    const paymentMethod = action.params?.paymentMethod;
    if (!paymentMethod) return;

    const G7Core = (window as any).G7Core;
    if (isNhnKcpApplePayMethod(paymentMethod) && !isIosMobileDevice()) {
        const message = applePayUnsupportedMessage();
        G7Core?.state?.setLocal?.({
            paymentErrorMessage: message,
            isSubmittingOrder: false,
        });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
        clearEasyPayButtonStyles();
        return;
    }

    // 확장 결제수단은 1급 시민 — 선택한 수단 ID 를 그대로 서버에 보낸다.
    // (과거에는 서버 검증이 확장 ID 를 422 로 막아 'card' 로 정규화한 serverPaymentMethod 를
    //  함께 저장했으나, 소비처가 없는 죽은 코드였고 위장 자체가 결함의 원인이었다 — #475)
    const isEasyPay = typeof paymentMethod === 'string' && paymentMethod.startsWith('nhnkcp_');
    G7Core?.state?.setLocal?.({ paymentMethod });

    if (isEasyPay) {
        updateEasyPayButtonStyles(paymentMethod);
    } else {
        clearEasyPayButtonStyles();
    }
}
