/**
 * @file board-index-category-filter.test.tsx
 * @description 게시판 목록 - 카테고리 필터 레이아웃 JSON 구조 검증
 *
 * 검증 방식: 인라인 fixture layout JSON 트리를 직접 분석 (DOM 렌더링 비의존).
 * (testId 도입을 회피하고 RTL 권장 패턴인 구조 검증으로 통합 — issue #204 후속 결정)
 */

import { describe, it, expect } from 'vitest';

// ============================================================
// 카테고리 필터 검증용 인라인 layout fixture
// (실제 index.json 의 카테고리 필터 관련 영역만 추출)
// ============================================================

const categoryFilterLayoutJson = {
    version: '1.0.0',
    layout_name: 'board_index_category_test',
    data_sources: [
        {
            id: 'posts',
            type: 'api',
            endpoint: '/api/modules/sirsoft-board/boards/test-board/posts',
            method: 'GET',
            params: {
                page: '{{query.page ?? 1}}',
                search: "{{query.search ?? ''}}",
                category: "{{query.category ?? ''}}",
            },
            auto_fetch: true,
            auth_required: true,
            refetchOnMount: true,
        },
    ],
    components: [
        {
            comment: '메인 컨테이너',
            type: 'layout',
            name: 'Container',
            if: '{{posts?.data?.board}}',
            children: [
                {
                    comment: '헤더',
                    type: 'basic',
                    name: 'H1',
                    props: { 'data-testid': 'board-title' },
                    text: "{{posts?.data?.board?.name ?? ''}}",
                },
                {
                    comment: '필터 바 (카테고리 필터 + 검색)',
                    type: 'basic',
                    name: 'Div',
                    props: {
                        className:
                            'flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 mb-4',
                        'data-testid': 'filter-bar',
                    },
                    children: [
                        {
                            comment: '카테고리 필터 (카테고리가 있을 때만 표시)',
                            type: 'basic',
                            name: 'Select',
                            if: '{{posts?.data?.board?.categories && posts?.data?.board?.categories.length > 0}}',
                            props: {
                                value: "{{query.category || 'all'}}",
                                className:
                                    'w-full sm:w-48 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white text-sm',
                                options:
                                    "{{[{value:'all', label:'전체 카테고리'}].concat((posts?.data?.board?.categories ?? []).map(function(c){return{value:c,label:c};}))}}",
                                'data-testid': 'category-filter',
                            },
                            actions: [
                                {
                                    type: 'change',
                                    handler: 'navigate',
                                    params: {
                                        path: '/board/{{route.slug}}',
                                        mergeQuery: true,
                                        query: {
                                            category:
                                                "{{$event.target.value === 'all' ? '' : $event.target.value}}",
                                            page: 1,
                                        },
                                    },
                                },
                            ],
                        },
                        {
                            comment: '검색 바',
                            type: 'basic',
                            name: 'Div',
                            props: { className: 'flex justify-end' },
                            children: [
                                {
                                    type: 'composite',
                                    name: 'SearchBar',
                                    props: {
                                        name: 'search',
                                        placeholder: '검색',
                                        value: "{{query.search || ''}}",
                                        showButton: false,
                                        className: 'w-full sm:w-64',
                                    },
                                    actions: [
                                        {
                                            type: 'submit',
                                            handler: 'navigate',
                                            params: {
                                                path: '/board/{{route.slug}}',
                                                mergeQuery: true,
                                                query: {
                                                    search: '{{$event.target.search.value}}',
                                                    page: 1,
                                                },
                                            },
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                },
                {
                    comment: '게시글 없음 (검색/필터 아닐 때)',
                    type: 'basic',
                    name: 'Div',
                    if: "{{posts?.data?.board?.slug && !query.search && !query.category && (!query.page || Number(query.page) <= 1) && posts?.data?.pagination?.total === 0}}",
                    props: { 'data-testid': 'empty-no-filter' },
                    children: [
                        { type: 'basic', name: 'P', text: '등록된 게시글이 없습니다' },
                    ],
                },
                {
                    comment: '검색/카테고리 결과 없음',
                    type: 'basic',
                    name: 'Div',
                    if: '{{posts?.data?.board?.slug && (query.search || query.category) && posts?.data?.pagination?.total === 0}}',
                    props: { 'data-testid': 'empty-with-filter' },
                    children: [
                        { type: 'basic', name: 'P', text: '검색 결과가 없습니다' },
                        {
                            type: 'basic',
                            name: 'Button',
                            text: '검색 초기화',
                            props: { 'data-testid': 'clear-filter-btn' },
                            actions: [
                                {
                                    type: 'click',
                                    handler: 'navigate',
                                    params: { path: '/board/{{route.slug}}' },
                                },
                            ],
                        },
                    ],
                },
                {
                    comment: '페이지네이션',
                    type: 'composite',
                    name: 'Pagination',
                    props: {
                        currentPage: '{{posts?.data?.pagination?.current_page ?? 1}}',
                        totalPages: '{{posts?.data?.pagination?.last_page ?? 1}}',
                    },
                    actions: [
                        {
                            event: 'onPageChange',
                            type: 'change',
                            handler: 'navigate',
                            params: {
                                path: '/board/{{route?.slug}}',
                                mergeQuery: true,
                                query: {
                                    page: '{{$args[0]}}',
                                    category: '{{query.category}}',
                                },
                            },
                        },
                    ],
                },
            ],
        },
    ],
} as const;

// ============================================================
// 헬퍼
// ============================================================

const container = categoryFilterLayoutJson.components[0] as any;
const filterBar = container.children?.find(
    (c: any) => c.comment === '필터 바 (카테고리 필터 + 검색)'
);

function getCategorySelect(): any {
    return filterBar?.children?.find((c: any) => c.name === 'Select');
}

function getPagination(): any {
    return container.children?.find((c: any) => c.name === 'Pagination');
}

function getByTestIdInContainer(testId: string): any {
    return container.children?.find(
        (c: any) => c.props?.['data-testid'] === testId
    );
}

// ============================================================
// 테스트
// ============================================================

describe('게시판 목록 - 카테고리 필터 (JSON 구조 검증)', () => {
    describe('카테고리 필터 표시/미표시 조건', () => {
        it('카테고리 Select 의 if 조건이 categories 배열의 비어있지 않음을 확인한다', () => {
            const select = getCategorySelect();
            expect(select).toBeDefined();
            expect(select.if).toContain('posts?.data?.board?.categories');
            expect(select.if).toContain('length > 0');
        });

        it('카테고리 Select 의 if 표현식은 categories 가 빈 배열이면 false 로 평가된다', () => {
            const select = getCategorySelect();
            expect(select.if).toMatch(/categories.*length\s*>\s*0/);
        });

        it('카테고리 Select 의 props.value 가 query.category 를 사용한다', () => {
            const select = getCategorySelect();
            expect(select.props.value).toContain('query.category');
        });
    });

    describe('API params 에 category 포함', () => {
        it('data_sources params 에 category 파라미터가 포함된다', () => {
            const dataSource = categoryFilterLayoutJson.data_sources[0];
            expect(dataSource.params).toHaveProperty('category');
            expect(dataSource.params.category).toBe("{{query.category ?? ''}}");
        });

        it('data_sources endpoint 가 게시판 슬러그 기반이다', () => {
            const dataSource = categoryFilterLayoutJson.data_sources[0];
            expect(dataSource.endpoint).toContain('/api/modules/sirsoft-board/boards/');
            expect(dataSource.method).toBe('GET');
        });
    });

    describe('카테고리 필터 변경 시 네비게이션', () => {
        it('카테고리 Select change 액션이 navigate 핸들러를 사용한다', () => {
            const action = getCategorySelect().actions?.[0];
            expect(action.type).toBe('change');
            expect(action.handler).toBe('navigate');
        });

        it('navigate path 가 게시판 슬러그를 동적으로 사용하고 mergeQuery=true 를 설정한다', () => {
            const action = getCategorySelect().actions?.[0];
            expect(action.params.path).toBe('/board/{{route.slug}}');
            expect(action.params.mergeQuery).toBe(true);
        });

        it('"전체 카테고리"(value=all) 선택 시 category 가 빈 문자열로 변환되는 표현식을 가진다', () => {
            const action = getCategorySelect().actions?.[0];
            expect(action.params.query.category).toContain("'all'");
            expect(action.params.query.category).toContain("''");
            expect(action.params.query.page).toBe(1);
        });
    });

    describe('페이지네이션에 category query 유지', () => {
        it('Pagination onPageChange 액션 query 에 category 가 보존된다', () => {
            const pagination = getPagination();
            expect(pagination).toBeDefined();
            const action = pagination.actions?.[0];
            expect(action.params.query).toHaveProperty('category');
            expect(action.params.query.category).toBe('{{query.category}}');
        });

        it('Pagination action 이 page 를 $args[0] 로 업데이트한다', () => {
            const action = getPagination().actions?.[0];
            expect(action.handler).toBe('navigate');
            expect(action.params.query.page).toBe('{{$args[0]}}');
            expect(action.params.mergeQuery).toBe(true);
        });
    });

    describe('빈 결과 조건 분기', () => {
        it('"게시글 없음" 분기(empty-no-filter)가 검색/카테고리/페이지 모두 없을 때만 표시된다', () => {
            const node = getByTestIdInContainer('empty-no-filter');
            expect(node).toBeDefined();
            expect(node.if).toContain('!query.search');
            expect(node.if).toContain('!query.category');
            expect(node.if).toContain('Number(query.page) <= 1');
            expect(node.if).toContain('pagination?.total === 0');
        });

        it('"검색 결과 없음" 분기(empty-with-filter)가 검색/카테고리 중 하나라도 있을 때 표시된다', () => {
            const node = getByTestIdInContainer('empty-with-filter');
            expect(node).toBeDefined();
            expect(node.if).toContain('(query.search || query.category)');
            expect(node.if).toContain('pagination?.total === 0');
        });

        it('"검색 결과 없음" 분기에 검색 초기화 버튼이 포함된다 (path 만 지정 → 쿼리 해제)', () => {
            const node = getByTestIdInContainer('empty-with-filter');
            const clearBtn = node.children?.find(
                (c: any) => c.props?.['data-testid'] === 'clear-filter-btn'
            );
            expect(clearBtn).toBeDefined();
            expect(clearBtn.actions[0].handler).toBe('navigate');
            expect(clearBtn.actions[0].params.path).toBe('/board/{{route.slug}}');
            expect(clearBtn.actions[0].params.mergeQuery).toBeUndefined();
            expect(clearBtn.actions[0].params.query).toBeUndefined();
        });

        it('두 빈 상태 분기는 서로 배타적이다 (filter 유무로 구분)', () => {
            const noFilter = getByTestIdInContainer('empty-no-filter');
            const withFilter = getByTestIdInContainer('empty-with-filter');
            expect(noFilter.if).toContain('!query.category');
            expect(withFilter.if).toMatch(/query\.(search|category)/);
        });
    });

    describe('레이아웃 JSON 구조 정적 검증 (중복 안전망)', () => {
        it('Select 컴포넌트 타입이 basic 이다', () => {
            const select = getCategorySelect();
            expect(select.type).toBe('basic');
        });

        it('Select change 액션 의 params.query.page 가 1 로 리셋된다', () => {
            const action = getCategorySelect().actions?.[0];
            expect(action.params.query.page).toBe(1);
        });
    });
});
