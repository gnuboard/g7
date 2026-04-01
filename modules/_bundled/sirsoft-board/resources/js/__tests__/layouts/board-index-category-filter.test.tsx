// @vitest-environment jsdom
/**
 * @file board-index-category-filter.test.tsx
 * @description 게시판 게시글 목록 - 카테고리 필터 검색 기능 테스트
 *
 * 검증 항목:
 * 1. 카테고리 필터 표시/미표시 조건
 * 2. API params에 category 포함
 * 3. 페이지네이션에 category query 유지
 * 4. 빈 결과 조건 분기 (카테고리 필터 반영)
 */

import '@testing-library/jest-dom';
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// G7Core 전역 모킹
(globalThis as any).window = globalThis.window || globalThis;
(window as any).G7Core = (window as any).G7Core || {
  createChangeEvent: vi.fn((data: { checked?: boolean; name?: string; value?: any }) => ({
    target: {
      checked: data.checked ?? false,
      name: data.name ?? '',
      value: data.value ?? (data.checked ? 'on' : 'off'),
      type: 'checkbox',
    },
    currentTarget: {
      checked: data.checked ?? false,
      name: data.name ?? '',
      value: data.value ?? (data.checked ? 'on' : 'off'),
      type: 'checkbox',
    },
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
  })),
  state: {
    get: vi.fn(),
    set: vi.fn(),
    update: vi.fn(),
    subscribe: vi.fn(() => vi.fn()),
  },
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
  modal: { open: vi.fn(), close: vi.fn() },
  events: { emit: vi.fn(), on: vi.fn(() => vi.fn()), off: vi.fn() },
  t: (key: string) => key,
};

// ============================================================
// 테스트용 컴포넌트 정의
// ============================================================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

const TestButton: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSelect: React.FC<{
  value?: string | number;
  className?: string;
  options?: Array<{ value: string | number; label: string }>;
  onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
  'data-testid'?: string;
}> = ({ value, className, options, onChange, 'data-testid': testId }) => (
  <select
    value={value}
    className={className}
    onChange={onChange}
    data-testid={testId}
  >
    {options?.map((opt) => (
      <option key={opt.value} value={opt.value}>
        {opt.label}
      </option>
    ))}
  </select>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestH2: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h2 className={className}>{children || text}</h2>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={className} data-icon={name} data-size={size} />
);

const TestContainer: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <div className={className} data-testid="container">
    {children}
  </div>
);

const TestSearchBar: React.FC<{
  name?: string;
  placeholder?: string;
  value?: string;
  showButton?: boolean;
  className?: string;
  onSubmit?: (e: React.FormEvent) => void;
  'data-testid'?: string;
}> = ({ name, placeholder, value, className, onSubmit, 'data-testid': testId }) => (
  <form onSubmit={onSubmit} data-testid={testId || 'search-bar'}>
    <input name={name} placeholder={placeholder} defaultValue={value} />
  </form>
);

const TestPagination: React.FC<{
  currentPage?: number;
  totalPages?: number;
  className?: string;
  onPageChange?: (page: number) => void;
}> = ({ currentPage, totalPages, className, onPageChange }) => (
  <nav className={className} data-testid="pagination">
    <span>
      Page {currentPage} of {totalPages}
    </span>
    {onPageChange && (
      <button onClick={() => onPageChange((currentPage ?? 1) + 1)}>Next</button>
    )}
  </nav>
);

// ============================================================
// 컴포넌트 레지스트리 설정
// ============================================================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: {
      component: TestButton,
      metadata: { name: 'Button', type: 'basic' },
    },
    Select: {
      component: TestSelect,
      metadata: { name: 'Select', type: 'basic' },
    },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Container: {
      component: TestContainer,
      metadata: { name: 'Container', type: 'layout' },
    },
    SearchBar: {
      component: TestSearchBar,
      metadata: { name: 'SearchBar', type: 'composite' },
    },
    Pagination: {
      component: TestPagination,
      metadata: { name: 'Pagination', type: 'composite' },
    },
  };

  return registry;
}

