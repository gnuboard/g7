/**
 * transition_overlay 레이아웃 옵션 단위 테스트
 *
 * 페이지 전환 시 오버레이를 표시하여
 * React 비동기 렌더링 중 stale DOM flash를 방지하는 기능 검증
 *
 * - target 지정 시: 런타임 CSS 규칙(RuntimeStyleSheet) + ::after 의사 요소 (React 렌더 트리 외부).
 *   jsdom 에는 생성형 스타일시트가 없어 `<style id>` 폴백으로 붙는다 — 실제 브라우저 경로(<style> 0개)는
 *   `TemplateApp.overlayNoInlineStyle.test.ts` 가 실제 TemplateApp 메서드로 잠근다 (#163, engine-v1.91.0)
 * - target 미지정 시: DOM 오버레이 (document.body에 fixed)
 *
 * @since engine-v1.23.0
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { remove as removeRuntimeStyleRules, setRules as setRuntimeStyleRules } from '../style/RuntimeStyleSheet';

type SkeletonConfig = {
    component: string;
    animation?: 'pulse' | 'wave' | 'none';
    iteration_count?: number;
};

type SpinnerConfig = {
    component?: string;
    text?: string;
};

type TransitionOverlayConfig = boolean | { enabled: boolean; style?: string; target?: string; skeleton?: SkeletonConfig; spinner?: SpinnerConfig };

interface NormalizedConfig {
    enabled: boolean;
    style: string;
    target?: string;
    skeleton?: SkeletonConfig;
    spinner?: SpinnerConfig;
}

/**
 * config 정규화 (TemplateApp.showTransitionOverlay 내부 로직)
 */
function normalizeConfig(config: TransitionOverlayConfig): NormalizedConfig {
    return typeof config === 'boolean'
        ? { enabled: config, style: 'opaque' }
        : { enabled: config.enabled, style: config.style || 'opaque', target: config.target, skeleton: config.skeleton, spinner: config.spinner };
}

/**
 * 스타일별 CSS 생성
 */
function getStyleCss(style: string, isDark: boolean): { bgCss: string; extraCss: string } {
    switch (style) {
        case 'blur':
            return {
                bgCss: isDark ? 'rgba(17,24,39,0.3)' : 'rgba(255,255,255,0.3)',
                extraCss: 'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);',
            };
        case 'fade':
            return {
                bgCss: isDark ? 'rgba(17,24,39,0.8)' : 'rgba(255,255,255,0.8)',
                extraCss: '',
            };
        case 'opaque':
        default:
            return {
                bgCss: isDark ? 'rgb(17,24,39)' : 'rgb(249,250,251)',
                extraCss: '',
            };
    }
}

/**
 * 오버레이 생성 (TemplateApp.showTransitionOverlay 로직)
 *
 * - target 지정 시: <style> 태그를 <head>에 삽입 (::after 의사 요소)
 * - target 미지정 시: <div> 오버레이를 body에 삽입 (position:fixed)
 */
function showTransitionOverlay(config: TransitionOverlayConfig): HTMLElement | null {
    const normalized = normalizeConfig(config);
    if (!normalized.enabled) return null;

    // 기존 오버레이 제거
    hideTransitionOverlay();

    const isDark = document.documentElement.classList.contains('dark');
    const { bgCss, extraCss } = getStyleCss(normalized.style, isDark);

    if (normalized.target) {
        // 런타임 규칙 방식 — 실제 구현과 같이 RuntimeStyleSheet 를 거친다(jsdom 은 <style id> 폴백으로 붙는다) (#163)
        const selector = `#${normalized.target}`;
        setRuntimeStyleRules(
            'g7-transition-overlay',
            `${selector}{position:relative;z-index:0;}${selector}::after{content:'';position:absolute;inset:0;background:${bgCss};${extraCss}z-index:2147483647;pointer-events:none;}`
        );
        return document.getElementById('g7-transition-overlay');
    } else {
        // DOM 오버레이 방식 (폴백) — TemplateApp.showTransitionOverlay 와 동일하게 개별 속성으로
        // 설정한다. cssText 일괄 설정은 backdrop-filter(jsdom 미지원) 가 섞이면 그 선언 블록 전체가
        // 거부되어 앞선 background 까지 무효화된다. 개별 setProperty 는 미지원 속성만 무시되고
        // background 는 보존된다(실제 브라우저 동작은 cssText 일괄과 동일).
        const overlay = document.createElement('div');
        overlay.id = 'g7-transition-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.style.position = 'fixed';
        overlay.style.inset = '0';
        overlay.style.zIndex = '9999';
        overlay.style.pointerEvents = 'none';
        overlay.style.background = bgCss;
        for (const decl of extraCss.split(';')) {
            const idx = decl.indexOf(':');
            if (idx === -1) continue;
            overlay.style.setProperty(decl.slice(0, idx).trim(), decl.slice(idx + 1).trim());
        }
        document.body.appendChild(overlay);
        return overlay;
    }
}

