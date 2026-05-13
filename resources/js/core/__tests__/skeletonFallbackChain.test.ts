/**
 * skeleton 3단계 fallback chain 단위 테스트
 *
 * 네비게이션 컨텍스트에 따라 스켈레톤 범위가 달라지는 로직 검증:
 * 1. target DOM 존재     → 해당 영역만 (페이지 내부 전환)
 * 2. fallback_target 존재 → 해당 영역만 (페이지 전환)
 * 3. 둘 다 미존재         → 전체 페이지 (#app fullpage)
 *
 * @since engine-v1.24.2
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';

// --- renderSkeletonOverlay에서 추출한 핵심 로직 ---

type SkeletonScope = 'target' | 'fallback' | 'fullpage';

interface FallbackResult {
    targetEl: HTMLElement | null;
    skeletonScope: SkeletonScope;
}

/**
 * 3단계 fallback chain (TemplateApp.renderSkeletonOverlay 내부 로직)
 *
 * target → fallback_target → #app
 */
function resolveFallbackChain(target: string, fallbackTarget?: string): FallbackResult {
    let targetEl = document.getElementById(target);
    let skeletonScope: SkeletonScope = 'target';

    if (!targetEl && fallbackTarget) {
        targetEl = document.getElementById(fallbackTarget);
        skeletonScope = 'fallback';
    }
    if (!targetEl) {
        targetEl = document.getElementById('app');
        skeletonScope = 'fullpage';
    }

    return { targetEl, skeletonScope };
}

/**
 * CSS ::after 타겟 ID 결정 (TemplateApp.renderSkeletonOverlay 내부 로직)
 */
function getCssTargetId(skeletonScope: SkeletonScope, target: string, fallbackTarget?: string): string {
    if (skeletonScope === 'fullpage') return 'app';
    if (skeletonScope === 'fallback') return fallbackTarget!;
    return target;
}

/**
 * 스켈레톤 컴포넌트 트리 결정 (TemplateApp.renderSkeletonOverlay 내부 로직)
 *
 * fullpage: 전체 컴포넌트 트리
 * fallback/target: findComponentChildrenById로 추출한 하위 트리
 */
function resolveComponentTree(
    skeletonScope: SkeletonScope,
    components: any[],
    target: string,
    fallbackTarget?: string,
    findChildrenById?: (components: any[], id: string) => any[]
): any[] {
    if (skeletonScope === 'fullpage') {
        return components;
    }
    const searchId = skeletonScope === 'fallback' ? fallbackTarget! : target;
    return findChildrenById ? findChildrenById(components, searchId) : [];
}

// --- 테스트 ---

