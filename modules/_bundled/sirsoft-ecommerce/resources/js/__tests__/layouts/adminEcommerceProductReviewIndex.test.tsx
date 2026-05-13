/**
 * 관리자 리뷰 관리 목록 레이아웃 구조 검증 테스트
 *
 * @description
 * - admin_ecommerce_product_review_index.json JSON 구조 검증 (렌더링 불필요)
 * - 권한(permissions), extends, data_sources 구조 검증
 * - state 초기값 검증
 * - slots.content 내 페이지 헤더, 필터, 일괄처리, DataGrid, Pagination 구조 검증
 * - 다국어 키 네임스페이스 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import reviewIndex from '../../../layouts/admin/admin_ecommerce_product_review_index.json';

/**
 * 재귀적으로 컴포넌트 트리에서 id로 검색
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 재귀적으로 컴포넌트 트리에서 name으로 모든 항목 검색
 */
function findAllByName(node: any, name: string): any[] {
    const results: any[] = [];
    if (!node) return results;
    if (node.name === name) results.push(node);
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            results.push(...findAllByName(child, name));
        }
    }
    if (node.itemTemplate) {
        results.push(...findAllByName(node.itemTemplate, name));
    }
    return results;
}

/**
 * 재귀적으로 $t: 다국어 키 수집 (파이프 이후 파라미터 제거)
 */
function collectI18nKeys(node: any): string[] {
    const keys: string[] = [];
    if (!node) return keys;

    if (typeof node.text === 'string' && node.text.startsWith('$t:')) {
        keys.push(node.text.replace('$t:', '').split('|')[0]);
    }
    if (node.props) {
        for (const val of Object.values(node.props)) {
            if (typeof val === 'string' && val.startsWith('$t:')) {
                keys.push(val.replace('$t:', '').split('|')[0]);
            }
            if (Array.isArray(val)) {
                for (const opt of val as any[]) {
                    if (opt && typeof opt.label === 'string' && opt.label.startsWith('$t:')) {
                        keys.push(opt.label.replace('$t:', '').split('|')[0]);
                    }
                    if (opt && typeof opt.header === 'string' && opt.header.startsWith('$t:')) {
                        keys.push(opt.header.replace('$t:', '').split('|')[0]);
                    }
                }
            }
        }
    }
    if (typeof node.emptyMessage === 'string' && node.emptyMessage.startsWith('$t:')) {
        keys.push(node.emptyMessage.replace('$t:', '').split('|')[0]);
    }

    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            keys.push(...collectI18nKeys(child));
        }
    }
    if (node.itemTemplate) {
        keys.push(...collectI18nKeys(node.itemTemplate));
    }
    return keys;
}

const layout = reviewIndex as any;
// slots.content 순회용 가상 루트 노드
const contentRoot = { children: layout.slots?.content ?? [] };

// ─── 기본 레이아웃 속성 검증 ───

describe('관리자 리뷰 관리 목록 레이아웃 기본 속성 (admin_ecommerce_product_review_index.json)', () => {
    describe('권한 및 상속 검증', () => {
        it('permissions[0]이 sirsoft-ecommerce.reviews.read여야 한다', () => {
            expect(layout.permissions[0]).toBe('sirsoft-ecommerce.reviews.read');
        });

        it('extends가 _admin_base여야 한다', () => {
            expect(layout.extends).toBe('_admin_base');
        });
    });

    describe('data_sources 검증', () => {
        const ds = layout.data_sources[0];

        it('data_sources[0].id가 reviews여야 한다', () => {
            expect(ds.id).toBe('reviews');
        });

        it('endpoint가 /admin/reviews를 포함해야 한다', () => {
            expect(ds.endpoint).toContain('/admin/reviews');
        });

        it('method가 GET이어야 한다', () => {
            expect(ds.method).toBe('GET');
        });

        it('auto_fetch가 true여야 한다', () => {
            expect(ds.auto_fetch).toBe(true);
        });

        it('params에 rating이 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('rating');
        });

        it('params에 reply_status가 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('reply_status');
        });

        it('params에 photo가 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('photo');
        });

        it('params에 status가 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('status');
        });

        it('params에 start_date가 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('start_date');
        });

        it('params에 end_date가 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('end_date');
        });

        it('params에 page, per_page, sort, search_field, search_keyword가 포함되어야 한다', () => {
            expect(ds.params).toHaveProperty('page');
            expect(ds.params).toHaveProperty('per_page');
            expect(ds.params).toHaveProperty('sort');
            expect(ds.params).toHaveProperty('search_field');
            expect(ds.params).toHaveProperty('search_keyword');
        });
    });

    describe('state 초기값 검증', () => {
        const state = layout.state;

        it('selectedReviews 초기값이 빈 배열이어야 한다', () => {
            expect(state.selectedReviews).toEqual([]);
        });

        it('expandedRows 초기값이 빈 배열이어야 한다', () => {
            expect(state.expandedRows).toEqual([]);
        });

        it('batchAction 초기값이 빈 문자열이어야 한다', () => {
            expect(state.batchAction).toBe('');
        });
    });
});

