/**
 * 상세설명 언어 탭 — 지원 언어·사이트 기본 언어 읽기
 *
 * @description
 * 7.1.0 출시 점검 D3-1: 지원 언어를 코어에 없는 `G7Core.config('app.supported_locales')` 로 읽어 늘 ko·en 이었고,
 * 기본 언어를 인자를 받지 않는 `G7Core.state.get('…')` 로 읽어 전역 상태 객체와 비교했다 — ja 탭을 추가할 수 없고
 * 사이트 기본 언어 탭 삭제 금지도 걸리지 않았다. 코어 공개 API `G7Core.locale.supported()` 와 `appConfig.siteLocale` 로 읽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { addDescriptionLocaleHandler, removeDescriptionLocaleHandler } from '../../handlers/descriptionLocaleHandlers';

let mockLocalState: Record<string, any> = {};
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
    locale: { current: () => 'ko', supported: () => ['ko', 'en', 'ja'] },
    t: (key: string) => key,
    toast,
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

describe('상세설명 언어 탭 — 코어 지원 언어·사이트 기본 언어', () => {
    beforeEach(() => {
        (window as any).G7Core = mockG7Core;
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        siteLocale = 'ko';
    });

    /**
     * @scenario case=c01
     * @effects description_locale_uses_core_locale_api
     */
    it('지원 언어 셋째(JA) 탭을 추가할 수 있다', () => {
        mockLocalState = {
            form: { description: { ko: '<p>본문</p>' }, description_mobile: {} },
            ui: { descriptionActiveLocales: ['ko'] },
        };

        addDescriptionLocaleHandler({ handler: 'addDescriptionLocale', params: { locale: 'ja' } }, {} as any);

        expect(toast.error).not.toHaveBeenCalled();
        expect(mockLocalState.ui.descriptionActiveLocales).toEqual(['ko', 'ja']);
        expect(mockLocalState.form.description).toHaveProperty('ja', '');
    });

    /**
     * @scenario case=c02
     * @effects description_locale_uses_core_locale_api
     */
    it('사이트 기본 언어(JA) 탭은 지우지 못한다', () => {
        siteLocale = 'ja';
        mockLocalState = {
            form: { description: { ja: '<p>本文</p>', ko: '' }, description_mobile: {} },
            ui: { descriptionActiveLocales: ['ja', 'ko'] },
        };

        removeDescriptionLocaleHandler({ handler: 'removeDescriptionLocale', params: { locale: 'ja' } }, {} as any);

        expect(toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=c03
     * @effects description_locale_uses_core_locale_api
     */
    it('사이트 기본 언어가 아닌 KO 탭은 지운다', () => {
        siteLocale = 'ja';
        mockLocalState = {
            form: { description: { ja: '<p>本文</p>', ko: '<p>본문</p>' }, description_mobile: {} },
            ui: { descriptionActiveLocales: ['ja', 'ko'] },
        };

        removeDescriptionLocaleHandler({ handler: 'removeDescriptionLocale', params: { locale: 'ko' } }, {} as any);

        expect(mockLocalState.ui.descriptionActiveLocales).toEqual(['ja']);
        expect(mockLocalState.form.description).not.toHaveProperty('ko');
    });
});
