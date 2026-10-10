/**
 * NHN KCP 결제 안내·오류 문구는 번역 키로 낸다 (#599)
 *
 * 종전에는 「NHN KCP는 KRW 결제만 지원합니다」「애플페이는 IOS 기기에 …」「KCP 모바일 승인키 획득 실패」「KCP 오류 […]」를
 * 스크립트에 한국어(일부는 한국어/영어 두 갈래)로 박아, 일본어 등 다른 언어 화면에서도 한국어나 영어로 나왔다
 * 이제 플러그인 번역 키(ko·en, 일본어는 언어팩)를 쓰고, 번역 엔진이 없을 때만
 * 한국어 문구로 대신한다. PG 가 돌려준 오류 원문은 그대로 보여 준다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { requestPaymentHandler } from '../handlers/requestPayment';
import { applePayUnsupportedMessage } from '../support/applePayDevice';
import { translateNhnkcp } from '../translate';

const JA: Record<string, string> = {
    'sirsoft-pay_nhnkcp.payment.error.krw_only': 'NHN KCPはKRW決済のみ対応しています。({{currency}})',
    'sirsoft-pay_nhnkcp.payment.error.applepay_ios_only': 'Apple PayはiOS端末のモバイル決済でのみ利用できます。',
};

function fakeT(key: string, params?: Record<string, string | number>): string {
    const text = JA[key];
    if (!text) return key;

    return text.replace(/\{\{(\w+)\}\}/g, (m, k) => (params && k in params ? String(params[k]) : m));
}

describe('NHN KCP — 결제 안내·오류 문구 번역 키', () => {
    let setLocal: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        document.documentElement.lang = 'ja';
        setLocal = vi.fn();
        (window as any).G7Core = { t: fakeT, api: { get: vi.fn() }, state: { setLocal }, modal: { open: vi.fn() } };
    });

    afterEach(() => {
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    /**
     * @scenario message=krw_only
     * @effects message_from_translation_key
     */
    it('KRW 외 통화 안내를 번역 키로 내고 통화를 채운다', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: { order_number: 'O-1', order_name: 'T', amount: 1, payment_method: 'card', currency: 'USD' } } });

        expect(setLocal).toHaveBeenCalledWith(expect.objectContaining({ paymentErrorMessage: 'NHN KCPはKRW決済のみ対応しています。(USD)' }));
    });

    /**
     * @scenario message=applepay_ios_only
     * @effects message_from_translation_key
     */
    it('애플페이 iOS 전용 안내를 번역 키로 낸다', () => {
        expect(applePayUnsupportedMessage()).toBe('Apple PayはiOS端末のモバイル決済でのみ利用できます。');
    });

    /**
     * @scenario message=fallback
     * @effects fallback_fills_params
     */
    it('번역 엔진이 없으면 한국어 문구로 대신하고 값을 채운다', () => {
        delete (window as any).G7Core;

        expect(translateNhnkcp('payment.error.pg_error', 'KCP 오류 [{{code}}]: {{message}}', { code: '3001', message: '한도 초과' }))
            .toBe('KCP 오류 [3001]: 한도 초과');
    });
});