// ─── slots.content 구조 검증 ───

describe('slots.content 구조 검증', () => {
    it('slots.content가 배열로 존재해야 한다', () => {
        expect(Array.isArray(layout.slots?.content)).toBe(true);
    });

    describe('페이지 헤더 검증', () => {
        it('page_header 요소가 존재해야 한다', () => {
            const header = findById(contentRoot, 'page_header');
            expect(header).not.toBeNull();
            expect(header.id).toBe('page_header');
        });

        it('페이지 헤더에 H1 제목 요소가 있어야 한다', () => {
            const header = findById(contentRoot, 'page_header');
            const h1s = findAllByName(header, 'H1');
            expect(h1s.length).toBeGreaterThan(0);
        });

        it('페이지 헤더 H1이 product_review.title 다국어 키를 사용해야 한다', () => {
            const header = findById(contentRoot, 'page_header');
            const h1s = findAllByName(header, 'H1');
            const titleH1 = h1s.find(
                (h: any) => typeof h.text === 'string' && h.text.includes('product_review.title'),
            );
            expect(titleH1).toBeDefined();
        });

        it('페이지 헤더에 P 설명 요소가 있어야 한다', () => {
            const header = findById(contentRoot, 'page_header');
            const ps = findAllByName(header, 'P');
            expect(ps.length).toBeGreaterThan(0);
        });
    });

    describe('검색 필터 영역 검증', () => {
        it('filter_card 요소가 존재해야 한다', () => {
            const filterCard = findById(contentRoot, 'filter_card');
            expect(filterCard).not.toBeNull();
        });

        it('search_row 내 검색 필드 Select가 존재해야 한다', () => {
            const searchRow = findById(contentRoot, 'search_row');
            expect(searchRow).not.toBeNull();
            const selects = findAllByName(searchRow, 'Select');
            expect(selects.length).toBeGreaterThan(0);
        });

        it('검색 필드 Select의 value가 searchField를 참조해야 한다', () => {
            const searchRow = findById(contentRoot, 'search_row');
            const selects = findAllByName(searchRow, 'Select');
            const searchFieldSelect = selects.find(
                (s: any) =>
                    typeof s.props?.value === 'string' &&
                    s.props.value.includes('searchField'),
            );
            expect(searchFieldSelect).toBeDefined();
        });

        it('검색어 Input이 searchKeyword를 참조해야 한다', () => {
            const searchRow = findById(contentRoot, 'search_row');
            const inputs = findAllByName(searchRow, 'Input');
            const keywordInput = inputs.find(
                (i: any) =>
                    typeof i.props?.value === 'string' &&
                    i.props.value.includes('searchKeyword'),
            );
            expect(keywordInput).toBeDefined();
        });

        it('별점 필터 행(filter_rating_row)이 존재해야 한다', () => {
            const ratingRow = findById(contentRoot, 'filter_rating_row');
            expect(ratingRow).not.toBeNull();
        });

        it('별점 필터에 1점~5점 및 전체 버튼이 있어야 한다 (최소 6개)', () => {
            const ratingRow = findById(contentRoot, 'filter_rating_row');
            const buttons = findAllByName(ratingRow, 'Button');
            expect(buttons.length).toBeGreaterThanOrEqual(6);
        });

        it('별점 필터 버튼들이 rating 쿼리 파라미터를 업데이트해야 한다', () => {
            const ratingRow = findById(contentRoot, 'filter_rating_row');
            const buttons = findAllByName(ratingRow, 'Button');
            const has5star = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.rating === '5'),
            );
            expect(has5star).toBe(true);
        });

        it('답변 상태 필터 행(filter_reply_status_row)이 존재해야 한다', () => {
            const replyStatusRow = findById(contentRoot, 'filter_reply_status_row');
            expect(replyStatusRow).not.toBeNull();
        });

        it('답변 상태 필터에 replied/not_replied 버튼이 있어야 한다', () => {
            const replyStatusRow = findById(contentRoot, 'filter_reply_status_row');
            const buttons = findAllByName(replyStatusRow, 'Button');
            const hasReplied = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.reply_status === 'replied'),
            );
            const hasNotReplied = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.reply_status === 'not_replied'),
            );
            expect(hasReplied).toBe(true);
            expect(hasNotReplied).toBe(true);
        });

        it('포토리뷰 필터 행(filter_photo_row)이 존재해야 한다', () => {
            const photoRow = findById(contentRoot, 'filter_photo_row');
            expect(photoRow).not.toBeNull();
        });

        it('포토리뷰 필터에 photo/normal 버튼이 있어야 한다', () => {
            const photoRow = findById(contentRoot, 'filter_photo_row');
            const buttons = findAllByName(photoRow, 'Button');
            const hasPhoto = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.photo === 'photo'),
            );
            const hasNormal = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.photo === 'normal'),
            );
            expect(hasPhoto).toBe(true);
            expect(hasNormal).toBe(true);
        });

        it('리뷰 상태 필터 행(filter_review_status_row)이 존재해야 한다', () => {
            const statusRow = findById(contentRoot, 'filter_review_status_row');
            expect(statusRow).not.toBeNull();
        });

        it('리뷰 상태 필터에 visible/hidden 버튼이 있어야 한다', () => {
            const statusRow = findById(contentRoot, 'filter_review_status_row');
            const buttons = findAllByName(statusRow, 'Button');
            const hasVisible = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.status === 'visible'),
            );
            const hasHidden = buttons.some((b: any) =>
                b.actions?.some((a: any) => a.params?.query?.status === 'hidden'),
            );
            expect(hasVisible).toBe(true);
            expect(hasHidden).toBe(true);
        });

        it('기간 필터 행(filter_date_row)이 존재해야 한다', () => {
            const dateRow = findById(contentRoot, 'filter_date_row');
            expect(dateRow).not.toBeNull();
        });

        it('기간 필터에 날짜 Input(type=date)이 2개 있어야 한다', () => {
            const dateRow = findById(contentRoot, 'filter_date_row');
            const dateInputs = findAllByName(dateRow, 'Input').filter(
                (i: any) => i.props?.type === 'date',
            );
            expect(dateInputs).toHaveLength(2);
        });

        it('기간 필터에 빠른 날짜 선택(오늘/3일/1주일 등) 버튼이 있어야 한다', () => {
            const dateRow = findById(contentRoot, 'filter_date_row');
            // 빠른 날짜 버튼은 Div로 구현됨 (sequence 핸들러 사용)
            const allActions: any[] = [];
            const collectActions = (node: any): void => {
                if (node?.actions) allActions.push(...node.actions);
                if (node?.children) node.children.forEach(collectActions);
            };
            collectActions(dateRow);
            const setDateRangeActions = allActions.filter(
                (a: any) =>
                    a.handler === 'sequence' &&
                    a.actions?.some((sa: any) =>
                        sa.handler === 'sirsoft-ecommerce.setDateRange',
                    ),
            );
            expect(setDateRangeActions.length).toBeGreaterThanOrEqual(3);
        });
    });

    describe('일괄처리 영역 검증', () => {
        it('batch_actions 요소가 존재해야 한다', () => {
            const batchActions = findById(contentRoot, 'batch_actions');
            expect(batchActions).not.toBeNull();
        });

        it('일괄처리 Select의 value가 batchAction을 참조해야 한다', () => {
            const batchActions = findById(contentRoot, 'batch_actions');
            const selects = findAllByName(batchActions, 'Select');
            const batchSelect = selects.find(
                (s: any) =>
                    typeof s.props?.value === 'string' &&
                    s.props.value.includes('batchAction'),
            );
            expect(batchSelect).toBeDefined();
        });

        it('일괄처리 Select onChange가 batchAction 상태를 setState로 업데이트해야 한다', () => {
            const batchActions = findById(contentRoot, 'batch_actions');
            const selects = findAllByName(batchActions, 'Select');
            const batchSelect = selects.find(
                (s: any) =>
                    typeof s.props?.value === 'string' &&
                    s.props.value.includes('batchAction'),
            );
            expect(batchSelect).toBeDefined();
            const action = batchSelect.actions[0];
            expect(action.handler).toBe('setState');
        });

        it('적용 버튼의 disabled 조건에 selectedReviews가 포함되어야 한다', () => {
            const batchActions = findById(contentRoot, 'batch_actions');
            const buttons = findAllByName(batchActions, 'Button');
            const applyButton = buttons.find(
                (b: any) =>
                    typeof b.props?.disabled === 'string' &&
                    b.props.disabled.includes('selectedReviews'),
            );
            expect(applyButton).toBeDefined();
        });

        it('적용 버튼의 disabled 조건에 batchAction이 포함되어야 한다', () => {
            const batchActions = findById(contentRoot, 'batch_actions');
            const buttons = findAllByName(batchActions, 'Button');
            const applyButton = buttons.find(
                (b: any) =>
                    typeof b.props?.disabled === 'string' &&
                    b.props.disabled.includes('selectedReviews'),
            );
            expect(applyButton?.props.disabled).toContain('batchAction');
        });

        it('적용 버튼 클릭 시 openModal 핸들러가 sequence에 포함되어야 한다', () => {
            const batchActions = findById(contentRoot, 'batch_actions');
            const buttons = findAllByName(batchActions, 'Button');
            const applyButton = buttons.find(
                (b: any) =>
                    typeof b.props?.disabled === 'string' &&
                    b.props.disabled.includes('selectedReviews'),
            );
            const clickAction = applyButton?.actions?.find((a: any) => a.type === 'click');
            expect(clickAction?.handler).toBe('sequence');
            const hasOpenModal = clickAction?.actions?.some(
                (a: any) => a.handler === 'openModal',
            );
            expect(hasOpenModal).toBe(true);
        });
    });

    describe('DataGrid 검증', () => {
        it('review_datagrid 요소가 존재해야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid).not.toBeNull();
        });

        it('DataGrid data가 reviews.data?.data를 source로 사용해야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid.props.data).toContain('reviews.data');
            // reviews.data?.data 또는 reviews.data?.data ?? [] 형태 검증
            expect(datagrid.props.data).toMatch(/reviews\.data\??\.(data)/);
        });

        it('DataGrid가 selectable=true 속성을 가져야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid.props.selectable).toBe(true);
        });

        it('DataGrid selectedIds가 selectedReviews를 참조해야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid.props.selectedIds).toContain('selectedReviews');
        });

        it('DataGrid가 expandable=true 속성을 가져야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid.props.expandable).toBe(true);
        });

        it('DataGrid expandedRowIds가 expandedRows를 참조해야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid.props.expandedRowIds).toContain('expandedRows');
        });

        it('DataGrid loading이 reviews.$loading을 참조해야 한다', () => {
            const datagrid = findById(contentRoot, 'review_datagrid');
            expect(datagrid.props.loading).toContain('reviews.$loading');
        });
    });

    describe('Pagination 컴포넌트 검증', () => {
        it('Pagination 컴포넌트가 존재해야 한다', () => {
            const paginations = findAllByName(contentRoot, 'Pagination');
            expect(paginations.length).toBeGreaterThan(0);
        });

        it('Pagination이 last_page > 1 조건으로 표시되어야 한다', () => {
            const paginations = findAllByName(contentRoot, 'Pagination');
            const pagination = paginations[0];
            expect(pagination.if).toContain('last_page');
            expect(pagination.if).toContain('1');
        });

        it('Pagination currentPage가 reviews.data?.meta?.current_page를 참조해야 한다', () => {
            const paginations = findAllByName(contentRoot, 'Pagination');
            const pagination = paginations[0];
            expect(pagination.props.currentPage).toContain('current_page');
        });

        it('Pagination totalPages가 reviews.data?.meta?.last_page를 참조해야 한다', () => {
            const paginations = findAllByName(contentRoot, 'Pagination');
            const pagination = paginations[0];
            expect(pagination.props.totalPages).toContain('last_page');
        });

        it('Pagination onPageChange 이벤트가 navigate 핸들러를 사용해야 한다', () => {
            const paginations = findAllByName(contentRoot, 'Pagination');
            const pagination = paginations[0];
            const pageChangeAction = pagination.actions?.find(
                (a: any) => a.event === 'onPageChange',
            );
            expect(pageChangeAction).toBeDefined();
            expect(pageChangeAction.handler).toBe('navigate');
        });
    });
});

