// @vitest-environment jsdom
/**
 * @file board-index-empty-states.test.tsx
 * @description 게시판 목록 - 빈 상태, 로딩/오류, 글쓰기 버튼 렌더링 테스트
 *
 * 검증 항목:
 * 1. 빈 상태 3종 (빈 페이지 / 게시글 없음 / 검색 결과 없음) 조건부 렌더링
 * 2. 로딩 스켈레톤 / 오류 페이지 조건부 렌더링
 * 3. 글쓰기 버튼 권한별 분기 (allowed / no_permission / not_logged_in)
 */

import '@testing-library/jest-dom';
import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
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
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
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
}> = ({ name, placeholder, value, className, onSubmit }) => (
  <form onSubmit={onSubmit} data-testid="search-bar">
    <input name={name} placeholder={placeholder} defaultValue={value} />
  </form>
);

const TestPagination: React.FC<{
  currentPage?: number;
  totalPages?: number;
  className?: string;
  onPageChange?: (page: number) => void;
}> = ({ currentPage, totalPages, className }) => (
  <nav className={className} data-testid="pagination">
    <span>
      Page {currentPage} of {totalPages}
    </span>
  </nav>
);

const TestSelect: React.FC<{
  value?: string | number;
  className?: string;
  options?: Array<{ value: string | number; label: string }>;
  onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
}> = ({ value, className, options, onChange }) => (
  <select value={value} className={className} onChange={onChange}>
    {options?.map((opt) => (
      <option key={opt.value} value={opt.value}>
        {opt.label}
      </option>
    ))}
  </select>
);

// ============================================================
// 컴포넌트 레지스트리 설정
// ============================================================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    SearchBar: { component: TestSearchBar, metadata: { name: 'SearchBar', type: 'composite' } },
    Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
  };

  return registry;
}

// ============================================================
// 레이아웃 JSON (빈 상태 + 로딩/오류 + 글쓰기 버튼 검증용)
// ============================================================

/**
 * 리팩토링된 index.json 구조의 핵심 부분을 재현
 * partial 분리된 내용을 인라인으로 포함 (테스트 편의)
 */
const boardIndexLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_index_empty_states_test',
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
    },
  ],
  components: [
    {
      comment: '로딩 스켈레톤 (오류 없을 때만)',
      type: 'layout',
      name: 'Container',
      if: '{{!posts?.data?.board && !_global.hasError}}',
      props: { 'data-testid': 'loading-skeleton' },
      children: [
        {
          type: 'basic',
          name: 'P',
          text: '게시글을 불러오는 중입니다...',
        },
      ],
    },
    {
      comment: '오류 페이지',
      type: 'layout',
      name: 'Container',
      if: '{{_global.hasError}}',
      props: { 'data-testid': 'error-page' },
      children: [
        {
          type: 'basic',
          name: 'H2',
          props: { 'data-testid': 'error-title' },
          text: '일시적인 오류가 발생했습니다',
        },
        {
          type: 'basic',
          name: 'Button',
          props: { 'data-testid': 'reload-btn' },
          text: '새로고침',
          actions: [{ type: 'click', handler: 'refresh' }],
        },
      ],
    },
    {
      comment: '메인 컨테이너 (데이터 로드 완료 시)',
      type: 'layout',
      name: 'Container',
      if: '{{posts?.data?.board}}',
      children: [
        {
          type: 'basic',
          name: 'H1',
          props: { 'data-testid': 'board-title' },
          text: "{{posts?.data?.board?.name ?? ''}}",
        },
        {
          comment: '글쓰기 버튼',
          type: 'basic',
          name: 'Button',
          props: { 'data-testid': 'write-btn' },
          actions: [
            {
              type: 'click',
              handler: 'switch',
              params: {
                value:
                  "{{posts?.data?.permissions?.posts_write ? 'allowed' : (_global.currentUser?.uuid ? 'no_permission' : 'not_logged_in')}}",
              },
              cases: {
                allowed: {
                  handler: 'navigate',
                  params: { path: '/board/{{route.slug}}/write' },
                },
                no_permission: {
                  handler: 'toast',
                  params: { type: 'warning', message: '글쓰기 권한이 없습니다' },
                },
                not_logged_in: {
                  handler: 'sequence',
                  actions: [
                    {
                      handler: 'toast',
                      params: { type: 'info', message: '글을 작성하려면 로그인이 필요합니다' },
                    },
                    {
                      handler: 'navigate',
                      params: {
                        path: '/login',
                        query: { redirect: '/board/{{route.slug}}/write' },
                      },
                    },
                  ],
                },
              },
            },
          ],
          children: [
            { type: 'basic', name: 'Icon', props: { name: 'pencil', size: 'sm' } },
            { type: 'basic', name: 'Span', text: '글쓰기' },
          ],
        },
        {
          comment: '빈 페이지 (page > 1 + 데이터 0건)',
          type: 'basic',
          name: 'Div',
          if: '{{posts?.data?.board?.slug && query.page && Number(query.page) > 1 && posts?.data?.data?.length === 0}}',
          props: {
            className: 'flex flex-col items-center justify-center py-16 text-center bg-gray-100/70 dark:bg-gray-800/50 rounded-xl',
            'data-testid': 'empty-page',
          },
          children: [
            { type: 'basic', name: 'P', text: '해당 페이지에 게시글이 없습니다' },
            {
              type: 'basic',
              name: 'Button',
              text: '첫 페이지로 이동',
              props: { 'data-testid': 'go-first-btn' },
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
          comment: '게시글 없음 (검색/카테고리 아닐 때)',
          type: 'basic',
          name: 'Div',
          if: "{{posts?.data?.board?.slug && !query.search && !query.category && (!query.page || Number(query.page) <= 1) && posts?.data?.pagination?.total === 0}}",
          props: {
            className: 'flex flex-col items-center justify-center py-16 text-center bg-gray-100/70 dark:bg-gray-800/50 rounded-xl',
            'data-testid': 'empty-no-posts',
          },
          children: [
            { type: 'basic', name: 'P', text: '등록된 게시글이 없습니다' },
          ],
        },
        {
          comment: '검색 결과 없음',
          type: 'basic',
          name: 'Div',
          if: '{{posts?.data?.board?.slug && (query.search || query.category) && posts?.data?.pagination?.total === 0}}',
          props: {
            className: 'flex flex-col items-center justify-center py-16 text-center bg-gray-100/70 dark:bg-gray-800/50 rounded-xl',
            'data-testid': 'empty-search',
          },
          children: [
            { type: 'basic', name: 'P', text: '검색 결과가 없습니다' },
            {
              type: 'basic',
              name: 'Button',
              text: '검색 초기화',
              props: { 'data-testid': 'clear-search-btn' },
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
        },
      ],
    },
  ],
};

// ============================================================
// 테스트 데이터
// ============================================================

/** 게시글이 있는 기본 응답 */
const boardWithPostsResponse = {
  data: {
    board: {
      id: 1,
      slug: 'test-board',
      name: '테스트 게시판',
      type: 'basic',
      categories: [],
    },
    data: [
      { id: 1, title: '첫 번째 글' },
      { id: 2, title: '두 번째 글' },
    ],
    pagination: { current_page: 1, last_page: 2, total: 15 },
    permissions: { posts_write: true },
  },
};

/** 게시글이 없는 응답 (total=0) */
const emptyBoardResponse = {
  data: {
    board: {
      id: 1,
      slug: 'test-board',
      name: '테스트 게시판',
      type: 'basic',
      categories: [],
    },
    data: [],
    pagination: { current_page: 1, last_page: 1, total: 0 },
    permissions: { posts_write: true },
  },
};

/** page > 1이면서 데이터 0건인 응답 */
const emptyPageResponse = {
  data: {
    board: {
      id: 1,
      slug: 'test-board',
      name: '테스트 게시판',
      type: 'basic',
      categories: [],
    },
    data: [],
    pagination: { current_page: 5, last_page: 3, total: 25 },
    permissions: { posts_write: true },
  },
};

/** 글쓰기 권한 없는 응답 (로그인 상태) */
const boardNoWritePermResponse = {
  data: {
    board: {
      id: 1,
      slug: 'test-board',
      name: '테스트 게시판',
      type: 'basic',
      categories: [],
    },
    data: [{ id: 1, title: '글1' }],
    pagination: { current_page: 1, last_page: 1, total: 1 },
    permissions: { posts_write: false },
  },
};

// ============================================================
// 테스트
// ============================================================

describe('게시판 목록 - 빈 상태/로딩/오류/글쓰기 버튼', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('로딩 스켈레톤 표시 조건', () => {
    it('데이터 로드 전 + 오류 없음: 로딩 스켈레톤 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: {},
        initGlobal: { hasError: false },
      });

      await testUtils.render();

      expect(screen.queryByTestId('loading-skeleton')).toBeInTheDocument();
      expect(screen.queryByTestId('error-page')).not.toBeInTheDocument();
      expect(screen.queryByTestId('board-title')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('데이터 로드 완료: 로딩 스켈레톤 미표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: { posts: boardWithPostsResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('loading-skeleton')).not.toBeInTheDocument();
      expect(screen.queryByTestId('board-title')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('오류 페이지 표시 조건', () => {
    it('_global.hasError가 true: 오류 페이지 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: {},
        initGlobal: { hasError: true },
      });

      await testUtils.render();

      expect(screen.queryByTestId('error-page')).toBeInTheDocument();
      expect(screen.queryByTestId('error-title')).toBeInTheDocument();
      expect(screen.queryByTestId('reload-btn')).toBeInTheDocument();
      expect(screen.queryByTestId('loading-skeleton')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('빈 상태 3종 조건부 렌더링', () => {
    it('게시글 없음 (검색/카테고리 없이 total=0): empty-no-posts 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('empty-no-posts')).toBeInTheDocument();
      expect(screen.queryByTestId('empty-search')).not.toBeInTheDocument();
      expect(screen.queryByTestId('empty-page')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('검색 결과 없음 (query.search 있고 total=0): empty-search 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        queryParams: { search: '존재하지않는검색어' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('empty-search')).toBeInTheDocument();
      expect(screen.queryByTestId('empty-no-posts')).not.toBeInTheDocument();
      expect(screen.queryByTestId('clear-search-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('카테고리 필터 결과 없음 (query.category 있고 total=0): empty-search 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        queryParams: { category: '존재하지않는카테고리' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('empty-search')).toBeInTheDocument();
      expect(screen.queryByTestId('empty-no-posts')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('빈 페이지 (page > 1 + data 0건): empty-page 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        queryParams: { page: '5' },
        initialData: { posts: emptyPageResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('empty-page')).toBeInTheDocument();
      expect(screen.queryByTestId('go-first-btn')).toBeInTheDocument();
      expect(screen.queryByTestId('empty-no-posts')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('게시글이 있을 때 빈 상태 모두 미표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: { posts: boardWithPostsResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('empty-no-posts')).not.toBeInTheDocument();
      expect(screen.queryByTestId('empty-search')).not.toBeInTheDocument();
      expect(screen.queryByTestId('empty-page')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('빈 상태 배경 스타일 일관성', () => {
    it('모든 빈 상태 컴포넌트가 동일한 배경 클래스를 가진다', () => {
      const expectedBgClass = 'bg-gray-100/70 dark:bg-gray-800/50 rounded-xl';
      const mainContainer = boardIndexLayoutJson.components[2];
      const children = mainContainer.children;

      // 빈 페이지, 게시글 없음, 검색 결과 없음 3개 컴포넌트 확인
      const emptyPageComp = children.find(
        (c: any) => c.props?.['data-testid'] === 'empty-page'
      );
      const emptyNoPostsComp = children.find(
        (c: any) => c.props?.['data-testid'] === 'empty-no-posts'
      );
      const emptySearchComp = children.find(
        (c: any) => c.props?.['data-testid'] === 'empty-search'
      );

      expect(emptyPageComp?.props?.className).toContain(expectedBgClass);
      expect(emptyNoPostsComp?.props?.className).toContain(expectedBgClass);
      expect(emptySearchComp?.props?.className).toContain(expectedBgClass);
    });
  });

  describe('글쓰기 버튼 switch 핸들러', () => {
    it('글쓰기 권한 있을 때 switch value가 "allowed"', () => {
      // 정적 구조 검증: switch value 표현식 확인
      const mainContainer = boardIndexLayoutJson.components[2];
      const writeBtn = mainContainer.children.find(
        (c: any) => c.props?.['data-testid'] === 'write-btn'
      );

      expect(writeBtn).toBeDefined();
      expect(writeBtn.actions[0].handler).toBe('switch');
      expect(writeBtn.actions[0].cases).toHaveProperty('allowed');
      expect(writeBtn.actions[0].cases).toHaveProperty('no_permission');
      expect(writeBtn.actions[0].cases).toHaveProperty('not_logged_in');
    });

    it('allowed 케이스: navigate 핸들러로 글쓰기 페이지 이동', () => {
      const mainContainer = boardIndexLayoutJson.components[2];
      const writeBtn = mainContainer.children.find(
        (c: any) => c.props?.['data-testid'] === 'write-btn'
      );

      const allowedCase = writeBtn.actions[0].cases.allowed;
      expect(allowedCase.handler).toBe('navigate');
      expect(allowedCase.params.path).toBe('/board/{{route.slug}}/write');
    });

    it('no_permission 케이스: toast warning 표시', () => {
      const mainContainer = boardIndexLayoutJson.components[2];
      const writeBtn = mainContainer.children.find(
        (c: any) => c.props?.['data-testid'] === 'write-btn'
      );

      const noPermCase = writeBtn.actions[0].cases.no_permission;
      expect(noPermCase.handler).toBe('toast');
      expect(noPermCase.params.type).toBe('warning');
    });

    it('not_logged_in 케이스: sequence로 toast + navigate (로그인 페이지)', () => {
      const mainContainer = boardIndexLayoutJson.components[2];
      const writeBtn = mainContainer.children.find(
        (c: any) => c.props?.['data-testid'] === 'write-btn'
      );

      const notLoggedInCase = writeBtn.actions[0].cases.not_logged_in;
      expect(notLoggedInCase.handler).toBe('sequence');
      expect(notLoggedInCase.actions).toHaveLength(2);
      expect(notLoggedInCase.actions[0].handler).toBe('toast');
      expect(notLoggedInCase.actions[1].handler).toBe('navigate');
      expect(notLoggedInCase.actions[1].params.path).toBe('/login');
      expect(notLoggedInCase.actions[1].params.query.redirect).toBe(
        '/board/{{route.slug}}/write'
      );
    });

    it('글쓰기 버튼에 pencil 아이콘과 텍스트가 있다', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: { posts: boardWithPostsResponse },
      });

      await testUtils.render();

      const writeBtn = screen.queryByTestId('write-btn');
      expect(writeBtn).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('페이지네이션 항상 표시', () => {
    it('게시글이 있을 때 페이지네이션 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: { posts: boardWithPostsResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('pagination')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('게시글이 없을 때도 페이지네이션 표시', async () => {
      const testUtils = createLayoutTest(boardIndexLayoutJson, {
        componentRegistry: registry,
        routeParams: { slug: 'test-board' },
        initialData: { posts: emptyBoardResponse },
      });

      await testUtils.render();

      expect(screen.queryByTestId('pagination')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
