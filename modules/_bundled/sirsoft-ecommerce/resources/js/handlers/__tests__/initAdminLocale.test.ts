/**
 * initAdminLocale 핸들러 테스트
 *
 * @description
 * 관리자 설정 화면의 확정 게이트와 서버 422 필드 오류 조회 키는 **현재 로케일**을
 * 정확히 알아야 한다. 레이아웃 표현식에는 그 값을 주는 변수가 없으므로(`$locale` 은
 * 편집 모드 전용, `_global.locale` 은 코어가 쓰지 않는다) 이 핸들러가 주입한다.
 *
 * 폴백이 잘못되면 증상이 조용하다 — 비-ko 관리자가 사유를 등록하지 못하는데
 * 화면에는 아무 오류도 남지 않는다. 그래서 우선순위 전건을 고정한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initAdminLocaleHandler } from '../initAdminLocale';

describe('initAdminLocaleHandler', () => {
    let stateSet: ReturnType<typeof vi.fn>;

    /**
     * G7Core 목을 설치한다.
     *
     * @param current `G7Core.locale.current()` 반환값 (undefined 면 API 부재)
     * @param withState state API 노출 여부
     */
    const installG7Core = (current?: string, withState = true): void => {
        stateSet = vi.fn();
        (window as any).G7Core = {
            locale: current === undefined ? {} : { current: () => current },
            ...(withState ? { state: { set: stateSet } } : {}),
        };
    };

    beforeEach(() => {
        window.localStorage.clear();
        installG7Core('ja');
    });

    afterEach(() => {
        delete (window as any).G7Core;
        window.localStorage.clear();
        vi.restoreAllMocks();
    });

    it('G7Core 가 알려 준 현재 로케일을 _global.locale 에 넣는다', () => {
        const result = initAdminLocaleHandler();

        expect(result).toBe('ja');
        expect(stateSet).toHaveBeenCalledWith({ locale: 'ja' });
    });

    it('G7Core 값이 localStorage 보다 우선한다 (요청 Accept-Language 와 같은 축)', () => {
        window.localStorage.setItem('g7_locale', 'en');
        installG7Core('ja');

        expect(initAdminLocaleHandler()).toBe('ja');
        expect(stateSet).toHaveBeenCalledWith({ locale: 'ja' });
    });

    it('G7Core 가 로케일을 모르면 localStorage 값을 쓴다', () => {
        window.localStorage.setItem('g7_locale', 'en');
        installG7Core(undefined);

        expect(initAdminLocaleHandler()).toBe('en');
        expect(stateSet).toHaveBeenCalledWith({ locale: 'en' });
    });

    it('둘 다 없으면 ko 로 떨어진다', () => {
        installG7Core(undefined);

        expect(initAdminLocaleHandler()).toBe('ko');
        expect(stateSet).toHaveBeenCalledWith({ locale: 'ko' });
    });

    it('빈 문자열은 값으로 치지 않는다 (빈 로케일이 주입되면 게이트가 통째로 무너진다)', () => {
        window.localStorage.setItem('g7_locale', 'en');
        installG7Core('');

        expect(initAdminLocaleHandler()).toBe('en');
    });

    it('state API 가 없어도 던지지 않는다 (init_actions 체인이 끊기면 화면이 비어 뜬다)', () => {
        installG7Core('ja', false);

        expect(() => initAdminLocaleHandler()).not.toThrow();
        expect(initAdminLocaleHandler()).toBe('ja');
    });
});