// ─── 다국어 키 종합 검증 ───

describe('다국어 키 종합 검증', () => {
    const prefix = 'sirsoft-ecommerce.admin.product_review';

    it('meta.title이 product_review.title 다국어 키를 사용해야 한다', () => {
        expect(layout.meta?.title).toBe(`$t:${prefix}.title`);
    });

    it('meta.description이 product_review.description 다국어 키를 사용해야 한다', () => {
        expect(layout.meta?.description).toBe(`$t:${prefix}.description`);
    });

    it('slots.content 내 다국어 키가 product_review 네임스페이스를 포함해야 한다', () => {
        const keys = collectI18nKeys(contentRoot);
        const productReviewKeys = keys.filter((k) => k.startsWith(prefix));
        expect(productReviewKeys.length).toBeGreaterThan(0);
    });

    it('필터 관련 다국어 키(filter.*)가 존재해야 한다', () => {
        const keys = collectI18nKeys(contentRoot);
        const filterKeys = keys.filter((k) => k.includes('.filter.'));
        expect(filterKeys.length).toBeGreaterThan(0);
    });

    it('테이블 컬럼 관련 다국어 키(table.columns.*)가 존재해야 한다', () => {
        const keys = collectI18nKeys(contentRoot);
        const columnKeys = keys.filter((k) => k.includes('table.columns'));
        expect(columnKeys.length).toBeGreaterThan(0);
    });

    it('일괄처리 관련 다국어 키(batch.*)가 존재해야 한다', () => {
        const keys = collectI18nKeys(contentRoot);
        const batchKeys = keys.filter((k) => k.includes('.batch.'));
        expect(batchKeys.length).toBeGreaterThan(0);
    });
});
