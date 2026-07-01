import { afterEach, describe, expect, it } from 'vitest';
import {
    consumeStandardPaySdkReloadFlag,
    markStandardPaySdkForReload,
    resetStandardPaySdk,
} from '../paymentDomCleanup';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

describe('paymentDomCleanup', () => {
    afterEach(() => {
        delete (window as any).INIStdPay;
        delete windowRecord().__sirsoftKginicisReloadStandardPaySdk;
        document.head.innerHTML = '';
    });

    it('결제창 닫힘 후 다음 시도에서 표준결제 SDK를 재로드하도록 표시한다', () => {
        markStandardPaySdkForReload();

        expect(consumeStandardPaySdkReloadFlag()).toBe(true);
        expect(consumeStandardPaySdkReloadFlag()).toBe(false);
    });

    it('기존 KG 표준결제 SDK 전역 객체와 script 잔재를 제거한다', () => {
        const sdkUrl = 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js';
        const sdkScript = document.createElement('script');
        sdkScript.src = sdkUrl;
        document.head.appendChild(sdkScript);

        const thirdPartyScript = document.createElement('script');
        thirdPartyScript.src = 'https://stgstdpay.inicis.com/stdjs/INIStdPay_third-party.js';
        document.head.appendChild(thirdPartyScript);

        (window as any).INIStdPay = { pay: () => undefined };

        resetStandardPaySdk(sdkUrl);

        expect((window as any).INIStdPay).toBeUndefined();
        expect(document.querySelector(`script[src="${sdkUrl}"]`)).toBeNull();
        expect(document.querySelector('script[src*="/INIStdPay_third-party.js"]')).toBeNull();
    });
});
