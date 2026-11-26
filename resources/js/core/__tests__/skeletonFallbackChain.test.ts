/**
 * skeleton 3단계 fallback chain 단위 테스트
 *
 * 네비게이션 컨텍스트에 따라 스켈레톤 범위가 달라지는 로직 검증:
 * 1. target DOM 존재     → 해당 영역만 (페이지 내부 전환)
 * 2. fallback_target 존재 → 해당 영역만 (페이지 전환)
 * 3. 둘 다 미존재         → 전체 페이지 (#app fullpage)
 *
 * 종전에는 TemplateApp.renderSkeletonOverlay 의 로직을 이 파일 안에 베낀 함수(대상 결정, CSS 대상 id,
 * 컴포넌트 트리 선택)만 검사해, 원본이 깨져도 통과했다. 지금은 실제 renderSkeletonOverlay 를 부르고, 주입된
 * ::after 규칙과 스켈레톤 컨테이너 위치, 스켈레톤 컴포넌트가 받은 트리를 본다.
 * (매니페스트 core-skeleton-fallback-nu2.yaml)
 *
 * @since engine-v1.24.2
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';

const { skeletonCalls } = vi.hoisted(() => ({
    skeletonCalls: [] as Array<{ components: any[] }>,
}));

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
    /** 받은 트리를 기록하고 최상위 id 를 그리는 스켈레톤 대역 */
    const PageSkeleton = (props: { components: any[] }) => {
        skeletonCalls.push({ components: props.components });
        return React.createElement(
            'div',
            { 'data-testid': 'skeleton' },
            props.components.map((c: any) => c.id).join(','),
        );
    };
    const mockInstance: any = {
        loadComponents: vi.fn().mockResolvedValue(undefined),
        getComponent: vi.fn((name: string) => (name === 'PageSkeleton' ? PageSkeleton : null)),
        hasComponent: vi.fn().mockReturnValue(true),
        getInstance: vi.fn(),
    };
    mockInstance.getInstance.mockReturnValue(mockInstance);
    return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';

/** 마이페이지 레이아웃 모양의 컴포넌트 트리 */
const fullTree = [
    { id: 'header', name: 'Header', children: [] },
    {
        id: 'main_content_area', name: 'Div', children: [
            { id: 'tab_nav', name: 'TabNavigation', children: [] },
            {
                id: 'mypage_tab_content', name: 'Div', children: [
                    { id: 'orders_list', name: 'Div', children: [] },
                ],
            },
        ],
    },
    { id: 'footer', name: 'Footer', children: [] },
];

