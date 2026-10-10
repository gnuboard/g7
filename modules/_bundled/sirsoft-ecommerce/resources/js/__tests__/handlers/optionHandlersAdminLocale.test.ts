// e2e:allow 이커머스 전수 실측 S5 — Playwright MCP 브라우저로 화면 재현·재실측(이력 「Chrome MCP 정밀 점검 매트릭스」·결함 표 S5-D38). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * 옵션 생성의 필수 언어 = 사이트 기본 언어
 *
 * @description
 * 필수 언어를 사이트 기본 언어(`app.site_locale`, 프론트 `appConfig.siteLocale`) 하나로 통일했다(제품 결정
 * 2026-10-08, 후속 B 세션 B3) — 서버 `LocaleRequiredTranslatable`·관리자 다국어 입력의 * 표시·이 처리기 모두.
 * 종전(S5-D38)에는 화면 언어를 필수로 봐서, 영어 관리자가 만든 상품을 한국어 관리자가 저장하지 못했다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { generateOptionsHandler } from '../../handlers/optionHandlers';

let mockLocalState: Record<string, any> = {};
let currentLocale = 'en';
let siteLocale = 'ko';

const toast = { success: vi.fn(), error: vi.fn(), warning: vi.fn() };

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        get: () => ({ appConfig: { siteLocale } }),
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    // 코어 공개 API 그대로 — `G7Core.config()` 는 코어에 없다(D3-1). 지원 언어는 `locale.supported()` 로만 온다.
    locale: { current: () => currentLocale, supported: () => ['ko', 'en', 'ja'] },
    t: (key: string) => key,
    toast,
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const run = () => generateOptionsHandler({ handler: 'sirsoft-ecommerce.generateOptions' } as any, {} as any);

describe('옵션 생성 — 사이트 기본 언어 기준', () => {
    beforeEach(() => {
        (window as any).G7Core = mockG7Core;
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        currentLocale = 'en';
        siteLocale = 'ko';
    });

    /**
     * @scenario case=c01
     * @effects option_generate_follows_site_locale
     */
    it('영어로 보는 관리자도 사이트 기본 언어(KO) 칸을 채우면 옵션이 만들어진다', () => {
        currentLocale = 'en';
        siteLocale = 'ko';
        mockLocalState = {
            form: { list_price: 20000, selling_price: 15000, options: [] },
            ui: { optionInputs: [{ name: { ko: '색상', en: 'Color', ja: '' }, values: [{ ko: '빨강', en: 'Red' }, { ko: '파랑' }] }] },
        };

        run();

        expect(mockLocalState.form.options).toHaveLength(2);
        expect(toast.error).not.toHaveBeenCalled();
        expect(toast.warning).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=c02
     * @effects option_generate_follows_site_locale
     */
    it('영어로 보는 관리자가 EN 칸만 채우면 막는다 — 서버가 사이트 기본 언어(KO)를 필수로 본다', () => {
        currentLocale = 'en';
        siteLocale = 'ko';
        mockLocalState = {
            form: { list_price: 20000, selling_price: 15000, options: [] },
            ui: { optionInputs: [{ name: { en: 'Color', ko: '' }, values: [{ en: 'Red' }] }] },
        };

        run();

        expect(mockLocalState.form.options).toHaveLength(0);
    });

    /**
     * @scenario case=c03
     * @effects option_generate_follows_site_locale
     */
    it('사이트 기본 언어가 EN 이면 한국어로 보는 관리자도 EN 칸으로 만든다', () => {
        currentLocale = 'ko';
        siteLocale = 'en';
        mockLocalState = {
            form: { list_price: 20000, selling_price: 15000, options: [] },
            ui: { optionInputs: [{ name: { en: 'Color', ko: '' }, values: [{ en: 'Red' }, { en: 'Blue' }] }] },
        };

        run();

        expect(mockLocalState.form.options).toHaveLength(2);
    });

    /**
     * @scenario case=c04
     * @effects option_generate_follows_site_locale
     */
    it('사이트 기본 언어가 지원 언어 목록 셋째(JA)여도 JA 칸으로 만든다 — 지원 언어를 코어 locale.supported() 로 읽는다', () => {
        currentLocale = 'ko';
        siteLocale = 'ja';
        mockLocalState = {
            form: { list_price: 20000, selling_price: 15000, options: [] },
            ui: { optionInputs: [{ name: { ja: 'カラー', ko: '' }, values: [{ ja: '赤' }, { ja: '青' }] }] },
        };

        run();

        expect(mockLocalState.form.options).toHaveLength(2);
        expect(toast.error).not.toHaveBeenCalled();
        expect(toast.warning).not.toHaveBeenCalled();
    });
});
