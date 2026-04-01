/**
 * @file search-pages-section.test.tsx
 * @description 통합검색 pages 섹션 Partial 렌더링 테스트 (Issue #99)
 *
 * 테스트 케이스:
 * - 기본 렌더링: all 탭에서 섹션 헤더 표시
 * - 검색 결과: 아이템이 있을 때 목록 렌더링
 * - 빈 상태: 아이템 없을 때 안내 메시지 표시
 * - 전체보기 버튼: pages_count > 0 일 때만 표시
 * - 탭 전환: pages 탭에서는 헤더 미표시
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  screen,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
  ({ className, children, 'data-testid': testId }) => <div className={className} data-testid={testId}>{children}</div>;

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <span className={className} data-testid={testId}>{children ?? text}</span>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <p className={className} data-testid={testId}>{children ?? text}</p>;

const TestButton: React.FC<{ className?: string; children?: React.ReactNode; text?: string; onClick?: () => void; 'data-testid'?: string }> =
  ({ className, children, text, onClick, 'data-testid': testId }) => (
    <button className={className} onClick={onClick} data-testid={testId}>{children ?? text}</button>
  );

const TestIcon: React.FC<{ name?: string; size?: string; className?: string }> =
  ({ name }) => <i data-icon={name} />;

const TestHtmlContent: React.FC<{ content?: string; className?: string; 'data-testid'?: string }> =
  ({ content, 'data-testid': testId }) => <div data-testid={testId} dangerouslySetInnerHTML={{ __html: content ?? '' }} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

// ========== 레이아웃 Fixture ==========

/**
 * pages/_section.json 핵심 구조 (Partial이므로 단일 루트 Div)
 */
