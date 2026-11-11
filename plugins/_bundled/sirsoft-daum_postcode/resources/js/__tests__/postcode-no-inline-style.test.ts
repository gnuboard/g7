// e2e:allow 핸들러가 만드는 DOM 에 <style> 요소·style 속성 문자열이 없음을 잠근다 — 정책을 강제한 브라우저에서의 위반 0건은 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 주소 검색 3방식)이 덮는다
/**
 * 우편번호 검색 창은 인라인 스타일을 쓰지 않는다 — 엄격한 스타일 정책 호환
 *
 * 회귀 배경: 운영자가 `style-src 'self'; style-src-attr 'none'` 을 걸면 (A) `<style>` 요소 (C) `setAttribute('style', …)` 가
 * 막힌다. 종전 핸들러는 펼치기 영역·다크 모드 규칙을 `<style>` 로, 전체 화면 창·레이어 크기를 `setAttribute('style')` 로
 * 실어 정책을 켠 사이트에서 검색 창이 무스타일로 떴다. 규칙은 플러그인 CSS 파일(`resources/css/main.css` →
 * `dist/css/plugin.css`)로 옮기고, 설정값(레이어 너비·높이)만 CSS 변수로 싣는다. `el.style.x = …`·`el.style.cssText = …`·
 * `style.setProperty()` 는 정책이 막지 않으므로 그대로 쓴다.
 *
 * @scenario-manifest tests/scenarios/postcode-no-inline-style.yaml
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    LAYER_VIEWPORT_GUTTER_X,
    LAYER_VIEWPORT_GUTTER_Y,
    openPostcodeHandler,
} from '../handlers/openPostcode';

/** vitest 는 플러그인 디렉터리에서 실행한다 */
const PLUGIN_ROOT = process.cwd();
const MAIN_CSS = readFileSync(resolve(PLUGIN_ROOT, 'resources/css/main.css'), 'utf8');
const HANDLER_SOURCE = readFileSync(resolve(PLUGIN_ROOT, 'resources/js/handlers/openPostcode.ts'), 'utf8');
const ENTRY_SOURCE = readFileSync(resolve(PLUGIN_ROOT, 'resources/js/index.ts'), 'utf8');
const MANIFEST = JSON.parse(readFileSync(resolve(PLUGIN_ROOT, 'plugin.json'), 'utf8'));

/**
 * 가짜 우편번호 생성자 — embed·open 호출만 받는다.
 *
 * @return 생성자
 */
function makeFakePostcode(): any {
    return function FakePostcode(this: any) {
        this.embed = vi.fn();
        this.open = vi.fn();
    };
}

/**
 * 주소 입력 줄과 [주소 검색] 버튼을 세운다.
 *
 * @return 버튼
 */
function mountButton(): HTMLButtonElement {
    document.body.innerHTML = `
        <div id="row"><input name="zipcode" /><div><div><button id="btn" type="button">주소 검색</button></div></div></div>`;

    return document.getElementById('btn') as HTMLButtonElement;
}

/**
 * 클릭 시점의 화면 폭을 정한다.
 *
 * @param width 화면 폭(px)
 */
function setViewportWidth(width: number): void {
    Object.defineProperty(window, 'innerWidth', { value: width, configurable: true, writable: true });
}

/**
 * `setAttribute('style', …)` 호출을 센다 — jsdom 은 `style.cssText` 대입 뒤에도 style 속성을 보여 주므로,
 * 속성 유무가 아니라 「속성 문자열로 실었는가」를 호출로 잰다.
 *
 * @return 호출 기록을 돌려주는 함수
 */
function spyStyleAttributeWrites(): () => string[] {
    const original = Element.prototype.setAttribute;
    const writes: string[] = [];

    vi.spyOn(Element.prototype, 'setAttribute').mockImplementation(function (this: Element, name: string, value: string) {
        if (String(name).toLowerCase() === 'style') {
            writes.push(`${this.id || this.className}: ${value}`);
        }

        return original.call(this, name, value);
    });

    return () => writes;
}

