/**
 * 런타임 CSS 규칙 헬퍼 — `<style>` 요소 없이 규칙을 붙인다 (#163, engine-v1.91.0)
 *
 * [case:network-strict-style-csp-inline-style-blocked]
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { getCssText, getSheet, has, remove, setRules } from '../RuntimeStyleSheet';
import { adoptedCssTexts, FakeCSSStyleSheet, installAdoptedStub } from './adoptedStub';

describe('RuntimeStyleSheet', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        (window as any).__g7RuntimeStyles = undefined;
    });

    describe('생성형 시트를 쓸 수 없는 환경(jsdom·구형 브라우저)', () => {
        /**
         * @scenario case=runtime_style_fallback_element
         * @effects runtime_style_falls_back_to_style_element
         */
        it('<style id=key> 로 폴백하고 제거하면 사라진다', () => {
            const handle = setRules('g7-test-rules', '#a{color:red}');

            expect(handle.mode).toBe('element');
            const el = document.getElementById('g7-test-rules');
            expect(el?.tagName).toBe('STYLE');
            expect(el?.textContent).toBe('#a{color:red}');
            expect(has('g7-test-rules')).toBe(true);

            handle.remove();
            expect(document.getElementById('g7-test-rules')).toBeNull();
            expect(has('g7-test-rules')).toBe(false);
        });
    });

    describe('생성형 시트(adopted) 환경', () => {
        let restore: () => void;

        beforeEach(() => {
            restore = installAdoptedStub();
        });

        afterEach(() => restore());

        /**
         * @scenario case=runtime_style_adopted
         * @effects runtime_style_uses_adopted_sheet_without_style_element, runtime_style_marker_in_head
         */
        it('<style> 없이 시트 1개 + <head> 의 표식 <meta id=key> 를 남긴다', () => {
            const handle = setRules('g7-transition-overlay', ['#t{position:relative}', '#t::after{content:""}'], { markerAttrs: { 'data-x': '1', style: 'color:red' } });

            expect(handle.mode).toBe('adopted');
            expect(document.querySelectorAll('style').length).toBe(0);
            expect(adoptedCssTexts()).toEqual(['#t{position:relative}#t::after{content:""}']);

            const marker = document.head.querySelector('#g7-transition-overlay');
            expect(marker?.tagName).toBe('META');
            expect(marker?.getAttribute('data-g7-runtime-style')).toBe('adopted');
            expect(marker?.getAttribute('data-x')).toBe('1');
            // 표식 요소에 style 속성을 달지 않는다(그 자체가 정책 위반)
            expect(marker?.hasAttribute('style')).toBe(false);
            expect(getCssText('g7-transition-overlay')).toBe('#t{position:relative}#t::after{content:""}');
            expect(getSheet('g7-transition-overlay')).toBeInstanceOf(FakeCSSStyleSheet);
        });

        /**
         * @scenario case=runtime_style_adopted
         * @effects runtime_style_same_key_replaces
         */
        it('같은 key 는 교체한다(시트·표식 모두 1개)', () => {
            setRules('k', '#a{color:red}');
            setRules('k', '#a{color:blue}');

            expect(adoptedCssTexts()).toEqual(['#a{color:blue}']);
            expect(document.querySelectorAll('#k').length).toBe(1);
        });

        /**
         * @scenario case=runtime_style_adopted
         * @effects runtime_style_remove_keeps_foreign_sheets
         */
        it('제거하면 0 이 되고, 남이 넣은 시트는 보존한다', () => {
            const foreign = new FakeCSSStyleSheet();
            foreign.replaceSync('.foreign{}');
            (document as any).adoptedStyleSheets = [foreign];

            setRules('a', '#a{}');
            setRules('b', '#b{}');
            expect(adoptedCssTexts()).toEqual(['.foreign{}', '#a{}', '#b{}']);

            remove('a');
            expect(adoptedCssTexts()).toEqual(['.foreign{}', '#b{}']);
            expect(document.getElementById('a')).toBeNull();

            remove('b');
            remove('missing');
            expect(adoptedCssTexts()).toEqual(['.foreign{}']);
            expect(has('b')).toBe(false);
        });

        /**
         * @scenario case=runtime_style_fallback_element
         * @effects runtime_style_remove_clears_legacy_style_element
         */
        it('레지스트리에 없는 옛 <style id=key> 잔재도 remove 로 걷는다', () => {
            const stale = document.createElement('style');
            stale.id = 'g7-skeleton-overlay-style';
            document.head.appendChild(stale);

            remove('g7-skeleton-overlay-style');

            expect(document.getElementById('g7-skeleton-overlay-style')).toBeNull();
        });
    });
});
