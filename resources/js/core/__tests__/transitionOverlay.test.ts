/**
 * transition_overlay 레이아웃 옵션 단위 테스트
 *
 * 페이지 전환 시 오버레이를 표시하여
 * React 비동기 렌더링 중 stale DOM flash를 방지하는 기능 검증
 *
 * - target 지정 시: CSS <style> 주입 + ::after 의사 요소 (React 렌더 트리 외부)
 * - target 미지정 시: DOM 오버레이 (document.body에 fixed)
 * - spinner 스타일: 대상 안에 빈 컨테이너(#g7-skeleton-overlay)를 넣고 기본 스피너를 그린다
 *
 * #869: 종전에는 TemplateApp 의 덮개 로직을 이 파일 안에 베껴 두고 그 사본만 검사했다. 원본이 깨져도 통과했고,
 * 스피너 사본은 원본과 이미 달랐다(원본은 ::after 배경과 position:fixed 를 쓰지 않고, 색은 CSS 변수로 받는다).
 * 지금은 실제 TemplateApp 의 showTransitionOverlay 와 hideTransitionOverlay 를 부른다.
 * (매니페스트 core-transition-overlay-nu2.yaml)
 *
 * @since engine-v1.23.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../template-engine', () => ({
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    updateTemplateData: vi.fn(),
    getActionDispatcher: vi.fn().mockReturnValue(null),
    getState: vi.fn().mockReturnValue({ currentLayoutJson: null, currentDataContext: {} }),
}));

vi.mock('../routing/Router', () => ({
    Router: vi.fn(function (this: any) {
        this.on = vi.fn();
    }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
    const mockInstance: any = {
        loadComponents: vi.fn().mockResolvedValue(undefined),
        getComponent: vi.fn(() => null),
        hasComponent: vi.fn().mockReturnValue(true),
        getInstance: vi.fn(),
    };
    mockInstance.getInstance.mockReturnValue(mockInstance);
    return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';

type OverlayConfig = boolean | {
    enabled: boolean;
    style?: string;
    target?: string;
    fallback_target?: string;
    skeleton?: { component: string; animation?: string; iteration_count?: number };
    spinner?: { component?: string; text?: string };
};

describe('transition_overlay', () => {
    let app: TemplateApp;

    /** 실제 TemplateApp.showTransitionOverlay 를 부르고 만들어진 덮개 요소를 돌려준다 */
    const show = (config: OverlayConfig, layoutData?: unknown): HTMLElement | null => {
        (app as any).showTransitionOverlay(config, layoutData);
        return document.getElementById('g7-transition-overlay');
    };

    /** 실제 TemplateApp.hideTransitionOverlay */
    const hide = (): void => {
        (app as any).hideTransitionOverlay();
    };

    const createTargetContainer = (id: string, parent: HTMLElement = document.body): HTMLDivElement => {
        const container = document.createElement('div');
        container.id = id;
        parent.appendChild(container);
        return container;
    };

    beforeEach(() => {
        document.body.innerHTML = '';
        document.head.querySelectorAll('#g7-transition-overlay, #g7-skeleton-overlay-style').forEach(el => el.remove());
        document.documentElement.classList.remove('dark');
        app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko' } as any);
    });

    afterEach(() => {
        hide();
        document.documentElement.classList.remove('dark');
        document.body.innerHTML = '';
    });

    describe('설정 해석', () => {
        // @scenario area=config
        // @effects overlay_config_normalized
        it('true 는 opaque 전체 화면 덮개로 그린다', () => {
            const el = show(true);
            expect(el?.tagName).toBe('DIV');
            expect(el?.style.background).toBe('rgb(249, 250, 251)');
        });

        // @scenario area=config
        // @effects overlay_config_normalized
        it('false 는 덮개를 만들지 않는다', () => {
            expect(show(false)).toBeNull();
        });

        // @scenario area=config
        // @effects overlay_config_normalized
        it('객체 설정의 style 을 그대로 쓴다', () => {
            const el = show({ enabled: true, style: 'blur' });
            expect(el?.style.background).toBe('rgba(255, 255, 255, 0.3)');
        });

        // @scenario area=config
        // @effects overlay_config_normalized
        it('style 미지정 시 opaque를 기본값으로 사용해야 한다', () => {
            const el = show({ enabled: true });
            expect(el?.style.background).toBe('rgb(249, 250, 251)');
        });

        // @scenario area=config
        // @effects overlay_config_normalized
        it('target 을 덮개 CSS 의 선택자로 쓴다', () => {
            createTargetContainer('my_container');
            const el = show({ enabled: true, target: 'my_container' });
            expect(el?.textContent).toContain('#my_container::after{');
        });
    });

    describe('target 미지정 — DOM 오버레이 (폴백)', () => {
        // @scenario area=dom_overlay
        // @effects overlay_dom_fallback_rendered
        it('transition_overlay: true 시 body에 fixed 오버레이를 생성해야 한다', () => {
            const el = show(true);

            expect(el).not.toBeNull();
            expect(el!.id).toBe('g7-transition-overlay');
            expect(el!.parentElement).toBe(document.body);
            expect(el!.getAttribute('aria-hidden')).toBe('true');
        });

        // @scenario area=dom_overlay
        // @effects overlay_dom_fallback_rendered
        it('transition_overlay: false 시 오버레이를 생성하지 않아야 한다', () => {
            show(false);
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
        });

        // @scenario area=dom_overlay
        // @effects overlay_dom_fallback_rendered
        it('공통 스타일(fixed, inset, z-index, pointer-events)이 적용되어야 한다', () => {
            const el = show(true)!;

            expect(el.style.position).toBe('fixed');
            expect(el.style.inset).toBe('0');
            expect(el.style.zIndex).toBe('9999');
            expect(el.style.pointerEvents).toBe('none');
        });

        // @scenario area=dom_overlay
        // @effects overlay_style_colors
        it('opaque 스타일 시 gray-50 배경을 사용해야 한다', () => {
            const el = show({ enabled: true, style: 'opaque' })!;
            expect(el.style.background).toBe('rgb(249, 250, 251)');
        });

        // @scenario area=dom_overlay
        // @effects overlay_style_colors
        it('blur 스타일 시 반투명 배경이 backdrop-filter 와 함께 보존되어야 한다', () => {
            // jsdom 은 backdrop-filter 를 모른다. 미지원 선언이 섞여도 배경이 지워지지 않는지가 회귀선이다.
            const el = show({ enabled: true, style: 'blur' })!;
            expect(el.style.background).toBe('rgba(255, 255, 255, 0.3)');
        });

        // @scenario area=dom_overlay
        // @effects overlay_style_colors
        it('fade 스타일 시 반투명 배경을 적용해야 한다', () => {
            const el = show({ enabled: true, style: 'fade' })!;
            expect(el.style.background).toBe('rgba(255, 255, 255, 0.8)');
        });
    });

    describe('target 지정 — CSS <style> 주입', () => {
        // @scenario area=target_css
        // @effects overlay_target_css_injected
        it('target 지정 시 <style> 태그를 <head>에 삽입해야 한다', () => {
            createTargetContainer('main_content_area');

            const el = show({ enabled: true, target: 'main_content_area' });

            expect(el).not.toBeNull();
            expect(el!.tagName).toBe('STYLE');
            expect(el!.id).toBe('g7-transition-overlay');
            expect(document.head.contains(el!)).toBe(true);
        });

        // @scenario area=target_css
        // @effects overlay_target_css_injected
        it('CSS에 타겟 selector와 ::after 규칙이 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const css = show({ enabled: true, target: 'main_content_area' })!.textContent!;

            expect(css).toContain('#main_content_area{position:relative;z-index:0;}');
            expect(css).toContain('#main_content_area::after{');
            expect(css).toContain('position:absolute;');
            expect(css).toContain('inset:0;');
            expect(css).toContain('pointer-events:none;');
        });

        // @scenario area=target_css
        // @effects overlay_style_colors
        it('opaque 스타일 시 gray-50 배경 CSS가 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const el = show({ enabled: true, target: 'main_content_area' });
            expect(el!.textContent).toContain('background:rgb(249,250,251)');
        });

        // @scenario area=target_css
        // @effects overlay_style_colors
        it('blur 스타일 시 backdrop-filter CSS가 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const css = show({ enabled: true, style: 'blur', target: 'main_content_area' })!.textContent!;
            expect(css).toContain('background:rgba(255,255,255,0.3)');
            expect(css).toContain('backdrop-filter:blur(4px)');
            expect(css).toContain('-webkit-backdrop-filter:blur(4px)');
        });

        // @scenario area=target_css
        // @effects overlay_style_colors
        it('fade 스타일 시 반투명 배경 CSS가 포함되어야 한다', () => {
            createTargetContainer('main_content_area');

            const el = show({ enabled: true, style: 'fade', target: 'main_content_area' });
            expect(el!.textContent).toContain('background:rgba(255,255,255,0.8)');
        });

        // @scenario area=target_css
        // @effects overlay_removed_cleanly
        it('제거 시 <style> 태그가 <head>에서 삭제되어야 한다', () => {
            createTargetContainer('main_content_area');
            show({ enabled: true, target: 'main_content_area' });
            expect(document.head.querySelector('#g7-transition-overlay')).toBeTruthy();

            hide();

            expect(document.head.querySelector('#g7-transition-overlay')).toBeNull();
        });
    });

    describe('다크 모드', () => {
        // @scenario area=dark_mode
        // @effects overlay_style_colors
        it('다크 모드에서 opaque DOM 오버레이 시 gray-900 배경을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            const el = show(true)!;
            expect(el.style.background).toBe('rgb(17, 24, 39)');
        });

        // @scenario area=dark_mode
        // @effects overlay_style_colors
        it('다크 모드에서 target CSS 주입 시 gray-900 배경이 포함되어야 한다', () => {
            document.documentElement.classList.add('dark');
            createTargetContainer('main_content_area');

            const el = show({ enabled: true, target: 'main_content_area' });
            expect(el!.textContent).toContain('background:rgb(17,24,39)');
        });

        // @scenario area=dark_mode
        // @effects overlay_style_colors
        it('다크 모드에서 blur 스타일 시 dark 반투명 배경을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            const el = show({ enabled: true, style: 'blur' })!;
            expect(el.style.background).toBe('rgba(17, 24, 39, 0.3)');
        });
    });

    describe('라우트 변경 시나리오', () => {
        // @scenario area=lifecycle
        // @effects overlay_removed_cleanly
        it('오버레이 표시 후 제거가 정상 동작해야 한다', () => {
            show(true);
            expect(document.getElementById('g7-transition-overlay')).toBeTruthy();

            hide();
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
        });

        // @scenario area=lifecycle
        // @effects overlay_removed_cleanly
        it('target CSS 주입 후 제거가 정상 동작해야 한다', () => {
            createTargetContainer('content');

            show({ enabled: true, target: 'content' });
            expect(document.getElementById('g7-transition-overlay')).toBeTruthy();

            hide();
            expect(document.getElementById('g7-transition-overlay')).toBeNull();
        });

        // @scenario area=lifecycle
        // @effects overlay_not_duplicated
        it('중복 호출 시 기존 오버레이를 제거하고 새로 생성해야 한다', () => {
            show(true);
            show(true);

            expect(document.querySelectorAll('#g7-transition-overlay').length).toBe(1);
        });

        // @scenario area=lifecycle
        // @effects overlay_removed_cleanly
        it('hideTransitionOverlay 중복 호출 시 에러 없이 처리해야 한다', () => {
            show(true);
            expect(() => {
                hide();
                hide();
            }).not.toThrow();
        });
    });

    describe('skeleton 스타일 (@since engine-v1.24.0)', () => {
        // @scenario area=skeleton
        // @effects overlay_skeleton_fallback
        it('skeleton 설정이 있어도 레이아웃 데이터가 없으면 target opaque CSS 로 폴백한다', () => {
            createTargetContainer('main_content_area');

            const el = show({
                enabled: true,
                style: 'skeleton',
                target: 'main_content_area',
                skeleton: { component: 'PageSkeleton', animation: 'pulse', iteration_count: 5 },
            });

            expect(el?.tagName).toBe('STYLE');
            expect(el?.textContent).toContain('#main_content_area::after{');
            expect(el?.textContent).toContain('background:rgb(249,250,251)');
            expect(document.getElementById('g7-skeleton-overlay')).toBeNull();
        });

        // @scenario area=skeleton
        // @effects overlay_skeleton_fallback
        it('skeleton 설정 없이 skeleton 스타일 시 opaque 폴백 CSS를 생성해야 한다', () => {
            const el = show({ enabled: true, style: 'skeleton' })!;
            expect(el).not.toBeNull();
            expect(el.style.background).toBe('rgb(249, 250, 251)');
        });

        // @scenario area=skeleton
        // @effects overlay_skeleton_fallback
        it('skeleton 컴포넌트가 있어도 target 이 없으면 전체 화면 opaque 덮개로 폴백한다', () => {
            const el = show({ enabled: true, style: 'skeleton', skeleton: { component: 'PageSkeleton' } }, { components: [] });

            expect(el?.tagName).toBe('DIV');
            expect(el?.style.background).toBe('rgb(249, 250, 251)');
        });

        // @scenario area=skeleton
        // @effects overlay_skeleton_fallback
        it('skeleton 스타일 다크 모드에서 opaque 폴백 시 gray-900 배경을 사용해야 한다', () => {
            document.documentElement.classList.add('dark');
            const el = show({ enabled: true, style: 'skeleton' })!;
            expect(el.style.background).toBe('rgb(17, 24, 39)');
        });
    });

    describe('spinner 스타일 (@since engine-v1.29.0)', () => {
        const spinner = (target: string, fallbackTarget?: string): void => {
            show({ enabled: true, style: 'spinner', target, fallback_target: fallbackTarget });
        };
        const container = (): HTMLElement | null => document.getElementById('g7-skeleton-overlay');
        const spinnerStyle = (): HTMLElement | null => document.getElementById('g7-skeleton-overlay-style');

        // @scenario area=spinner
        // @effects spinner_overlay_mounted_in_target
        it('spinner 스타일과 target 이 있으면 일반 덮개 대신 스피너를 그린다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(container()).not.toBeNull();
            expect(document.querySelector('div#g7-transition-overlay')).toBeNull();
        });

        // @scenario area=spinner
        // @effects spinner_overlay_mounted_in_target
        it('target 존재 시 해당 영역 안에 스피너 컨테이너를 생성해야 한다', () => {
            const target = createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(container()!.parentElement).toBe(target);
        });

        // @scenario area=spinner
        // @effects spinner_overlay_mounted_in_target
        it('대상에 position:relative 와 회전 keyframes 를 <head>에 주입해야 한다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(document.head.contains(spinnerStyle())).toBe(true);
            expect(spinnerStyle()!.textContent).toContain('#main_content_area{position:relative;}');
            expect(spinnerStyle()!.textContent).toContain('@keyframes g7-spin');
        });

        // @scenario area=spinner
        // @effects spinner_default_rendered
        it('기본 스피너 DOM이 생성되어야 한다 (컴포넌트 미지정 시)', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(container()!.innerHTML).toContain('g7-spin');
            expect(container()!.innerHTML).toContain('border-radius:50%');
        });

        // @scenario area=spinner
        // @effects spinner_default_rendered
        it('aria 속성이 올바르게 설정되어야 한다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(container()!.getAttribute('role')).toBe('status');
            expect(container()!.getAttribute('aria-busy')).toBe('true');
        });

        // @scenario area=spinner_fallback
        // @effects spinner_target_fallback_chain
        it('3단계 fallback chain: target 미존재 시 fallback_target 사용', () => {
            const fallback = createTargetContainer('main_content_area');

            spinner('mypage_tab_content', 'main_content_area');

            expect(container()!.parentElement).toBe(fallback);
            expect(spinnerStyle()!.textContent).toContain('#main_content_area{position:relative;}');
        });

        // @scenario area=spinner_fallback
        // @effects spinner_target_fallback_chain
        it('3단계 fallback chain: target과 fallback 모두 미존재 시 #app 사용', () => {
            const appEl = createTargetContainer('app');

            spinner('nonexistent', 'also_nonexistent');

            expect(container()!.parentElement).toBe(appEl);
            expect(spinnerStyle()!.textContent).toContain('#app{position:relative;}');
        });

        // @scenario area=spinner_fallback
        // @effects spinner_target_fallback_chain
        it('모든 대상 DOM 이 없으면 스피너 대신 target 기준 opaque 덮개 CSS 로 폴백한다', () => {
            spinner('nonexistent');

            expect(container()).toBeNull();
            expect(spinnerStyle()).toBeNull();
            const overlay = document.getElementById('g7-transition-overlay');
            expect(overlay?.tagName).toBe('STYLE');
            expect(overlay?.textContent).toContain('#nonexistent::after{');
        });

        // @scenario area=spinner
        // @effects spinner_default_rendered
        it('기본 스피너 배경은 CSS 변수 --g7-overlay-bg 로 받고 밝은 회색이 기본값이다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(container()!.innerHTML).toContain('background:var(--g7-overlay-bg, rgb(249,250,251))');
        });

        // @scenario area=spinner
        // @effects spinner_default_rendered
        it('기본 스피너 선 색은 CSS 변수 --g7-spinner-color 로 받고 gray-400 이 기본값이다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            expect(container()!.innerHTML).toContain('var(--g7-spinner-color, #9ca3af)');
        });

        // 정의하는 곳이 없는 CSS 변수에만 맡겨, 다크 모드에서도 밝은 회색 덮개가 그려졌다(#869).
        // 다른 덮개(opaque, blur, target CSS)와 같이 다크 모드 기본값은 gray-900 이다. 변수가 있으면 변수가 이긴다.
        // @scenario area=dark_mode
        // @effects overlay_style_colors
        it('다크 모드의 기본 스피너 배경 기본값은 다른 덮개와 같은 gray-900 이다', () => {
            createTargetContainer('main_content_area');
            document.documentElement.classList.add('dark');

            spinner('main_content_area');

            expect(container()!.innerHTML).toContain('background:var(--g7-overlay-bg, rgb(17,24,39))');
        });

        // @scenario area=spinner
        // @effects overlay_removed_cleanly
        it('hideTransitionOverlay 로 스피너 컨테이너와 스타일이 모두 제거되어야 한다', () => {
            createTargetContainer('main_content_area');
            spinner('main_content_area');

            expect(container()).toBeTruthy();
            expect(spinnerStyle()).toBeTruthy();

            hide();

            expect(container()).toBeNull();
            expect(spinnerStyle()).toBeNull();
        });

        // @scenario area=spinner
        // @effects overlay_not_duplicated
        it('중복 호출 시 기존 오버레이를 제거하고 새로 생성해야 한다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');
            spinner('main_content_area');

            expect(document.querySelectorAll('#g7-skeleton-overlay').length).toBe(1);
            expect(document.querySelectorAll('#g7-skeleton-overlay-style').length).toBe(1);
        });

        // @scenario area=spinner_fallback
        // @effects spinner_target_fallback_chain
        it('fullpage(#app) 모드에서도 컨테이너는 #app 안에 들어간다', () => {
            const appEl = createTargetContainer('app');

            spinner('nonexistent');

            expect(appEl.contains(container())).toBe(true);
        });

        // @scenario area=spinner
        // @effects spinner_overlay_mounted_in_target
        it('기본 스피너 덮개는 대상 안에서 position:absolute 로 대상 전체를 덮는다', () => {
            createTargetContainer('main_content_area');

            spinner('main_content_area');

            const cover = container()!.firstElementChild as HTMLElement;
            expect(cover.style.position).toBe('absolute');
            expect(cover.style.inset).toBe('0');
        });
    });
});
