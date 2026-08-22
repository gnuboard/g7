/**
 * @file board-index-empty-states.test.tsx
 * @description 게시판 목록 - 빈 상태 / 로딩 / 오류 / 글쓰기 버튼 레이아웃 JSON 구조 검증
 *
 * 검증 방식: 레이아웃 JSON 트리 구조 직접 분석 (DOM 렌더링 비의존).
 * 빈 상태/로딩/오류 분기는 JSON 의 if 표현식과 컴포넌트 트리로 충분히 검증 가능.
 * (testId 도입을 회피하고 RTL 권장 패턴인 구조 검증으로 통합 — issue #204 후속 결정)
 */

import { describe, it, expect } from 'vitest';

// 게시판 목록의 빈 상태 / 로딩-오류 partial
import emptyStatesPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/index/_empty_states.json';
import loadingErrorPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/index/_loading_error.json';
import writeButtonPartial from '../../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/board/index/_write_button.json';

/**
 * 컴포넌트 트리에서 if 표현식이 특정 패턴을 포함하는 첫 노드를 찾는다.
 */
function findByIfPattern(node: any, pattern: string): any | null {
    if (!node) return null;
    if (typeof node.if === 'string' && node.if.includes(pattern)) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findByIfPattern(child, pattern);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 컴포넌트 트리에서 text 가 특정 i18n 키를 포함하는 노드를 모두 찾는다.
 */
function findAllByText(node: any, textPattern: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (typeof node.text === 'string' && node.text.includes(textPattern)) results.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findAllByText(child, textPattern));
        }
    }
    return results;
}

/**
 * 컴포넌트 트리에서 name 이 일치하는 첫 노드를 찾는다.
 */
function findByName(node: any, name: string): any | null {
    if (!node) return null;
    if (node.name === name) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findByName(child, name);
            if (found) return found;
        }
    }
    return null;
}

