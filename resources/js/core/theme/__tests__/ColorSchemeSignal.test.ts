/**
 * 색상 테마 중립 표식
 *
 * 확장 CSS 는 `<html data-g7-color-scheme>` 만 본다. 코어가 템플릿 선언(세 전략)을 그 표식으로 옮기는지,
 * 템플릿이 다크를 켜고 끌 때 표식이 따라가는지 확인한다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    ColorSchemeSignal,
    COLOR_SCHEME_ATTRIBUTE,
    normalizeColorSchemeDeclaration,
} from '../ColorSchemeSignal';

/**
 * MutationObserver 콜백이 돌 때까지 기다린다.
 *
 * @returns Promise<void>
 */
const flush = () => new Promise<void>((resolve) => setTimeout(resolve, 0));

describe('ColorSchemeSignal', () => {
    let signal: ColorSchemeSignal;

    beforeEach(() => {
        signal = new ColorSchemeSignal();
        document.documentElement.className = '';
        document.body.className = '';
        document.documentElement.removeAttribute(COLOR_SCHEME_ATTRIBUTE);
    });

    afterEach(() => {
        signal.stop();
        vi.restoreAllMocks();
        delete (window as any).G7Config;
    });

    // @scenario case=color_signal_c01
    // @effects html_color_scheme_marker_follows_template
    it('선언을 정규화한다 — 모르는 값과 선택자 없는 ancestor-class 는 none', () => {
        expect(normalizeColorSchemeDeclaration(undefined)).toEqual({ strategy: 'none' });
        expect(normalizeColorSchemeDeclaration({ strategy: 'weird' })).toEqual({ strategy: 'none' });
        expect(normalizeColorSchemeDeclaration({ strategy: 'ancestor-class' })).toEqual({ strategy: 'none' });
        expect(normalizeColorSchemeDeclaration({ strategy: 'ancestor-class', selector: ' .dark ' }))
            .toEqual({ strategy: 'ancestor-class', selector: '.dark' });
        expect(normalizeColorSchemeDeclaration({ strategy: 'media-query' })).toEqual({ strategy: 'media-query' });
    });

    // @scenario case=color_signal_c02
    // @effects html_color_scheme_marker_follows_template
    it('none — 항상 light 를 단다', () => {
        document.documentElement.classList.add('dark');
        signal.start({ strategy: 'none' });

        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('light');
        expect(signal.get()).toBe('light');
    });

    // @scenario case=color_signal_c03
    // @effects html_color_scheme_marker_follows_template
    it('선언이 인자로 없으면 G7Config.colorScheme 을 읽는다', () => {
        (window as any).G7Config = { colorScheme: { strategy: 'ancestor-class', selector: '.dark' } };
        document.documentElement.classList.add('dark');
        signal.start();

        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('dark');
    });

    // @scenario case=color_signal_c04
    // @effects html_color_scheme_marker_follows_template
    it('ancestor-class — html 클래스 토글을 매번 따라간다(3회 연속)', async () => {
        signal.start({ strategy: 'ancestor-class', selector: '.dark' });
        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('light');

        const seen: Array<string | null> = [];
        for (let i = 0; i < 3; i += 1) {
            document.documentElement.classList.toggle('dark');
            await flush();
            seen.push(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE));
        }

        expect(seen).toEqual(['dark', 'light', 'dark']);
    });

    // @scenario case=color_signal_c05
    // @effects html_color_scheme_marker_follows_template
    it('ancestor-class — body 에 붙은 선택자도 다크로 본다', async () => {
        signal.start({ strategy: 'ancestor-class', selector: '.theme-dark' });
        document.body.classList.add('theme-dark');
        await flush();

        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('dark');
    });

    // @scenario case=color_signal_c06
    // @effects html_color_scheme_marker_follows_template
    it('ancestor-class — 잘못된 선택자는 light 로 남는다(예외 없음)', () => {
        expect(() => signal.start({ strategy: 'ancestor-class', selector: '..[' })).not.toThrow();
        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('light');
    });

    // @scenario case=color_signal_c07
    // @effects html_color_scheme_marker_follows_template
    it('media-query — prefers-color-scheme 변경을 따라간다', () => {
        // 콜백 안에서 채워지는 값이라 변수 대신 보관 객체에 둔다(변수면 초기값 null 로 좁혀져 호출할 수 없다)
        const captured: { listener: (() => void) | null } = { listener: null };
        const mql = {
            matches: false,
            addEventListener: (_: string, cb: () => void) => { captured.listener = cb; },
            removeEventListener: vi.fn(),
        };
        vi.spyOn(window, 'matchMedia').mockImplementation(() => mql as unknown as MediaQueryList);

        signal.start({ strategy: 'media-query' });
        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('light');

        mql.matches = true;
        captured.listener?.();
        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('dark');
    });

    // @scenario case=color_signal_c08
    // @effects html_color_scheme_marker_follows_template
    it('stop 뒤에는 클래스가 바뀌어도 표식을 고치지 않는다', async () => {
        signal.start({ strategy: 'ancestor-class', selector: '.dark' });
        signal.stop();
        document.documentElement.classList.add('dark');
        await flush();

        expect(document.documentElement.getAttribute(COLOR_SCHEME_ATTRIBUTE)).toBe('light');
    });
});
