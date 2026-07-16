/* eslint-disable @typescript-eslint/no-explicit-any */

const METHOD_TO_TEXT: Record<string, string> = {
    nicepay_naverpay:   '네이버페이',
    nicepay_kakaopay:   '카카오페이',
    nicepay_samsungpay: '삼성페이',
    nicepay_applepay:   '애플페이',
    nicepay_payco:      'PAYCO',
    nicepay_skpay:      '11pay',
    nicepay_ssgpay:     'SSG페이',
    nicepay_lpay:       'L.pay',
};

// 흰색 내부 ring + 어두운 외부 ring으로 어떤 버튼 배경색에도 선택 상태가 확실히 보임
const SELECTED_SHADOW = '0 0 0 2px #ffffff, 0 0 0 5px rgba(0,0,0,0.55)';

function getEasyPayButtonContainer(): Element | null {
    const section = document.getElementById('nicepay_checkout_payment_section');
    if (!section) return null;
    const paras = section.querySelectorAll('p');
    for (const p of paras) {
        if (p.textContent?.includes('간편결제')) {
            return p.nextElementSibling;
        }
    }
    return null;
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

    // 확장 결제수단은 1급 시민 — 선택한 수단 ID 를 그대로 서버에 보낸다.
    // (과거에는 서버 검증이 확장 ID 를 422 로 막아 'card' 로 정규화한 serverPaymentMethod 를
    //  함께 저장했으나, 소비처가 없는 죽은 코드였고 위장 자체가 결함의 원인이었다 — #475)
    const isEasyPay = typeof paymentMethod === 'string' && paymentMethod.indexOf('nicepay_') === 0;
    (window as any).G7Core?.state?.setLocal?.({ paymentMethod });

    if (isEasyPay) {
        updateEasyPayButtonStyles(paymentMethod);
    } else {
        clearEasyPayButtonStyles();
    }
}

// 코어 결제 버튼(신용카드 등) 클릭 시에도 간편결제 선택 표시 해제
export function initEasyPayWatcher(): void {
    const templateApp = (window as any).__templateApp;
    if (typeof templateApp?.onGlobalStateChange !== 'function') return;

    templateApp.onGlobalStateChange((state: any) => {
        const paymentMethod = state?._local?.paymentMethod;
        if (typeof paymentMethod !== 'string') return;
        if (!paymentMethod.startsWith('nicepay_')) {
            clearEasyPayButtonStyles();
        }
    });
}