describe('skeleton 3단계 fallback chain (@since engine-v1.24.2)', () => {
    let appEl: HTMLDivElement;

    beforeEach(() => {
        // #app은 항상 존재 (React 렌더 컨테이너)
        appEl = document.createElement('div');
        appEl.id = 'app';
        document.body.appendChild(appEl);
    });

    afterEach(() => {
        // DOM 정리
        ['app', 'main_content_area', 'mypage_tab_content'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.remove();
        });
    });

    describe('resolveFallbackChain', () => {
        it('target DOM 존재 시 scope=target으로 해결해야 한다', () => {
            const targetEl = document.createElement('div');
            targetEl.id = 'mypage_tab_content';
            document.body.appendChild(targetEl);

            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('target');
            expect(result.targetEl).toBe(targetEl);
        });

        it('target 미존재 + fallback_target 존재 시 scope=fallback으로 해결해야 한다', () => {
            const fallbackEl = document.createElement('div');
            fallbackEl.id = 'main_content_area';
            document.body.appendChild(fallbackEl);

            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('fallback');
            expect(result.targetEl).toBe(fallbackEl);
        });

        it('target + fallback_target 모두 미존재 시 scope=fullpage (#app)로 해결해야 한다', () => {
            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('fullpage');
            expect(result.targetEl).toBe(appEl);
        });

        it('fallback_target 미지정 + target 미존재 시 scope=fullpage로 해결해야 한다', () => {
            const result = resolveFallbackChain('mypage_tab_content');

            expect(result.skeletonScope).toBe('fullpage');
            expect(result.targetEl).toBe(appEl);
        });

        it('#app도 미존재 시 targetEl=null을 반환해야 한다', () => {
            appEl.remove();

            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('fullpage');
            expect(result.targetEl).toBeNull();
        });

        it('fallback_target 없이 target만 존재 시 scope=target으로 해결해야 한다', () => {
            const targetEl = document.createElement('div');
            targetEl.id = 'main_content_area';
            document.body.appendChild(targetEl);

            const result = resolveFallbackChain('main_content_area');

            expect(result.skeletonScope).toBe('target');
            expect(result.targetEl).toBe(targetEl);
        });
    });

    describe('getCssTargetId', () => {
        it('scope=target일 때 target ID를 반환해야 한다', () => {
            expect(getCssTargetId('target', 'mypage_tab_content', 'main_content_area'))
                .toBe('mypage_tab_content');
        });

        it('scope=fallback일 때 fallback_target ID를 반환해야 한다', () => {
            expect(getCssTargetId('fallback', 'mypage_tab_content', 'main_content_area'))
                .toBe('main_content_area');
        });

        it('scope=fullpage일 때 "app"을 반환해야 한다', () => {
            expect(getCssTargetId('fullpage', 'mypage_tab_content', 'main_content_area'))
                .toBe('app');
        });
    });

    describe('resolveComponentTree', () => {
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

        function mockFindChildrenById(components: any[], id: string): any[] {
            for (const comp of components) {
                if (comp.id === id) return comp.children || [];
                if (comp.children) {
                    const found = mockFindChildrenById(comp.children, id);
                    if (found.length > 0) return found;
                }
            }
            return [];
        }

        it('scope=fullpage일 때 전체 컴포넌트 트리를 반환해야 한다', () => {
            const result = resolveComponentTree('fullpage', fullTree, 'mypage_tab_content', 'main_content_area', mockFindChildrenById);

            expect(result).toBe(fullTree);
            expect(result).toHaveLength(3); // header + content + footer
        });

        it('scope=fallback일 때 fallback_target의 자식만 반환해야 한다', () => {
            const result = resolveComponentTree('fallback', fullTree, 'mypage_tab_content', 'main_content_area', mockFindChildrenById);

            expect(result).toHaveLength(2); // tab_nav + mypage_tab_content
            expect(result[0].id).toBe('tab_nav');
            expect(result[1].id).toBe('mypage_tab_content');
        });

        it('scope=target일 때 target의 자식만 반환해야 한다', () => {
            const result = resolveComponentTree('target', fullTree, 'mypage_tab_content', 'main_content_area', mockFindChildrenById);

            expect(result).toHaveLength(1); // orders_list only
            expect(result[0].id).toBe('orders_list');
        });
    });

    describe('실제 시나리오', () => {
        it('초기 로드 (직접 URL 접속) — fullpage 스켈레톤', () => {
            // 초기 로드: renderTemplate() 전이므로 target/fallback DOM 미존재
            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('fullpage');
            expect(result.targetEl!.id).toBe('app');
        });

        it('헤더에서 게시판→마이페이지 전환 — fallback (main_content_area) 스켈레톤', () => {
            // 페이지 전환: main_content_area는 존재하지만 mypage_tab_content는 아직 없음
            const mainEl = document.createElement('div');
            mainEl.id = 'main_content_area';
            document.body.appendChild(mainEl);

            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('fallback');
            expect(result.targetEl!.id).toBe('main_content_area');
        });

        it('마이페이지 내 탭 전환 (주문→프로필) — target (mypage_tab_content) 스켈레톤', () => {
            // 마이페이지 내부 전환: 둘 다 존재
            const mainEl = document.createElement('div');
            mainEl.id = 'main_content_area';
            document.body.appendChild(mainEl);

            const tabEl = document.createElement('div');
            tabEl.id = 'mypage_tab_content';
            mainEl.appendChild(tabEl);

            const result = resolveFallbackChain('mypage_tab_content', 'main_content_area');

            expect(result.skeletonScope).toBe('target');
            expect(result.targetEl!.id).toBe('mypage_tab_content');
        });

        it('일반 페이지 (fallback_target 미사용) — target 존재 시 target, 미존재 시 fullpage', () => {
            // _user_base 기본 설정: target="main_content_area", fallback_target 없음
            // 페이지 전환: main_content_area 존재
            const mainEl = document.createElement('div');
            mainEl.id = 'main_content_area';
            document.body.appendChild(mainEl);

            const result1 = resolveFallbackChain('main_content_area');
            expect(result1.skeletonScope).toBe('target');

            // 초기 로드: main_content_area 미존재
            mainEl.remove();
            const result2 = resolveFallbackChain('main_content_area');
            expect(result2.skeletonScope).toBe('fullpage');
        });

        it('마이페이지에서 헤더→게시판 전환 — 게시판의 target (main_content_area) 사용', () => {
            // 마이페이지 DOM이 있더라도, 게시판 레이아웃의 transition_overlay가 적용됨
            // 게시판: target="main_content_area" (fallback_target 없음)
            const mainEl = document.createElement('div');
            mainEl.id = 'main_content_area';
            document.body.appendChild(mainEl);

            const result = resolveFallbackChain('main_content_area');

            expect(result.skeletonScope).toBe('target');
            expect(result.targetEl!.id).toBe('main_content_area');
        });
    });
});