/**
 * 오버레이 제거 (TemplateApp.hideTransitionOverlay 로직)
 */
function hideTransitionOverlay(): void {
    removeRuntimeStyleRules('g7-transition-overlay');
    const existing = document.getElementById('g7-transition-overlay');
    if (existing) existing.remove();
}

describe('transition_overlay', () => {
    beforeEach(() => {
        hideTransitionOverlay();
        document.documentElement.classList.remove('dark');
    });

    afterEach(() => {
        hideTransitionOverlay();
        document.documentElement.classList.remove('dark');
        const target = document.getElementById('main_content_area');
        if (target) target.remove();
    });

    describe('normalizeConfig', () => {
        /**
         * @scenario case=overlay_legacy_algorithm
         * @effects overlay_config_normalized
         */
        it('true를 opaque 스타일로 정규화해야 한다', () => {
            expect(normalizeConfig(true)).toEqual({ enabled: true, style: 'opaque' });
        });

        /** @effects overlay_config_normalized */
        it('false를 enabled: false로 정규화해야 한다', () => {
            expect(normalizeConfig(false)).toEqual({ enabled: false, style: 'opaque' });
        });

        /** @effects overlay_config_normalized */
        it('객체 config를 그대로 반환해야 한다', () => {
            expect(normalizeConfig({ enabled: true, style: 'blur' })).toEqual({ enabled: true, style: 'blur' });
        });

        /** @effects overlay_config_normalized */
        it('style 미지정 시 opaque를 기본값으로 사용해야 한다', () => {
            expect(normalizeConfig({ enabled: true })).toEqual({ enabled: true, style: 'opaque' });
        });

        /** @effects overlay_config_normalized */
        it('target을 보존해야 한다', () => {
            expect(normalizeConfig({ enabled: true, target: 'my_container' }))
                .toEqual({ enabled: true, style: 'opaque', target: 'my_container' });
        });
    });

    describe('target 미지정 — DOM 오버레이 (폴백)', () => {
        /** @effects overlay_fullpage_div_via_cssom */
        it('transition_overlay: true 시 body에 fixed 오버레이를 생성해야 한다', () => {
            const el = showTransitionOverlay(true);

            expect(el).not.toBeNull();
            expect(el!.tagName).toBe('DIV');
            expect(el!.id).toBe('g7-transition-overlay');
            expect(document.body.contains(el!)).toBe(true);
        });

        /** @effects overlay_fullpage_div_via_cssom */
        it('transition_overlay: false 시 오버레이를 생성하지 않아야 한다', () => {
            const el = showTransitionOverlay(false);
            expect(el).toBeNull();
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
        });

        /** @effects overlay_fullpage_div_via_cssom */
        it('공통 스타일(fixed, inset, z-index, pointer-events)이 적용되어야 한다', () => {
            const el = showTransitionOverlay(true) as HTMLDivElement;

            expect(el.style.position).toBe('fixed');
            expect(el.style.inset).toBe('0');
            expect(el.style.zIndex).toBe('9999');
            expect(el.style.pointerEvents).toBe('none');
        });

        /** @effects overlay_fullpage_div_via_cssom */
        it('opaque 스타일 시 gray-50 배경을 사용해야 한다', () => {
            const el = showTransitionOverlay(true) as HTMLDivElement;
            expect(el.style.background).toBe('rgb(249, 250, 251)');
        });

        /** @effects overlay_fullpage_div_via_cssom */
        it('blur 스타일 시 backdrop-filter를 적용해야 한다', () => {
            const el = showTransitionOverlay({ enabled: true, style: 'blur' }) as HTMLDivElement;
            // jsdom 은 backdrop-filter 를 CSS 프로퍼티로 인식하지 않는다. 개별 속성 설정이라
            // backdrop-filter 는 빈 값이 되지만 background 는 보존된다(cssText 일괄이면 동반 무효화).
            // backdrop-filter 적용 자체는 CSS <style> 주입 모드 테스트(css.toContain)에서 검증.
            expect(el.style.background).toBe('rgba(255, 255, 255, 0.3)');
        });

        /** @effects overlay_fullpage_div_via_cssom */
        it('fade 스타일 시 반투명 배경을 적용해야 한다', () => {
            const el = showTransitionOverlay({ enabled: true, style: 'fade' }) as HTMLDivElement;
            expect(el.style.background).toBe('rgba(255, 255, 255, 0.8)');
        });
    });

    describe('target 지정 — CSS <style> 주입', () => {
        function createTargetContainer(id: string): HTMLDivElement {
            const container = document.createElement('div');
            container.id = id;
            document.body.appendChild(container);
            return container;
        }

        /** @effects overlay_target_rules_via_runtime_sheet */
        it('target 지정 시 <style> 태그를 <head>에 삽입해야 한다', () => {
            createTargetContainer('main_content_area');

            const el = showTransitionOverlay({ enabled: true, target: 'main_content_area' });

            expect(el).not.toBeNull();
            expect(el!.tagName).toBe('STYLE');
            expect(el!.id).toBe('g7-transition-overlay');
            expect(document.head.contains(el!)).toBe(true);
        });

        /** @effects overlay_target_rules_via_runtime_sheet */
        it('CSS에 타겟 selector와 ::after 규칙이 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const el = showTransitionOverlay({ enabled: true, target: 'main_content_area' });

            const css = el!.textContent!;
            expect(css).toContain('#main_content_area{position:relative;z-index:0;}');
            expect(css).toContain('#main_content_area::after{');
            expect(css).toContain('position:absolute;');
            expect(css).toContain('inset:0;');
            expect(css).toContain('pointer-events:none;');
        });

        /** @effects overlay_target_rules_via_runtime_sheet */
        it('opaque 스타일 시 gray-50 배경 CSS가 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const el = showTransitionOverlay({ enabled: true, target: 'main_content_area' });
            expect(el!.textContent).toContain('background:rgb(249,250,251)');
        });

        /** @effects overlay_target_rules_via_runtime_sheet */
        it('blur 스타일 시 backdrop-filter CSS가 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const el = showTransitionOverlay({ enabled: true, target: 'main_content_area', style: 'blur' });
            const css = el!.textContent!;
            expect(css).toContain('backdrop-filter:blur(4px)');
            expect(css).toContain('background:rgba(255,255,255,0.3)');
        });

        /** @effects overlay_target_rules_via_runtime_sheet */
        it('fade 스타일 시 반투명 배경 CSS가 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const el = showTransitionOverlay({ enabled: true, target: 'main_content_area', style: 'fade' });
            expect(el!.textContent).toContain('background:rgba(255,255,255,0.8)');
        });

        /** @effects overlay_target_rules_via_runtime_sheet */
        it('제거 시 <style> 태그가 <head>에서 삭제되어야 한다', () => {
            createTargetContainer('main_content_area');

            showTransitionOverlay({ enabled: true, target: 'main_content_area' });
            expect(document.getElementById('g7-transition-overlay')).toBeTruthy();

            hideTransitionOverlay();
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
        });
    });

    describe('다크 모드', () => {
        /** @effects overlay_dark_mode_colors */
        it('다크 모드에서 opaque DOM 오버레이 시 gray-900 배경을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            const el = showTransitionOverlay(true) as HTMLDivElement;
            expect(el.style.background).toBe('rgb(17, 24, 39)');
        });

        /** @effects overlay_dark_mode_colors */
        it('다크 모드에서 target CSS 주입 시 gray-900 배경이 포함되어야 한다', () => {
            document.documentElement.classList.add('dark');
            const container = document.createElement('div');
            container.id = 'main_content_area';
            document.body.appendChild(container);

            const el = showTransitionOverlay({ enabled: true, target: 'main_content_area' });
            expect(el!.textContent).toContain('background:rgb(17,24,39)');
        });

        /** @effects overlay_dark_mode_colors */
        it('다크 모드에서 blur 스타일 시 dark 반투명 배경을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            const el = showTransitionOverlay({ enabled: true, style: 'blur' }) as HTMLDivElement;
            expect(el.style.background).toBe('rgba(17, 24, 39, 0.3)');
        });
    });

    describe('라우트 변경 시나리오', () => {
        /** @effects overlay_show_hide_idempotent */
        it('오버레이 표시 후 제거가 정상 동작해야 한다', () => {
            showTransitionOverlay(true);
            expect(document.getElementById('g7-transition-overlay')).toBeTruthy();

            hideTransitionOverlay();
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
        });

        /** @effects overlay_show_hide_idempotent */
        it('target CSS 주입 후 제거가 정상 동작해야 한다', () => {
            const container = document.createElement('div');
            container.id = 'content';
            document.body.appendChild(container);

            showTransitionOverlay({ enabled: true, target: 'content' });
            expect(document.getElementById('g7-transition-overlay')).toBeTruthy();

            hideTransitionOverlay();
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
            container.remove();
        });

        /** @effects overlay_show_hide_idempotent */
        it('중복 호출 시 기존 오버레이를 제거하고 새로 생성해야 한다', () => {
            showTransitionOverlay(true);
            showTransitionOverlay(true);

            const overlays = document.querySelectorAll('#g7-transition-overlay');
            expect(overlays.length).toBe(1);
        });

        /** @effects overlay_show_hide_idempotent */
        it('hideTransitionOverlay 중복 호출 시 에러 없이 처리해야 한다', () => {
            expect(() => {
                hideTransitionOverlay();
                hideTransitionOverlay();
            }).not.toThrow();
        });
    });

    describe('skeleton 스타일 (@since engine-v1.24.0)', () => {
        /** @effects skeleton_overlay_config_and_fallback */
        it('skeleton 스타일을 정규화해야 한다', () => {
            const config: TransitionOverlayConfig = {
                enabled: true,
                style: 'skeleton',
                target: 'main_content_area',
                skeleton: {
                    component: 'PageSkeleton',
                    animation: 'pulse',
                    iteration_count: 5,
                },
            };
            const normalized = normalizeConfig(config);

            expect(normalized.style).toBe('skeleton');
            expect(normalized.target).toBe('main_content_area');
            expect(normalized.skeleton).toEqual({
                component: 'PageSkeleton',
                animation: 'pulse',
                iteration_count: 5,
            });
        });

        /** @effects skeleton_overlay_config_and_fallback */
        it('skeleton 설정 없이 skeleton 스타일 시 opaque 폴백 CSS를 생성해야 한다', () => {
            // skeleton 스타일이지만 skeleton 설정이 없으면 CSS 폴백
            const el = showTransitionOverlay({ enabled: true, style: 'skeleton' }) as HTMLDivElement;
            expect(el).not.toBeNull();
            // opaque와 동일한 배경 (폴백)
            expect(el.style.background).toBe('rgb(249, 250, 251)');
        });

        /** @effects skeleton_overlay_config_and_fallback */
        it('skeleton 설정의 기본값이 올바르게 적용되어야 한다', () => {
            const config: TransitionOverlayConfig = {
                enabled: true,
                style: 'skeleton',
                target: 'main_content_area',
                skeleton: {
                    component: 'PageSkeleton',
                },
            };
            const normalized = normalizeConfig(config);

            expect(normalized.skeleton!.component).toBe('PageSkeleton');
            expect(normalized.skeleton!.animation).toBeUndefined();
            expect(normalized.skeleton!.iteration_count).toBeUndefined();
        });

        /** @effects skeleton_overlay_config_and_fallback */
        it('skeleton 스타일 다크 모드에서 opaque 폴백 시 gray-900 배경을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            const el = showTransitionOverlay({ enabled: true, style: 'skeleton' }) as HTMLDivElement;
            expect(el.style.background).toBe('rgb(17, 24, 39)');
        });
    });

    describe('spinner 스타일 (@since engine-v1.29.0)', () => {
        /**
         * renderSpinnerOverlay 로직을 테스트 환경에서 재현
         * (TemplateApp.renderSpinnerOverlay의 핵심 로직)
         */
        function renderSpinnerOverlay(
            target: string,
            spinnerConfig?: SpinnerConfig,
            fallbackTarget?: string
        ): { container: HTMLDivElement | null; style: HTMLStyleElement | null; scope: string } {
            // 3단계 fallback chain
            let targetEl = document.getElementById(target);
            let scope = 'target';

            if (!targetEl && fallbackTarget) {
                targetEl = document.getElementById(fallbackTarget);
                scope = 'fallback';
            }
            if (!targetEl) {
                targetEl = document.getElementById('app');
                scope = 'fullpage';
            }
            if (!targetEl) {
                return { container: null, style: null, scope };
            }

            // 기존 오버레이 제거
            hideSpinnerOverlay();

            const isDark = document.documentElement.classList.contains('dark');
            const bgColor = isDark ? 'rgb(17,24,39)' : 'rgb(249,250,251)';
            const spinnerColor = isDark ? '#6b7280' : '#9ca3af';

            // Step 1: CSS ::after 주입
            const cssTargetId = scope === 'fullpage' ? 'app'
                : scope === 'fallback' ? fallbackTarget!
                : target;
            const selector = `#${cssTargetId}`;
            // 회전 keyframes(g7-spin)는 코어 정적 CSS(g7-core.css)에 있다 — 런타임 규칙에 넣지 않는다 (#163)
            setRuntimeStyleRules('g7-skeleton-overlay-style', [
                `${selector}{position:relative;z-index:0;}`,
                `${selector}::after{content:'';position:absolute;inset:0;background:${bgColor};z-index:2147483646;pointer-events:none;}`,
            ]);
            const styleEl = document.getElementById('g7-skeleton-overlay-style') as HTMLElement;

            // Step 2: 스피너 컨테이너 생성
            const container = document.createElement('div');
            container.id = 'g7-skeleton-overlay';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-busy', 'true');

            if (scope === 'fullpage') {
                container.style.cssText = `position:fixed;inset:0;z-index:20;overflow:hidden;pointer-events:none;background:${bgColor};`;
            } else {
                container.style.cssText = `position:absolute;z-index:20;overflow:hidden;pointer-events:none;background:${bgColor};`;
            }

            // 기본 스피너 (커스텀 컴포넌트 없을 때)
            // 모양은 코어 정적 CSS 클래스, 색만 CSS 변수로 (#163 — HTML 문자열 style 속성 금지)
            const fallbackOverlay = document.createElement('div');
            fallbackOverlay.className = 'g7-spinner-overlay';
            const fallbackSpinner = document.createElement('div');
            fallbackSpinner.className = 'g7-spinner';
            fallbackSpinner.style.setProperty('--g7-spinner-color', spinnerColor);
            fallbackOverlay.appendChild(fallbackSpinner);
            container.replaceChildren(fallbackOverlay);

            document.body.appendChild(container);
            return { container, style: styleEl, scope };
        }

        function hideSpinnerOverlay(): void {
            const container = document.getElementById('g7-skeleton-overlay');
            if (container) container.remove();
            removeRuntimeStyleRules('g7-skeleton-overlay-style');
        }

        function createTargetContainer(id: string): HTMLDivElement {
            const container = document.createElement('div');
            container.id = id;
            document.body.appendChild(container);
            return container;
        }

        afterEach(() => {
            hideSpinnerOverlay();
            const app = document.getElementById('app');
            if (app) app.remove();
            const mypage = document.getElementById('mypage_tab_content');
            if (mypage) mypage.remove();
        });

        /** @effects spinner_overlay_container_contract */
        it('spinner 스타일을 정규화해야 한다', () => {
            const config: TransitionOverlayConfig = {
                enabled: true,
                style: 'spinner',
                target: 'main_content_area',
                spinner: { component: 'PageLoading' },
            };
            const normalized = normalizeConfig(config);

            expect(normalized.style).toBe('spinner');
            expect(normalized.target).toBe('main_content_area');
            expect(normalized.spinner).toEqual({ component: 'PageLoading' });
        });

        /** @effects spinner_overlay_container_contract */
        it('target 존재 시 해당 영역에 스피너 컨테이너를 생성해야 한다', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container).not.toBeNull();
            expect(result.container!.id).toBe('g7-skeleton-overlay');
            expect(result.scope).toBe('target');
            expect(document.body.contains(result.container!)).toBe(true);
        });

        /** @effects spinner_overlay_container_contract */
        it('CSS ::after 스타일이 <head>에 주입되어야 한다', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.style).not.toBeNull();
            expect(result.style!.id).toBe('g7-skeleton-overlay-style');
            expect(document.head.contains(result.style!)).toBe(true);
            expect(result.style!.textContent).toContain('#main_content_area::after{');
            expect(result.style!.textContent).not.toContain('@keyframes');
        });

        /** @effects spinner_overlay_container_contract */
        it('기본 스피너 DOM이 생성되어야 한다 (컴포넌트 미지정 시)', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container!.querySelector('.g7-spinner-overlay > .g7-spinner')).not.toBeNull();
            expect(result.container!.innerHTML).not.toContain('border-radius');
        });

        /** @effects spinner_overlay_container_contract */
        it('aria 속성이 올바르게 설정되어야 한다', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container!.getAttribute('role')).toBe('status');
            expect(result.container!.getAttribute('aria-busy')).toBe('true');
        });

        /** @effects spinner_overlay_container_contract */
        it('3단계 fallback chain: target 미존재 시 fallback_target 사용', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('mypage_tab_content', undefined, 'main_content_area');

            expect(result.scope).toBe('fallback');
            expect(result.style!.textContent).toContain('#main_content_area::after{');
        });

        /** @effects spinner_overlay_container_contract */
        it('3단계 fallback chain: target과 fallback 모두 미존재 시 #app 사용', () => {
            createTargetContainer('app');

            const result = renderSpinnerOverlay('nonexistent', undefined, 'also_nonexistent');

            expect(result.scope).toBe('fullpage');
            expect(result.container!.style.position).toBe('fixed');
            expect(result.style!.textContent).toContain('#app::after{');
        });

        /** @effects spinner_overlay_container_contract */
        it('모든 DOM 미존재 시 null 반환', () => {
            const result = renderSpinnerOverlay('nonexistent');

            expect(result.container).toBeNull();
            expect(result.style).toBeNull();
        });

        /** @effects spinner_overlay_container_contract */
        it('다크 모드에서 올바른 배경색을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container!.style.background).toBe('rgb(17, 24, 39)');
            expect(result.style!.textContent).toContain('background:rgb(17,24,39)');
        });

        /** @effects spinner_overlay_container_contract */
        it('다크 모드에서 스피너 색상이 gray-500이어야 한다', () => {
            document.documentElement.classList.add('dark');
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container!.innerHTML).toContain('#6b7280');
        });

        /** @effects spinner_overlay_container_contract */
        it('라이트 모드에서 스피너 색상이 gray-400이어야 한다', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container!.innerHTML).toContain('#9ca3af');
        });

        /** @effects spinner_overlay_container_contract */
        it('hideSpinnerOverlay로 컨테이너와 스타일이 모두 제거되어야 한다', () => {
            createTargetContainer('main_content_area');
            renderSpinnerOverlay('main_content_area');

            expect(document.getElementById('g7-skeleton-overlay')).toBeTruthy();
            expect(document.getElementById('g7-skeleton-overlay-style')).toBeTruthy();

            hideSpinnerOverlay();

            expect(document.getElementById('g7-skeleton-overlay')).toBeNull();
            expect(document.getElementById('g7-skeleton-overlay-style')).toBeNull();
        });

        /** @effects spinner_overlay_container_contract */
        it('중복 호출 시 기존 오버레이를 제거하고 새로 생성해야 한다', () => {
            createTargetContainer('main_content_area');

            renderSpinnerOverlay('main_content_area');
            renderSpinnerOverlay('main_content_area');

            expect(document.querySelectorAll('#g7-skeleton-overlay').length).toBe(1);
            expect(document.querySelectorAll('#g7-skeleton-overlay-style').length).toBe(1);
        });

        /** @effects spinner_overlay_container_contract */
        it('fullpage 모드에서 position:fixed를 사용해야 한다', () => {
            createTargetContainer('app');

            const result = renderSpinnerOverlay('nonexistent');

            expect(result.container!.style.position).toBe('fixed');
            expect(result.container!.style.inset).toBe('0');
        });

        /** @effects spinner_overlay_container_contract */
        it('target 모드에서 position:absolute를 사용해야 한다', () => {
            createTargetContainer('main_content_area');

            const result = renderSpinnerOverlay('main_content_area');

            expect(result.container!.style.position).toBe('absolute');
        });
    });
});
