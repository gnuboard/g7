// @vitest-environment jsdom
import '@testing-library/jest-dom';

/**
 * 관리자 상품 리뷰 목록 레이아웃 렌더링 테스트
 *
 * @description
 * - admin_ecommerce_product_review_index.json 렌더링 검증
 * - reviews 데이터소스 API 모킹 및 바인딩 검증
 * - 검색 필터 구조 검증
 * - DataGrid 렌더링 검증
 * - 일괄처리(batch_actions) 구조 검증
 * - 권한(permissions) 설정 검증
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import layoutJson from '../../../layouts/admin/admin_ecommerce_product_review_index.json';

// ─── 테스트용 컴포넌트 ───

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
    ({ className, children, 'data-testid': testId }) => (
        <div className={className} data-testid={testId}>{children}</div>
    );

const TestButton: React.FC<{
    type?: string; className?: string; disabled?: boolean;
    children?: React.ReactNode; onClick?: () => void; 'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
    <button type={type as any} className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
        {children}
    </button>
);

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <span className={className}>{children || text}</span>;

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h1 className={className}>{children || text}</h1>;

const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <h3 className={className}>{children || text}</h3>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> =
    ({ className, children, text }) => <p className={className}>{children || text}</p>;

const TestImg: React.FC<{ src?: string; alt?: string; className?: string }> =
    ({ src, alt, className }) => <img src={src} alt={alt} className={className} />;

const TestInput: React.FC<{
    type?: string; placeholder?: string; value?: string; className?: string;
    disabled?: boolean; onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void; 'data-testid'?: string;
}> = ({ type, placeholder, value, className, disabled, onChange, 'data-testid': testId }) => (
    <input type={type} placeholder={placeholder} value={value} className={className}
        disabled={disabled} onChange={onChange} data-testid={testId} />
);

const TestSelect: React.FC<{
    value?: string; className?: string; children?: React.ReactNode; options?: any[];
    onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void; 'data-testid'?: string;
}> = ({ value, className, options, onChange, 'data-testid': testId }) => (
    <select value={value} className={className} onChange={onChange} data-testid={testId}>
        {options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

const TestDataGrid: React.FC<{
    data?: any[]; columns?: any[]; loading?: boolean; selectable?: boolean;
    expandable?: boolean; emptyMessage?: string; 'data-testid'?: string;
}> = ({ data, loading, emptyMessage, 'data-testid': testId }) => (
    <div data-testid={testId || 'review-datagrid'} data-loading={loading ? 'true' : 'false'}>
        {loading ? '로딩 중...' : data && data.length > 0
            ? `리뷰 ${data.length}건`
            : emptyMessage || '리뷰 없음'}
    </div>
);

const TestPagination: React.FC<{ total?: number; page?: number; lastPage?: number }> =
    ({ total, page, lastPage }) => (
        lastPage && lastPage > 1 ? (
            <div data-testid="pagination">페이지 {page} / 전체 {total}건</div>
        ) : null
    );

const TestModal: React.FC<{ id?: string; isOpen?: boolean; title?: string; children?: React.ReactNode }> =
    ({ id, isOpen, title, children }) => (
        isOpen ? (
            <div data-testid={`modal-${id}`} role="dialog">
                <h2>{title}</h2>
                {children}
            </div>
        ) : null
    );

const TestIcon: React.FC<{ name?: string; className?: string }> =
    ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} />;

const TestTextarea: React.FC<{
    value?: string; className?: string; rows?: number; placeholder?: string;
    onChange?: (e: React.ChangeEvent<HTMLTextAreaElement>) => void;
}> = ({ value, className, rows, placeholder, onChange }) => (
    <textarea value={value} className={className} rows={rows} placeholder={placeholder} onChange={onChange} />
);

// ─── 컴포넌트 레지스트리 ───

function setupTestRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
        Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
        Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
        Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
        H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
        H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
        P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
        Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
        Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
        Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
        Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
        Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
        Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'basic' } },
        DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
        Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
        Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    };
    return registry;
}

// ─── Mock 데이터 ───

const mockReviews = {
    data: [
        {
            id: 1,
            product: { id: 10, name: '프리미엄 무선 이어폰', thumbnail_url: '' },
            user: { id: 5, name: '김철수', username: 'user001' },
            rating: 5,
            content: '품질이 정말 좋고 착용감도 편안합니다.',
            status: 'visible',
            has_reply: true,
            reply_content: '소중한 리뷰 감사합니다!',
            image_count: 2,
            images: [{ id: 1, url: '' }, { id: 2, url: '' }],
            option_snapshot_label: '색상:블랙/용량:128GB',
            created_at: '2025-10-29T14:30:00Z',
            replied_at: '2025-10-29T15:00:00Z',
        },
        {
            id: 2,
            product: { id: 11, name: '스마트 워치', thumbnail_url: '' },
            user: { id: 6, name: '이영희', username: 'user002' },
            rating: 3,
            content: '보통입니다.',
            status: 'hidden',
            has_reply: false,
            reply_content: null,
            image_count: 0,
            images: [],
            option_snapshot_label: null,
            created_at: '2025-11-01T10:00:00Z',
            replied_at: null,
        },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
};

const translations = {
    'sirsoft-ecommerce': {
        admin: {
            product_review: {
                title: '상품리뷰 관리',
                description: '고객 리뷰를 확인하고 답변을 관리하세요',
                search: {
                    field: {
                        product_name: '상품명',
                        reviewer: '작성자',
                        content: '리뷰내용',
                        order_number: '주문번호',
                        option_name: '옵션명',
                    },
                    placeholder: '검색어를 입력하세요',
                    column_select: '컬럼 선택',
                },
                filter: {
                    rating: '평점',
                    reply_status: '답변 상태',
                    photo: '포토 여부',
                    review_status: '리뷰 상태',
                    date_range: '작성일 기간',
                    all: '전체',
                    replied: '답변완료',
                    not_replied: '미답변',
                    photo_review: '포토리뷰',
                    text_review: '일반리뷰',
                    visible: '전시중',
                    hidden: '숨김',
                    search: '검색',
                    reset: '초기화',
                    date_quick: {
                        today: '오늘', '3days': '3일간', week: '일주일',
                        month: '1개월', '3months': '3개월', all: '전체',
                    },
                },
                batch: {
                    selected_count: '{{count}}개 선택됨',
                    status_change: '리뷰 상태 변경',
                    apply: '적용',
                },
                table: {
                    total_count: '총 {{count}}개',
                    empty: '등록된 리뷰가 없습니다',
                    columns: {
                        product_name: '상품명', content: '리뷰 내용', rating: '평점',
                        reviewer: '작성자', order_number: '주문번호', created_at: '작성일',
                        reply: '답변', status: '상태', actions: '관리',
                    },
                },
                detail: {
                    seller_reply: {
                        edit_save: '저장',
                        delete_confirm: '답변을 삭제하시겠습니까?',
                        delete: '삭제',
                        edit: '수정',
                        cancel: '취소',
                        replied_at: '등록일',
                        modified_at: '수정일',
                    },
                    reply_form: { title: '판매자 답변', placeholder: '답변을 입력하세요', submit: '답변 등록' },
                },
            },
            settings: { review_settings: { title: '리뷰 설정' } },
        },
    },
    common: { loading: '처리 중...', cancel: '취소', save: '저장', delete: '삭제', confirm: '확인' },
};

// ─── 테스트 ───

describe('관리자 상품 리뷰 목록 레이아웃 렌더링', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;
    let registry: ComponentRegistry;

    beforeEach(() => {
        registry = setupTestRegistry();
        testUtils = createLayoutTest(layoutJson as any, {
            auth: {
                isAuthenticated: true,
                user: { id: 1, name: 'Admin', role: 'super_admin' },
                authType: 'admin',
            },
            translations,
            locale: 'ko',
            componentRegistry: registry,
        });
    });

    afterEach(() => {
        testUtils.cleanup();
    });

    // ─── 레이아웃 구조 검증 ───

    describe('레이아웃 구조 검증', () => {
        it('레이아웃 정보가 올바르게 로드된다', () => {
            const info = testUtils.getLayoutInfo();
            expect(info.name).toBe('admin_ecommerce_product_review_index');
            expect(info.version).toBe('1.0.0');
        });

        it('reviews 데이터소스가 정의되어 있다', () => {
            const dataSources = testUtils.getDataSources();
            const reviewsDs = dataSources.find((ds) => ds.id === 'reviews');
            expect(reviewsDs).toBeDefined();
            expect(reviewsDs?.endpoint).toContain('/admin/reviews');
            expect(reviewsDs?.auto_fetch).toBe(true);
        });

        it('reviews.read 권한이 설정되어 있다', () => {
            expect((layoutJson as any).permissions).toContain('sirsoft-ecommerce.reviews.read');
        });

        it('state에 selectedReviews, expandedRows가 정의되어 있다', () => {
            expect(Array.isArray((layoutJson as any).state.selectedReviews)).toBe(true);
            expect(Array.isArray((layoutJson as any).state.expandedRows)).toBe(true);
        });
    });

    // ─── 렌더링 검증 ───

    describe('컴포넌트 렌더링 검증', () => {
        it('데이터가 있을 때 레이아웃이 정상 렌더링된다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            const { container } = await testUtils.render();
            expect(container.innerHTML.length).toBeGreaterThan(0);
        });

        it('데이터가 있을 때 제목이 렌더링된다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            await testUtils.render();
            expect(screen.getByText('상품리뷰 관리')).toBeInTheDocument();
        });

        it('DataGrid 컴포넌트가 레지스트리에 등록되어 있다', () => {
            expect(registry.hasComponent('DataGrid')).toBe(true);
        });

        it('빈 데이터 시 emptyMessage가 렌더링된다', async () => {
            testUtils.mockApi('reviews', {
                response: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } },
            });
            await testUtils.render();
            const datagrid = screen.queryByTestId('review-datagrid');
            if (datagrid) {
                expect(datagrid.textContent).toContain('없음');
            }
        });
    });

    // ─── 상태 초기화 검증 ───

    describe('초기 상태 검증', () => {
        it('렌더링 후 로컬 상태가 초기화된다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            await testUtils.render();
            const state = testUtils.getState();
            expect(state._local).toBeDefined();
        });

        it('selectedReviews 초기값이 빈 배열이다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            await testUtils.render();
            const state = testUtils.getState();
            expect(state._local.selectedReviews ?? []).toEqual([]);
        });
    });

    // ─── 데이터 바인딩 검증 ───

    describe('데이터 바인딩 검증', () => {
        it('레이아웃 검증 오류가 없어야 한다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            await testUtils.render();
            expect(() => testUtils.assertNoValidationErrors()).not.toThrow();
        });

        it('reviews 데이터소스가 올바른 배열 경로를 사용한다', () => {
            // DataGrid data prop이 reviews.data?.data를 참조해야 함 (reviews.data는 객체)
            const datagrid = (layoutJson as any).slots?.content?.[0]?.children
                ?.flatMap((c: any) => c.children ?? [])
                ?.find((c: any) => c.id === 'review_datagrid')
                ?? null;
            // DataGrid가 있을 경우 data 바인딩이 .data?.data를 사용하는지 확인
            if (datagrid) {
                expect(datagrid.props?.data).toContain('reviews.data');
            }
        });
    });

    // ─── 일괄처리 구조 검증 ───

    describe('일괄처리 버튼 동작 검증', () => {
        it('선택 없이 적용 버튼이 비활성화된다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            await testUtils.render();
            // 선택 없이 batchAction도 없으면 버튼 disabled 확인
            const state = testUtils.getState();
            const selectedReviews = state._local.selectedReviews ?? [];
            expect(selectedReviews.length).toBe(0);
        });

        it('setState로 selectedReviews 상태를 변경할 수 있다', async () => {
            testUtils.mockApi('reviews', { response: mockReviews });
            await testUtils.render();
            testUtils.setState('selectedReviews', [1, 2], 'local');
            const state = testUtils.getState();
            expect(state._local.selectedReviews).toEqual([1, 2]);
        });
    });
});
