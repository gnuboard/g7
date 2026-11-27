/**
 * DevTools 패널 CSS 주입 — `<style>` 요소 없이 붙는지 잠근다 (공개 #163)
 *
 * 엄격한 스타일 정책(`style-src 'self'`)에서는 스크립트가 만든 `<style>` 요소가 적용되지 않는다.
 * 패널 CSS 는 생성형 스타일시트(`adoptedStyleSheets`)로 붙고, 지원하지 않는 환경(jsdom·구형 브라우저)에서만
 * 헬퍼의 `<style>` 폴백으로 내려간다.
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { DEVTOOLS_STYLES, DEVTOOLS_STYLE_KEY, injectDevToolsStyles } from '../ui/styles';
import { getCssText, getSheet } from '../../style/RuntimeStyleSheet';

/** 런타임 시트 흔적을 지운다 */
function resetRuntimeStyles(): void {
    (window as any).__g7RuntimeStyles?.clear();
    document.head.querySelectorAll('[data-g7-runtime-style]').forEach((n) => n.remove());
}

describe('injectDevToolsStyles', () => {
    beforeEach(resetRuntimeStyles);
    afterEach(resetRuntimeStyles);

    describe('생성형 시트 지원 환경(스텁)', () => {
        const g = globalThis as any;
        let originalCtor: unknown;
        let hadOwnCtor = false;
        let adoptedDescriptor: PropertyDescriptor | undefined;
        let replaceSync: ReturnType<typeof vi.fn>;

        beforeEach(() => {
            originalCtor = g.CSSStyleSheet;
            hadOwnCtor = Object.prototype.hasOwnProperty.call(g, 'CSSStyleSheet');
            adoptedDescriptor = Object.getOwnPropertyDescriptor(document, 'adoptedStyleSheets');

            class FakeSheet {
                public text = '';
            }
            replaceSync = vi.fn(function (this: FakeSheet, css: string) {
                this.text = css;
            });
            (FakeSheet.prototype as any).replaceSync = replaceSync;
            g.CSSStyleSheet = FakeSheet;

            let adopted: unknown[] = [];
            Object.defineProperty(document, 'adoptedStyleSheets', {
                configurable: true,
                get: () => adopted,
                set: (next: unknown[]) => {
                    adopted = next;
                },
            });
        });

        afterEach(() => {
            if (hadOwnCtor) g.CSSStyleSheet = originalCtor;
            else delete g.CSSStyleSheet;
            if (adoptedDescriptor) Object.defineProperty(document, 'adoptedStyleSheets', adoptedDescriptor);
            else delete (document as any).adoptedStyleSheets;
        });

        /**
         * @scenario case=devtools_no_style_element
         * @effects devtools_styles_via_runtime_sheet
         */
        it('<style> 요소를 만들지 않고 표식 <meta> 와 생성형 시트를 붙인다', () => {
            const before = document.querySelectorAll('style').length;

            injectDevToolsStyles();

            expect(document.querySelectorAll('style').length).toBe(before);
            const marker = document.getElementById(DEVTOOLS_STYLE_KEY);
            expect(marker?.tagName).toBe('META');
            expect(marker?.getAttribute('data-g7-runtime-style')).toBe('adopted');
            expect((document as any).adoptedStyleSheets).toEqual([getSheet(DEVTOOLS_STYLE_KEY)]);
            expect(getCssText(DEVTOOLS_STYLE_KEY)).toBe(DEVTOOLS_STYLES);
        });

        /**
         * @scenario case=devtools_no_style_element
         * @effects devtools_styles_attached_once
         */
        it('여러 번 불러도 한 번만 붙인다', () => {
            injectDevToolsStyles();
            injectDevToolsStyles();
            injectDevToolsStyles();

            expect(replaceSync).toHaveBeenCalledTimes(1);
            expect((document as any).adoptedStyleSheets.length).toBe(1);
            expect(document.querySelectorAll(`[id="${DEVTOOLS_STYLE_KEY}"]`).length).toBe(1);
        });
    });

    describe('미지원 환경(jsdom 폴백)', () => {
        /**
         * @scenario case=devtools_no_style_element
         * @effects runtime_style_falls_back_to_style_element
         */
        it('같은 id 의 <style> 하나로 내려가고 중복 호출에도 하나다', () => {
            injectDevToolsStyles();
            injectDevToolsStyles();

            const nodes = document.querySelectorAll(`[id="${DEVTOOLS_STYLE_KEY}"]`);
            expect(nodes.length).toBe(1);
            expect(nodes[0].tagName).toBe('STYLE');
            expect(nodes[0].textContent).toBe(DEVTOOLS_STYLES);
        });
    });
});