describe('우편번호 검색 창 — 인라인 스타일 없음', () => {
    let styleWrites: () => string[];

    beforeEach(() => {
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        (Element.prototype as any).scrollIntoView = vi.fn();
        (window as any).kakao = { Postcode: makeFakePostcode() };
        (window as any).G7Core = {
            plugin: { getSettings: () => ({}) },
            state: { setLocal: vi.fn() },
            t: (key: string) => key,
            assets: { notifyFailure: vi.fn(), clearFailure: vi.fn() },
        };
        styleWrites = spyStyleAttributeWrites();
    });

    afterEach(() => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        document.body.innerHTML = '';
        document.body.style.overflow = '';
        document.head.querySelectorAll('style').forEach((el) => el.remove());
        delete (window as any).kakao;
        delete (window as any).G7Core;
        delete (Element.prototype as any).scrollIntoView;
        vi.restoreAllMocks();
    });

    /**
     * @scenario mode=inline
     * @effects no_style_element_injected, no_style_attribute_string
     */
    it('펼치기: <style> 요소를 싣지 않고 style 속성 문자열도 쓰지 않는다', async () => {
        setViewportWidth(1280);
        const button = mountButton();

        await openPostcodeHandler(
            { handler: 'openPostcode', params: { displayModePc: 'inline' } },
            { event: { type: 'click', currentTarget: null, target: button } } as any
        );

        expect(document.querySelector('.g7-postcode-inline')).not.toBeNull();
        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(styleWrites()).toEqual([]);
    });

    /**
     * @scenario mode=fullscreen
     * @effects no_style_element_injected, no_style_attribute_string, fullscreen_styled_by_class
     */
    it('전체 화면: 창과 그 안 frame 을 클래스로 꾸민다', async () => {
        setViewportWidth(390);

        await openPostcodeHandler(
            { handler: 'openPostcode', params: { displayModeMobile: 'fullscreen' } },
            {} as any
        );

        const dialog = document.getElementById('daum-postcode-fullscreen')!;
        expect(dialog.classList.contains('g7-postcode-inline-fullscreen')).toBe(true);
        expect(dialog.hasAttribute('style')).toBe(false);

        const frame = dialog.querySelector('.g7-postcode-inline__frame')!;
        expect(frame.classList.contains('g7-postcode-inline__frame--fill')).toBe(true);
        expect(frame.hasAttribute('style')).toBe(false);

        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(styleWrites()).toEqual([]);

        // 종전 style 속성이 하던 일을 CSS 파일의 클래스 규칙이 맡는다
        expect(MAIN_CSS).toMatch(/^\.g7-postcode-inline-fullscreen\s*\{[^}]*position: fixed[^}]*inset: 0[^}]*z-index: 9999[^}]*display: flex[^}]*flex-direction: column/m);
        expect(MAIN_CSS).toMatch(/^\.g7-postcode-inline__frame--fill\s*\{[^}]*flex: 1 1 auto[^}]*min-height: 0[^}]*height: 100%/m);
    });

    /**
     * @scenario mode=layer
     * @effects no_style_element_injected, no_style_attribute_string, layer_size_via_css_variables
     */
    it('레이어: 설정 너비·높이를 CSS 변수로 싣는다', async () => {
        setViewportWidth(1280);

        await openPostcodeHandler(
            { handler: 'openPostcode', params: { displayModePc: 'layer', width: 640, height: 720 } },
            {} as any
        );

        const layer = document.getElementById('daum-postcode-layer')!;
        expect(layer.style.getPropertyValue('--dp-width')).toBe('640px');
        expect(layer.style.getPropertyValue('--dp-height')).toBe('720px');

        expect(document.querySelectorAll('style')).toHaveLength(0);
        expect(styleWrites()).toEqual([]);
    });

    /**
     * @scenario mode=layer
     * @effects layer_gutter_constants_match_css
     */
    it('레이어 여백 상수와 CSS 파일의 값이 같다', () => {
        const rule = MAIN_CSS.match(/#daum-postcode-layer\s*\{([^}]*)\}/)?.[1] ?? '';

        expect(rule).toContain(`width: min(var(--dp-width, 500px), calc(100vw - ${LAYER_VIEWPORT_GUTTER_X}px));`);
        expect(rule).toContain(`height: min(var(--dp-height, 600px), calc(100vh - ${LAYER_VIEWPORT_GUTTER_Y}px));`);
        expect(rule).toContain(`height: min(var(--dp-height, 600px), calc(100dvh - ${LAYER_VIEWPORT_GUTTER_Y}px));`);
        // dvh 선언이 vh 선언 뒤에 와야 dvh 를 아는 브라우저가 그 값을 쓴다
        expect(rule.indexOf('100dvh')).toBeGreaterThan(rule.indexOf('100vh'));
    });

    /**
     * @scenario mode=source
     * @effects no_style_element_injected, no_style_attribute_string, css_declared_as_plugin_asset
     */
    it('핸들러 소스에 <style> 생성·style 속성 문자열이 없고, CSS 는 플러그인 자산으로 선언돼 있다', () => {
        expect(HANDLER_SOURCE).not.toMatch(/createElement\(\s*['"]style['"]\s*\)/);
        expect(HANDLER_SOURCE).not.toMatch(/setAttribute\(\s*['"]style['"]/);

        expect(ENTRY_SOURCE).toContain("import '../css/main.css';");
        expect(MANIFEST.assets.css).toEqual({ entry: 'resources/css/main.css', output: 'dist/css/plugin.css' });
    });

    /**
     * @scenario mode=source
     * @effects dark_rules_in_css_file
     */
    it('펼치기 영역·다크 모드 규칙이 CSS 파일에 있다', () => {
        for (const selector of [
            '.g7-postcode-inline',
            '.g7-postcode-inline__bar',
            '.g7-postcode-inline__close',
            '.g7-postcode-inline__frame',
            '.dark .g7-postcode-inline',
            '.dark .g7-postcode-inline-fullscreen',
            '.dark .g7-postcode-inline__bar',
            '.dark .g7-postcode-inline__close',
        ]) {
            expect(MAIN_CSS, selector).toContain(`\n${selector} {`);
        }
    });
});