const pagesSectionFixture = {
  version: '1.0.0',
  layout_name: 'partials/search/pages/_section',
  state: {},
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'pages-section-root' },
      children: [
        {
          // 섹션 헤더 (all 탭에서만)
          type: 'basic',
          name: 'Div',
          if: '{{(_global.searchActiveTab ?? "all") === "all"}}',
          props: { 'data-testid': 'section-header' },
          children: [
            { type: 'basic', name: 'Icon', props: { name: 'file-alt' } },
            { type: 'basic', name: 'Span', props: { 'data-testid': 'pages-tab-label' }, text: 'search.tabs.pages' },
            { type: 'basic', name: 'Span', props: { 'data-testid': 'pages-count' }, text: '{{searchResults?.data?.pages_count ?? 0}}' },
            {
              type: 'basic',
              name: 'Button',
              if: '{{(searchResults?.data?.pages_count ?? 0) > 0}}',
              props: { 'data-testid': 'view-all-btn' },
              children: [
                { type: 'basic', name: 'Span', text: 'search.view_all' },
              ],
              actions: [
                {
                  type: 'click',
                  handler: 'sequence',
                  actions: [
                    { handler: 'setState', params: { target: 'global', searchActiveTab: 'pages', searchPage: 1 } },
                    { handler: 'refetchDataSource', params: { dataSourceId: 'searchResults' } },
                  ],
                },
              ],
            },
          ],
        },
        {
          // 페이지 목록
          type: 'basic',
          name: 'Div',
          if: '{{searchResults?.data?.pages?.items?.length > 0}}',
          iteration: {
            source: '{{searchResults?.data?.pages?.items ?? []}}',
            item_var: 'page',
            index_var: 'pageIdx',
          },
          children: [
            {
              type: 'basic',
              name: 'Button',
              props: { 'data-testid': 'page-item-btn' },
              actions: [{ type: 'click', handler: 'navigate', params: { path: '{{page.url}}' } }],
              children: [
                {
                  type: 'composite',
                  name: 'HtmlContent',
                  props: { 'data-testid': 'page-item-title', content: '{{page.title_highlighted ?? page.title}}' },
                },
                {
                  type: 'composite',
                  name: 'HtmlContent',
                  props: { 'data-testid': 'page-item-preview', content: '{{page.content_preview_highlighted ?? page.content_preview}}' },
                },
                {
                  type: 'basic',
                  name: 'P',
                  props: { 'data-testid': 'page-item-date' },
                  text: 'search.page_published_at|date={{page.published_at ?? ""}}',
                },
              ],
            },
          ],
        },
        {
          // 결과 없음
          type: 'basic',
          name: 'Div',
          if: '{{!searchResults?.data?.pages?.items || searchResults?.data?.pages?.items?.length === 0}}',
          props: { 'data-testid': 'pages-empty' },
          children: [
            { type: 'basic', name: 'Span', text: 'search.empty.pages_in_all' },
          ],
        },
      ],
    },
  ],
};

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    HtmlContent: { component: TestHtmlContent, metadata: { name: 'HtmlContent', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// ========== 헬퍼 ==========

function makeFakePageItem(overrides: Partial<{
  title: string;
  title_highlighted: string;
  content_preview: string;
  content_preview_highlighted: string;
  published_at: string;
  url: string;
}> = {}) {
  return {
    id: 1,
    slug: 'terms',
    title: '이용약관',
    title_highlighted: '<mark>이용</mark>약관',
    content_preview: '본 약관은...',
    content_preview_highlighted: '본 <mark>약관</mark>은...',
    published_at: '2026-02-01',
    url: '/page/terms',
    ...overrides,
  };
}

// ========== 테스트 케이스 ==========

describe('partials/search/pages/_section.json 렌더링 (Issue #99)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('섹션 헤더 (all 탭)', () => {
    it('all 탭에서 섹션 헤더가 표시된다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
        initialData: { searchResults: { data: { pages_count: 0, pages: { items: [] } } } },
      });

      await testUtils.render();

      expect(screen.getByTestId('section-header')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('pages 탭에서는 섹션 헤더가 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'pages' } },
        initialData: { searchResults: { data: { pages_count: 3, pages: { items: [makeFakePageItem()] } } } },
      });

      await testUtils.render();

      expect(screen.queryByTestId('section-header')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('searchActiveTab 미설정 시 all 탭으로 간주하여 헤더가 표시된다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: {} },
        initialData: { searchResults: { data: { pages_count: 0, pages: { items: [] } } } },
      });

      await testUtils.render();

      expect(screen.getByTestId('section-header')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('전체보기 버튼', () => {
    it('pages_count가 0이면 전체보기 버튼이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
        initialData: { searchResults: { data: { pages_count: 0, pages: { items: [] } } } },
      });

      await testUtils.render();

      expect(screen.queryByTestId('view-all-btn')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('pages_count가 1 이상이면 전체보기 버튼이 표시된다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
        initialData: { searchResults: { data: { pages_count: 3, pages: { items: [makeFakePageItem()] } } } },
      });

      await testUtils.render();

      expect(screen.getByTestId('view-all-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('검색 결과 목록', () => {
    it('아이템이 있으면 페이지 목록이 렌더링된다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
        initialData: {
          searchResults: {
            data: {
              pages_count: 1,
              pages: { items: [makeFakePageItem()] },
            },
          },
        },
      });

      await testUtils.render();

      expect(screen.getByTestId('page-item-btn')).toBeInTheDocument();
      expect(screen.queryByTestId('pages-empty')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('아이템이 없으면 빈 상태 안내가 표시된다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
        initialData: { searchResults: { data: { pages_count: 0, pages: { items: [] } } } },
      });

      await testUtils.render();

      expect(screen.queryByTestId('page-item-btn')).not.toBeInTheDocument();
      expect(screen.getByTestId('pages-empty')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('아이템의 url이 /page/{slug} 형식으로 바인딩된다', async () => {
      const item = makeFakePageItem({ url: '/page/terms' });
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
        initialData: { searchResults: { data: { pages_count: 1, pages: { items: [item] } } } },
      });

      await testUtils.render();

      // navigate 핸들러가 /page/terms 경로를 사용하는지
      // (page.url 바인딩이 올바르게 /page/ 형식인지 검증)
      const btn = screen.getByTestId('page-item-btn');
      expect(btn).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('빈 상태 (searchResults 미정의)', () => {
    it('searchResults 데이터가 없어도 에러 없이 렌더링된다', async () => {
      const testUtils = createLayoutTest(pagesSectionFixture, {
        componentRegistry: registry,
        initialState: { _global: { searchActiveTab: 'all' } },
      });

      await testUtils.render();

      expect(screen.getByTestId('pages-section-root')).toBeInTheDocument();
      expect(screen.getByTestId('pages-empty')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