describe('skeleton 3단계 fallback chain (@since engine-v1.24.2)', () => {
    let app: TemplateApp;
    let appEl: HTMLDivElement;

    /**
     * 실제 TemplateApp.renderSkeletonOverlay 를 부른다.
     *
     * @param target transition_overlay.target
     * @param fallbackTarget transition_overlay.fallback_target
     * @param component 스켈레톤 컴포넌트 이름
     */
    const render = (target: string, fallbackTarget?: string, component = 'PageSkeleton'): void => {
        (app as any).renderSkeletonOverlay(target, { component }, { components: fullTree }, fallbackTarget);
    };

    /** 주입된 ::after 규칙 */
    const skeletonStyle = (): string | null =>
        document.getElementById('g7-skeleton-overlay-style')?.textContent ?? null;

    /** body 직속 스켈레톤 컨테이너 */
    const skeletonContainer = (): HTMLElement | null => document.getElementById('g7-skeleton-overlay');

    /** 스켈레톤 컴포넌트가 마지막으로 받은 트리의 최상위 id */
    const lastTreeIds = (): string[] => skeletonCalls[skeletonCalls.length - 1].components.map((c: any) => c.id);

    const addDiv = (id: string, parent: HTMLElement = document.body): HTMLDivElement => {
        const el = document.createElement('div');
        el.id = id;
        parent.appendChild(el);
        return el;
    };

    beforeEach(() => {
        document.body.innerHTML = '';
        document.head.querySelectorAll('#g7-transition-overlay, #g7-skeleton-overlay-style').forEach(el => el.remove());
        skeletonCalls.length = 0;
        // #app은 항상 존재 (React 렌더 컨테이너)
        appEl = addDiv('app');
        app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko' } as any);
    });

    afterEach(() => {
        (app as any).hideSkeletonOverlay();
        (app as any).hideTransitionOverlay();
        document.head.querySelectorAll('#g7-transition-overlay, #g7-skeleton-overlay-style').forEach(el => el.remove());
        document.body.innerHTML = '';
    });

    describe('대상 결정', () => {
        // @scenario area=scope_target
        // @effects skeleton_scope_resolved
        it('target DOM 존재 시 target 영역만 덮는다', () => {
            addDiv('mypage_tab_content');

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#mypage_tab_content::after{');
            expect(skeletonContainer()!.style.position).toBe('absolute');
        });

        // @scenario area=scope_fallback
        // @effects skeleton_scope_resolved
        it('target 미존재 + fallback_target 존재 시 fallback_target 영역을 덮는다', () => {
            addDiv('main_content_area');

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#main_content_area::after{');
            expect(skeletonContainer()!.style.position).toBe('absolute');
        });

        // @scenario area=scope_fullpage
        // @effects skeleton_scope_resolved
        it('target + fallback_target 모두 미존재 시 #app 전체를 fixed 로 덮는다', () => {
            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#app::after{');
            expect(skeletonContainer()!.style.position).toBe('fixed');
        });

        // @scenario area=scope_fullpage
        // @effects skeleton_scope_resolved
        it('fallback_target 미지정 + target 미존재 시 #app 전체를 덮는다', () => {
            render('mypage_tab_content');

            expect(skeletonStyle()).toContain('#app::after{');
            expect(skeletonContainer()!.style.position).toBe('fixed');
        });

        // @scenario area=no_container
        // @effects skeleton_missing_target_falls_back
        it('#app도 미존재 시 스켈레톤 대신 opaque 덮개로 폴백한다', () => {
            appEl.remove();

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonContainer()).toBeNull();
            expect(skeletonStyle()).toBeNull();
            expect(skeletonCalls).toHaveLength(0);
            expect(document.getElementById('g7-transition-overlay')).not.toBeNull();
        });

        // @scenario area=scope_target
        // @effects skeleton_scope_resolved
        it('fallback_target 없이 target만 존재 시 target 영역을 덮는다', () => {
            addDiv('main_content_area');

            render('main_content_area');

            expect(skeletonStyle()).toContain('#main_content_area::after{');
            expect(skeletonContainer()!.style.position).toBe('absolute');
        });

        // @scenario area=no_container
        // @effects skeleton_missing_target_falls_back
        it('스켈레톤 컴포넌트가 등록되지 않았으면 opaque 덮개로 폴백한다', () => {
            addDiv('mypage_tab_content');

            render('mypage_tab_content', 'main_content_area', 'MissingSkeleton');

            expect(skeletonContainer()).toBeNull();
            expect(skeletonStyle()).toBeNull();
            expect(document.getElementById('g7-transition-overlay')).not.toBeNull();
        });
    });

    describe('CSS 대상 id', () => {
        // @scenario area=css_target
        // @effects skeleton_css_target_matches_scope
        it('target 범위면 ::after 규칙이 target id 하나만 가리킨다', () => {
            addDiv('main_content_area');
            addDiv('mypage_tab_content');

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#mypage_tab_content::after{');
            expect(skeletonStyle()).not.toContain('#main_content_area');
            expect(skeletonStyle()).not.toContain('#app');
        });

        // @scenario area=css_target
        // @effects skeleton_css_target_matches_scope
        it('fallback 범위면 ::after 규칙이 fallback_target id 를 가리킨다', () => {
            addDiv('main_content_area');

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#main_content_area::after{');
            expect(skeletonStyle()).not.toContain('#mypage_tab_content');
        });

        // @scenario area=css_target
        // @effects skeleton_css_target_matches_scope
        it('fullpage 범위면 ::after 규칙이 #app 을 가리킨다', () => {
            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#app::after{');
            expect(skeletonStyle()).not.toContain('#mypage_tab_content');
            expect(skeletonStyle()).not.toContain('#main_content_area');
        });
    });

    describe('스켈레톤 컴포넌트 트리', () => {
        // @scenario area=component_tree
        // @effects skeleton_tree_matches_scope
        it('fullpage 범위면 전체 컴포넌트 트리를 넘긴다', () => {
            render('mypage_tab_content', 'main_content_area');

            expect(skeletonCalls[skeletonCalls.length - 1].components).toBe(fullTree);
            expect(lastTreeIds()).toEqual(['header', 'main_content_area', 'footer']);
        });

        // @scenario area=component_tree
        // @effects skeleton_tree_matches_scope
        it('fallback 범위면 fallback_target 의 자식만 넘긴다', () => {
            addDiv('main_content_area');

            render('mypage_tab_content', 'main_content_area');

            expect(lastTreeIds()).toEqual(['tab_nav', 'mypage_tab_content']);
        });

        // @scenario area=component_tree
        // @effects skeleton_tree_matches_scope
        it('target 범위면 target 의 자식만 넘긴다', () => {
            addDiv('mypage_tab_content');

            render('mypage_tab_content', 'main_content_area');

            expect(lastTreeIds()).toEqual(['orders_list']);
            expect(skeletonContainer()!.textContent).toBe('orders_list');
        });

        // @scenario area=component_tree
        // @effects skeleton_tree_matches_scope
        it('트리에 대상 id 가 없으면 전체 트리로 폴백한다', () => {
            addDiv('detached_area');

            render('detached_area');

            expect(lastTreeIds()).toEqual(['header', 'main_content_area', 'footer']);
        });
    });

    describe('실제 시나리오', () => {
        // @scenario area=real_navigation
        // @effects skeleton_scope_resolved
        it('초기 로드 (직접 URL 접속) — fullpage 스켈레톤', () => {
            // 초기 로드: renderTemplate() 전이므로 target/fallback DOM 미존재
            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#app::after{');
            expect(lastTreeIds()).toEqual(['header', 'main_content_area', 'footer']);
        });

        // @scenario area=real_navigation
        // @effects skeleton_scope_resolved
        it('헤더에서 게시판→마이페이지 전환 — fallback (main_content_area) 스켈레톤', () => {
            // 페이지 전환: main_content_area는 존재하지만 mypage_tab_content는 아직 없음
            addDiv('main_content_area', appEl);

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#main_content_area::after{');
            expect(lastTreeIds()).toEqual(['tab_nav', 'mypage_tab_content']);
        });

        // @scenario area=real_navigation
        // @effects skeleton_scope_resolved
        it('마이페이지 내 탭 전환 (주문→프로필) — target (mypage_tab_content) 스켈레톤', () => {
            // 마이페이지 내부 전환: 둘 다 존재
            const mainEl = addDiv('main_content_area', appEl);
            addDiv('mypage_tab_content', mainEl);

            render('mypage_tab_content', 'main_content_area');

            expect(skeletonStyle()).toContain('#mypage_tab_content::after{');
            expect(lastTreeIds()).toEqual(['orders_list']);
        });

        // @scenario area=real_navigation
        // @effects skeleton_scope_resolved
        it('일반 페이지 (fallback_target 미사용) — target 존재 시 target, 미존재 시 fullpage', () => {
            // _user_base 기본 설정: target="main_content_area", fallback_target 없음
            // 페이지 전환: main_content_area 존재
            const mainEl = addDiv('main_content_area', appEl);

            render('main_content_area');
            expect(skeletonStyle()).toContain('#main_content_area::after{');

            // 초기 로드: main_content_area 미존재
            mainEl.remove();
            render('main_content_area');
            expect(skeletonStyle()).toContain('#app::after{');
            expect(document.querySelectorAll('#g7-skeleton-overlay-style')).toHaveLength(1);
        });
    });
});