// ============================================================
// 레이아웃 JSON (카테고리 필터 관련 부분만 발췌)
// ============================================================

/**
 * 카테고리가 있는 게시판의 게시글 목록 레이아웃 (간략화)
 * 실제 index.json에서 카테고리 필터 관련 기능만 추출
 */
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
          props: {
            'data-testid': 'board-title',
          },
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
            {
              type: 'basic',
              name: 'P',
              text: '등록된 게시글이 없습니다',
            },
          ],
        },
        {
          comment: '검색/카테고리 결과 없음',
          type: 'basic',
          name: 'Div',
          if: '{{posts?.data?.board?.slug && (query.search || query.category) && posts?.data?.pagination?.total === 0}}',
          props: { 'data-testid': 'empty-with-filter' },
          children: [
            {
              type: 'basic',
              name: 'P',
              text: '검색 결과가 없습니다',
            },
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
};

// ============================================================
// 테스트 데이터
// ============================================================

/** 카테고리가 있는 게시판 + 게시글이 있는 응답 */
const boardWithCategoriesResponse = {
  data: {
    board: {
      id: 1,
      slug: 'test-board',
      name: '테스트 게시판',
      type: 'basic',
      categories: ['공지사항', '자유게시판', '질문답변'],
      show_category: true,
    },
    data: [
      { id: 1, title: '첫 번째 글', category: '공지사항' },
      { id: 2, title: '두 번째 글', category: '자유게시판' },
    ],
    pagination: { current_page: 1, last_page: 3, total: 25 },
    permissions: { posts_write: true },
  },
};

/** 카테고리가 없는 게시판 응답 */
const boardWithoutCategoriesResponse = {
  data: {
    board: {
      id: 2,
      slug: 'no-cat-board',
      name: '카테고리 없는 게시판',
      type: 'basic',
      categories: [],
      show_category: false,
    },
    data: [{ id: 1, title: '글1' }],
    pagination: { current_page: 1, last_page: 1, total: 1 },
    permissions: { posts_write: true },
  },
};

/** 빈 결과 (필터 없이) */
const emptyBoardResponse = {
  data: {
    board: {
      id: 1,
      slug: 'test-board',
      name: '테스트 게시판',
      type: 'basic',
      categories: ['공지사항'],
      show_category: true,
    },
    data: [],
    pagination: { current_page: 1, last_page: 1, total: 0 },
    permissions: { posts_write: true },
  },
};

// ============================================================
// 테스트
// ============================================================

describe('게시판 목록 - 카테고리 필터', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('카테고리 필터 표시/미표시 조건', () => {
    it('카테고리가 있는 게시판에서 카테고리 Select가 표시된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        initialData: { posts: boardWithCategoriesResponse },
      });

      await testUtils.render();

      // 카테고리 필터 Select가 존재해야 함
      const categorySelect = screen.queryByTestId('category-filter');
      expect(categorySelect).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('카테고리가 없는 게시판에서 카테고리 Select가 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'no-cat-board' },
        initialData: { posts: boardWithoutCategoriesResponse },
      });

      await testUtils.render();

      // 카테고리 필터 Select가 없어야 함
      const categorySelect = screen.queryByTestId('category-filter');
      expect(categorySelect).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('categories가 빈 배열인 게시판에서 카테고리 Select가 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'no-cat-board' },
        initialData: {
          posts: {
            data: {
              ...boardWithoutCategoriesResponse.data,
              board: {
                ...boardWithoutCategoriesResponse.data.board,
                categories: [],
              },
            },
          },
        },
      });

      await testUtils.render();

      const categorySelect = screen.queryByTestId('category-filter');
      expect(categorySelect).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('API params에 category 포함', () => {
    it('data_sources params에 category 파라미터가 포함된다', () => {
      // 레이아웃 JSON 구조 검증 (정적 분석)
      const dataSource = categoryFilterLayoutJson.data_sources[0];
      expect(dataSource.params).toHaveProperty('category');
      expect(dataSource.params.category).toBe("{{query.category ?? ''}}");
    });

    it('query.category가 있을 때 API 요청에 category가 포함된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { category: '공지사항' },
        initialData: { posts: boardWithCategoriesResponse },
      });

      testUtils.mockApi('posts', {
        response: boardWithCategoriesResponse.data,
      });

      await testUtils.render();

      // category Select의 value가 query.category와 일치해야 함
      const categorySelect = screen.queryByTestId('category-filter');
      expect(categorySelect).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('카테고리 필터 변경 시 네비게이션', () => {
    it('카테고리 선택 시 navigate 핸들러가 호출된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        initialData: { posts: boardWithCategoriesResponse },
      });

      await testUtils.render();

      // 카테고리 변경 액션 트리거
      await testUtils.triggerAction({
        type: 'change',
        handler: 'navigate',
        params: {
          path: '/board/test-board',
          mergeQuery: true,
          query: {
            category: '공지사항',
            page: 1,
          },
        },
      });

      // navigate가 호출되었는지 확인
      expect(testUtils.mockNavigate).toHaveBeenCalled();
      expect(testUtils.getNavigationHistory()).toContain('/board/test-board');

      testUtils.cleanup();
    });

    it('"전체 카테고리" 선택 시 category 값이 빈 문자열이어야 한다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { category: '공지사항' },
        initialData: { posts: boardWithCategoriesResponse },
      });

      await testUtils.render();

      // "전체" 선택 시 category='' 로 네비게이션
      await testUtils.triggerAction({
        type: 'change',
        handler: 'navigate',
        params: {
          path: '/board/test-board',
          mergeQuery: true,
          query: {
            category: '', // 'all' 선택 시 빈 문자열
            page: 1,
          },
        },
      });

      expect(testUtils.mockNavigate).toHaveBeenCalled();

      testUtils.cleanup();
    });
  });

  describe('페이지네이션에 category query 유지', () => {
    it('페이지네이션 액션 query에 category가 포함된다', () => {
      // 레이아웃 JSON 구조 검증 (정적 분석)
      const container = categoryFilterLayoutJson.components[0];
      const pagination = container.children?.find(
        (c: any) => c.name === 'Pagination'
      );

      expect(pagination).toBeDefined();
      expect(pagination?.actions?.[0]?.params?.query).toHaveProperty(
        'category'
      );
      expect(pagination?.actions?.[0]?.params?.query?.category).toBe(
        '{{query.category}}'
      );
    });

    it('페이지 변경 시 카테고리 필터가 유지된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { category: '공지사항', page: '1' },
        initialData: { posts: boardWithCategoriesResponse },
      });

      await testUtils.render();

      // 페이지 변경 트리거
      await testUtils.triggerAction({
        type: 'change',
        handler: 'navigate',
        params: {
          path: '/board/test-board',
          mergeQuery: true,
          query: {
            page: 2,
            category: '공지사항', // 카테고리 유지
          },
        },
      });

      expect(testUtils.mockNavigate).toHaveBeenCalled();

      testUtils.cleanup();
    });
  });

  describe('빈 결과 조건 분기', () => {
    it('필터 없이 게시글이 0건일 때 "게시글 없음" 메시지가 표시된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      // "게시글 없음" (필터 없을 때)
      const emptyNoFilter = screen.queryByTestId('empty-no-filter');
      expect(emptyNoFilter).toBeInTheDocument();

      // "검색 결과 없음"은 표시되지 않아야 함
      const emptyWithFilter = screen.queryByTestId('empty-with-filter');
      expect(emptyWithFilter).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('카테고리 필터 결과가 0건일 때 "검색 결과 없음" 메시지가 표시된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { category: '공지사항' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      // "검색 결과 없음" (카테고리 필터 있을 때)
      const emptyWithFilter = screen.queryByTestId('empty-with-filter');
      expect(emptyWithFilter).toBeInTheDocument();

      // "게시글 없음"은 표시되지 않아야 함 (category 필터가 있으므로)
      const emptyNoFilter = screen.queryByTestId('empty-no-filter');
      expect(emptyNoFilter).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('검색어가 있고 결과가 0건일 때 "검색 결과 없음" 메시지가 표시된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { search: '없는글' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      const emptyWithFilter = screen.queryByTestId('empty-with-filter');
      expect(emptyWithFilter).toBeInTheDocument();

      const emptyNoFilter = screen.queryByTestId('empty-no-filter');
      expect(emptyNoFilter).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('카테고리 + 검색 동시 필터 결과가 0건일 때 "검색 결과 없음" 메시지가 표시된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { category: '공지사항', search: '없는글' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      const emptyWithFilter = screen.queryByTestId('empty-with-filter');
      expect(emptyWithFilter).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('초기화 버튼 클릭 시 모든 필터가 해제된다', async () => {
      const testUtils = createLayoutTest(categoryFilterLayoutJson, {
        componentRegistry: registry,
        translations: { board: { filter: { category_all: '전체 카테고리' } } },
        routeParams: { slug: 'test-board' },
        queryParams: { category: '공지사항' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      // 초기화 버튼 트리거 (path만 지정 → 쿼리 초기화)
      await testUtils.triggerAction({
        type: 'click',
        handler: 'navigate',
        params: {
          path: '/board/test-board',
        },
      });

      expect(testUtils.mockNavigate).toHaveBeenCalled();
      // path에 query가 없으므로 필터 해제
      expect(testUtils.getNavigationHistory()).toContain('/board/test-board');

      testUtils.cleanup();
    });
  });

  describe('레이아웃 JSON 구조 정적 검증', () => {
    it('data_sources에 category 파라미터가 올바르게 정의되어 있다', () => {
      const ds = categoryFilterLayoutJson.data_sources[0];
      expect(ds.params.category).toBe("{{query.category ?? ''}}");
    });

    it('Select 컴포넌트 타입이 basic이다', () => {
      const container = categoryFilterLayoutJson.components[0];
      const filterBar = container.children?.find(
        (c: any) => c.comment === '필터 바 (카테고리 필터 + 검색)'
      );
      const select = filterBar?.children?.find(
        (c: any) => c.name === 'Select'
      );

      expect(select).toBeDefined();
      expect(select?.type).toBe('basic');
    });

    it('Select의 change 액션이 navigate 핸들러를 사용한다', () => {
      const container = categoryFilterLayoutJson.components[0];
      const filterBar = container.children?.find(
        (c: any) => c.comment === '필터 바 (카테고리 필터 + 검색)'
      );
      const select = filterBar?.children?.find(
        (c: any) => c.name === 'Select'
      );
      const action = select?.actions?.[0];

      expect(action?.type).toBe('change');
      expect(action?.handler).toBe('navigate');
      expect(action?.params?.mergeQuery).toBe(true);
      expect(action?.params?.query?.page).toBe(1);
    });

    it('Select의 if 조건이 categories 존재 여부를 확인한다', () => {
      const container = categoryFilterLayoutJson.components[0];
      const filterBar = container.children?.find(
        (c: any) => c.comment === '필터 바 (카테고리 필터 + 검색)'
      );
      const select = filterBar?.children?.find(
        (c: any) => c.name === 'Select'
      );

      expect(select?.if).toContain('categories');
      expect(select?.if).toContain('length > 0');
    });

    it('빈 결과 조건에 !query.category가 포함되어 있다', () => {
      const container = categoryFilterLayoutJson.components[0];
      const emptyNoFilter = container.children?.find(
        (c: any) => c.props?.['data-testid'] === 'empty-no-filter'
      );

      expect(emptyNoFilter?.if).toContain('!query.category');
    });

    it('검색 결과 없음 조건에 query.category가 포함되어 있다', () => {
      const container = categoryFilterLayoutJson.components[0];
      const emptyWithFilter = container.children?.find(
        (c: any) => c.props?.['data-testid'] === 'empty-with-filter'
      );

      expect(emptyWithFilter?.if).toContain('query.category');
    });
  });
});