describe('게시판 목록 - 빈 상태 / 로딩 / 오류 (JSON 구조 검증)', () => {
    describe('빈 상태 3종 조건부 렌더링 (_empty_states.json)', () => {
        it('빈 페이지 (page > 1 + total 0건) 분기가 정의되어 있다', () => {
            const node = findByIfPattern(emptyStatesPartial, 'query.page');
            expect(node).not.toBeNull();
            expect(node.if).toContain('Number(query.page) > 1');
            expect(node.if).toContain("posts?.data?.data?.length === 0");
            // "처음으로" 이동 버튼이 함께 존재
            const goFirstBtn = findByName(node, 'Button');
            expect(goFirstBtn).not.toBeNull();
            expect(goFirstBtn.text).toContain('go_to_first_page');
            expect(goFirstBtn.actions[0].handler).toBe('navigate');
            expect(goFirstBtn.actions[0].params.path).toContain('/board/');
        });

        it('게시글 없음 (검색/카테고리 없이 total === 0) 분기가 정의되어 있다', () => {
            const node = findByIfPattern(emptyStatesPartial, '!query.search');
            expect(node).not.toBeNull();
            expect(node.if).toContain('!query.category');
            expect(node.if).toContain('posts?.data?.pagination?.total === 0');
            // "게시글 없음" 안내 텍스트
            const noPostsTexts = findAllByText(node, 'no_posts');
            expect(noPostsTexts.length).toBeGreaterThanOrEqual(1);
        });

        it('검색 결과 없음 (search 또는 category + total === 0) 분기가 정의되어 있다', () => {
            const node = findByIfPattern(emptyStatesPartial, '(query.search || query.category)');
            expect(node).not.toBeNull();
            expect(node.if).toContain('posts?.data?.pagination?.total === 0');
            // "검색 결과 없음" 안내 텍스트 + 검색 초기화 버튼
            const noResultsTexts = findAllByText(node, 'no_search_results');
            expect(noResultsTexts.length).toBeGreaterThanOrEqual(1);
            const clearBtn = findByName(node, 'Button');
            expect(clearBtn).not.toBeNull();
            expect(clearBtn.text).toContain('clear_search');
            expect(clearBtn.actions[0].handler).toBe('navigate');
        });

        it('세 분기는 서로 배타적인 if 조건을 사용한다 (page>1 / 검색없음 / 검색있음)', () => {
            const branches = emptyStatesPartial.children.filter((c: any) => typeof c.if === 'string');
            expect(branches.length).toBe(3);

            const conditions = branches.map((b: any) => b.if);
            // 정확히 하나의 분기가 query.page > 1 을 포함
            expect(conditions.filter((c: string) => c.includes('Number(query.page) > 1')).length).toBe(1);
            // 정확히 하나의 분기가 검색/카테고리 없음
            expect(conditions.filter((c: string) => c.includes('!query.search')).length).toBe(1);
            // 정확히 하나의 분기가 검색/카테고리 있음
            expect(conditions.filter((c: string) => c.includes('(query.search || query.category)')).length).toBe(1);
        });
    });

    describe('로딩 / 오류 표시 조건 (_loading_error.json)', () => {
        it('로딩 분기가 !posts.data.board && !_global.hasError 조건으로 정의된다', () => {
            const loading = findByIfPattern(loadingErrorPartial, '!posts?.data?.board');
            expect(loading).not.toBeNull();
            expect(loading.if).toContain('!_global.hasError');
            // 로딩 분기에 '$t:board.loading_posts' 메시지 노출
            const loadingTexts = findAllByText(loading, 'loading_posts');
            expect(loadingTexts.length).toBeGreaterThanOrEqual(1);
        });

        it('오류 분기가 _global.hasError 조건으로 정의되며 새로고침 버튼을 가진다', () => {
            // 로딩 분기도 !_global.hasError 를 포함하므로, 부정 키워드(!) 없는
            // 오류 전용 분기를 children 에서 직접 식별
            const errorNode = loadingErrorPartial.children.find(
                (c: any) => typeof c.if === 'string'
                    && c.if.includes('_global.hasError')
                    && !c.if.includes('!_global.hasError'),
            );
            expect(errorNode).toBeDefined();
            // 오류 제목 + 설명 i18n 키
            const titleTexts = findAllByText(errorNode, 'error_title');
            expect(titleTexts.length).toBeGreaterThanOrEqual(1);
            const descTexts = findAllByText(errorNode, 'error_description');
            expect(descTexts.length).toBeGreaterThanOrEqual(1);
            // 새로고침 버튼 (refresh handler)
            const reloadBtn = findByName(errorNode, 'Button');
            expect(reloadBtn).not.toBeNull();
            expect(reloadBtn.actions[0].handler).toBe('refresh');
        });

        it('로딩과 오류 분기는 서로 배타적이다 (오류 시 로딩 미표시)', () => {
            const branches = loadingErrorPartial.children.filter((c: any) => typeof c.if === 'string');
            expect(branches.length).toBe(2);
            const loadingBranch = branches.find((b: any) => b.if.includes('!_global.hasError'));
            const errorBranch = branches.find((b: any) =>
                b.if.includes('_global.hasError') && !b.if.includes('!_global.hasError')
            );
            expect(loadingBranch).toBeDefined();
            expect(errorBranch).toBeDefined();
        });
    });

    describe('글쓰기 버튼 권한 분기 (_write_button.json)', () => {
        it('글쓰기 버튼이 정의되어 있고 i18n 텍스트와 아이콘을 포함한다', () => {
            // _write_button.json 의 최상위 또는 children 어딘가에 Button + write 텍스트
            const writeBtn = findByName(writeButtonPartial, 'Button');
            expect(writeBtn).not.toBeNull();
            // pencil 아이콘 또는 write 관련 i18n 텍스트 존재
            const iconNode = findByName(writeBtn, 'Icon');
            expect(iconNode).not.toBeNull();
            expect(typeof iconNode.props?.name).toBe('string');
        });

        it('글쓰기 권한이 없거나 비로그인 시 분기 노출 (if 표현식 존재)', () => {
            // can_write 또는 currentUser 등 권한/인증 분기가 어딘가 있어야 함
            const ifPatterns = JSON.stringify(writeButtonPartial);
            const hasAuthBranch = /(can_write|currentUser|abilities)/.test(ifPatterns);
            expect(hasAuthBranch).toBe(true);
        });
    });
});
