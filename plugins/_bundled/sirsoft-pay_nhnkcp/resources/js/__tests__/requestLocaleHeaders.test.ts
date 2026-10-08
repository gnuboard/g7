/**
 * NHN KCP 화면 스크립트가 서버에 보내는 조회에 화면 언어를 싣는지 검증한다 (이커머스 전수 실측 S4 — S4-D16)
 *
 * 결함: 주문 상세·주문 완료·관리자 주문 상세 스크립트가 직접 보내는 조회(영수증·결제 표시·거래 조회)에 화면 언어가
 *   실리지 않아, 서버가 브라우저 기본 언어로 글자(영수증 줄 이름·버튼, 결제수단 표시 이름)를 만들었다. 한국어·일본어
 *   화면에 영어 「Receipt / View Receipt」 가 붙어 보였다. 코어 API 요청과 같이 g7_locale 을 Accept-Language 로 싣는다.
 */

import { readFileSync } from 'fs';
import { resolve } from 'path';
import { afterEach, describe, expect, it } from 'vitest';
import { localeHeaders } from '../requestLocale';

const FILES = ['mypageOrderShowInjector.ts', 'orderCompleteReceiptInjector.ts', 'adminOrderPaymentDisplayInjector.ts'];

describe('NHN KCP 화면 스크립트 조회 — 화면 언어', () => {
    afterEach(() => {
        localStorage.clear();
    });

    /**
     * @scenario case=locale_ja
     * @effects request_carries_screen_locale
     */
    it('화면 언어(g7_locale)를 Accept-Language 로 싣는다', () => {
        localStorage.setItem('g7_locale', 'ja');
        expect(localeHeaders()).toEqual({ 'Accept-Language': 'ja' });
    });

    /**
     * @scenario case=locale_unset
     * @effects request_carries_screen_locale
     */
    it('화면 언어가 정해지지 않았으면 아무것도 싣지 않는다(브라우저 기본값)', () => {
        expect(localeHeaders()).toEqual({});
    });

    /**
     * @scenario case=all_injector_requests
     * @effects request_carries_screen_locale
     */
    it.each(FILES)('%s 의 모든 fetch 요청이 화면 언어 헤더를 함께 싣는다', (file) => {
        const source = readFileSync(resolve(__dirname, '..', file), 'utf8');
        const calls = source.split(/\bfetch\(/).slice(1);
        expect(calls.length).toBeGreaterThan(0);
        for (const call of calls) {
            expect(call.slice(0, 700)).toMatch(/\.\.\.localeHeaders\(\)/);
        }
    });
});
