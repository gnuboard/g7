/**
 * @file search-failed-state.test.tsx
 * @description 통합검색 실패 표면화 렌더링 테스트 (공개 이슈 #103)
 *
 * 카테고리 검색이 예외로 실패하면(`categories_failed` / `search_failed`) 화면은
 * "검색 결과가 없습니다" 가 아니라 "검색 중 오류" 안내를 그려야 한다.
 *
 * 실제 레이아웃 JSON 을 import 해서 렌더한다 — 손으로 쓴 조각만 검증하면 실파일이
 * 규약을 어겨도 green 인 채로 유출된다. 부재 단언은 baseline 케이스에서 그 문구의
 * 존재를 먼저 확정한 뒤에만 의미를 갖는다.
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

// 실제 레이아웃 JSON — failed 분기 회귀 고정
import searchResultsPartial from '../../../layouts/partials/search/_search_results.json';
import searchStatesPartial from '../../../layouts/partials/search/_search_states.json';
import postsSectionPartial from '../../../layouts/partials/search/posts/_section.json';
import productsSectionPartial from '../../../layouts/partials/search/products/_section.json';
import pagesSectionPartial from '../../../layouts/partials/search/pages/_section.json';

// 실제 ko 언어 파일 — 문구 단언이 실키와 어긋나면 red
import koSearch from '../../../lang/partial/ko/search.json';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <div className={className}>{children}</div>
);
const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ children, text }) => (
  <span>{children ?? text}</span>
);
const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ children, text }) => (
  <p>{children ?? text}</p>
);
const TestH3: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ children, text }) => (
  <h3>{children ?? text}</h3>
);
const TestButton: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ children, text }) => (
  <button type="button">{children ?? text}</button>
);
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;
const TestHtmlContent: React.FC<{ content?: string }> = ({ content }) => (
  <div dangerouslySetInnerHTML={{ __html: content ?? '' }} />
);
const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    H4: { component: TestH3, metadata: { name: 'H4', type: 'basic' } },
    Ul: { component: TestDiv, metadata: { name: 'Ul', type: 'basic' } },
    Li: { component: TestDiv, metadata: { name: 'Li', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    HtmlContent: { component: TestHtmlContent, metadata: { name: 'HtmlContent', type: 'composite' } },
  };
  return registry;
}

// ========== partial 참조 인라인 해석 ==========

/**
 * 서버측 partial 해석을 테스트에서 재현합니다.
 *
 * `{ "partial": "..." }` 노드를 매핑된 실제 JSON 으로 치환하고, 매핑이 없는 참조는
 * 빈 Div 로 둡니다(이 테스트의 단언 대상 분기가 아니라는 뜻).
 *
 * @param node 레이아웃 JSON 노드
 * @param map partial 경로 => 실제 JSON
 * @returns 치환된 사본
 */
function resolvePartials(node: unknown, map: Record<string, unknown>): unknown {
  if (Array.isArray(node)) {
    return node.map((n) => resolvePartials(n, map));
  }
  if (!node || typeof node !== 'object') return node;
  const obj = node as Record<string, unknown>;
  if (typeof obj.partial === 'string') {
    const target = map[obj.partial];
    return target
      ? resolvePartials(JSON.parse(JSON.stringify(target)), map)
      : { type: 'basic', name: 'Div' };
  }
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(obj)) {
    out[key] = resolvePartials(value, map);
  }
  return out;
}

const PARTIAL_MAP: Record<string, unknown> = {
  'partials/search/posts/_section.json': postsSectionPartial,
  'partials/search/products/_section.json': productsSectionPartial,
  'partials/search/pages/_section.json': pagesSectionPartial,
};

/**
 * 실제 partial JSON 을 렌더 가능한 레이아웃으로 감쌉니다.
 *
 * @param partial 레이아웃 partial JSON
 * @returns 레이아웃 정의
 */
function wrapLayout(partial: unknown) {
  return {
    version: '1.0.0',
    layout_name: 'search_failed_probe',
    state: {},
    components: [resolvePartials(JSON.parse(JSON.stringify(partial)), PARTIAL_MAP)],
  };
}

// ========== 데이터 헬퍼 ==========

const FAILED_TITLE = (koSearch as any).failed?.title ?? '$t:search.failed.title';

/**
 * 검색 응답 데이터를 만듭니다.
 *
 * @param overrides 덮어쓸 필드
 * @returns searchResults 데이터소스 값
 */
function searchData(overrides: Record<string, unknown> = {}) {
  return {
    searchResults: {
      data: {
        q: '문의',
        total: 0,
        posts_count: 0,
        products_count: 0,
        pages_count: 0,
        posts: { items: [] },
        products: [],
        pages: { items: [] },
        categories_failed: { posts: false, products: false, pages: false },
        search_failed: false,
        ...overrides,
      },
    },
  };
}

const RENDER_OPTIONS = (queryParams: Record<string, string>, data: Record<string, unknown>) => ({
  componentRegistry: ComponentRegistry.getInstance(),
  queryParams,
  initialData: data,
  translations: { search: koSearch },
});

// ========== 테스트 케이스 ==========

describe('통합검색 실패 표면화 렌더링 (#103)', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  describe('_search_results.json 카테고리 탭 분기', () => {
    const tabCases: Array<[string, string, string]> = [
      ['posts', 'posts', (koSearch as any).empty.posts],
      ['products', 'products', (koSearch as any).empty.products],
      ['pages', 'pages', (koSearch as any).empty.pages],
    ];

    it.each(tabCases)(
      '%s 탭: 실패가 아니면 기존 "결과 없음" 문구가 렌더된다 (baseline)',
      async (_label, type, emptyText) => {
        const testUtils = createLayoutTest(wrapLayout(searchResultsPartial), RENDER_OPTIONS(
          { q: '문의', type },
          searchData()
        ));

        await testUtils.render();

        // 존재 확정 — 이 문구가 없으면 아래 실패 케이스의 부재 단언이 무의미하다
        expect(screen.getByText(emptyText)).toBeInTheDocument();
        expect(screen.queryByText(FAILED_TITLE)).not.toBeInTheDocument();

        testUtils.cleanup();
      }
    );

    /**
     * @scenario category=posts, scope=category_tab, failure=single
     * @effects error_notice_instead_of_empty
     */
    /**
     * @scenario category=products, scope=category_tab, failure=single
     * @effects error_notice_instead_of_empty
     */
    /**
     * @scenario category=pages, scope=category_tab, failure=single
     * @effects error_notice_instead_of_empty
     */
    it.each(tabCases)(
      '%s 탭: 카테고리 실패 시 "결과 없음" 대신 오류 안내가 렌더된다',
      async (_label, type, emptyText) => {
        const testUtils = createLayoutTest(wrapLayout(searchResultsPartial), RENDER_OPTIONS(
          { q: '문의', type },
          searchData({
            categories_failed: { posts: false, products: false, pages: false, [type]: true },
            search_failed: true,
          })
        ));

        await testUtils.render();

        expect(screen.getByText(FAILED_TITLE)).toBeInTheDocument();
        expect(screen.queryByText(emptyText)).not.toBeInTheDocument();

        testUtils.cleanup();
      }
    );

    /**
     * @scenario category=posts, scope=category_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    /**
     * @scenario category=products, scope=category_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    /**
     * @scenario category=pages, scope=category_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    it.each(tabCases)(
      '%s 탭: 전 카테고리 실패 시에도 그 탭이 오류 안내를 렌더한다',
      async (_label, type, emptyText) => {
        const testUtils = createLayoutTest(wrapLayout(searchResultsPartial), RENDER_OPTIONS(
          { q: '문의', type },
          searchData({
            categories_failed: { posts: true, products: true, pages: true },
            search_failed: true,
          })
        ));

        await testUtils.render();

        expect(screen.getByText(FAILED_TITLE)).toBeInTheDocument();
        expect(screen.queryByText(emptyText)).not.toBeInTheDocument();

        testUtils.cleanup();
      }
    );
  });

  describe('전체 탭 섹션 (partial 인라인 해석)', () => {
    const sectionCases: Array<[string, string, string]> = [
      // [실패 카테고리, 그 카테고리의 in_all 빈 문구 키, 정상 렌더를 확인할 다른 카테고리의 빈 문구 키]
      ['posts', 'posts_in_all', 'pages_in_all'],
      ['products', 'products_in_all', 'pages_in_all'],
      ['pages', 'pages_in_all', 'posts_in_all'],
    ];

    /**
     * @scenario category=posts, scope=all_tab, failure=single
     * @effects error_notice_instead_of_empty, unaffected_category_renders_normally
     */
    /**
     * @scenario category=products, scope=all_tab, failure=single
     * @effects error_notice_instead_of_empty, unaffected_category_renders_normally
     */
    /**
     * @scenario category=pages, scope=all_tab, failure=single
     * @effects error_notice_instead_of_empty, unaffected_category_renders_normally
     */
    it.each(sectionCases)(
      '%s 섹션만 실패하면 그 섹션만 오류 안내를 그리고 다른 섹션은 정상 렌더된다',
      async (failedCategory, failedEmptyKey, normalEmptyKey) => {
        const testUtils = createLayoutTest(wrapLayout(searchResultsPartial), RENDER_OPTIONS(
          { q: '문의', type: 'all' },
          searchData({
            total: 3,
            products_count: failedCategory === 'products' ? 0 : 3,
            categories_failed: { posts: false, products: false, pages: false, [failedCategory]: true },
            search_failed: true,
          })
        ));

        await testUtils.render();

        // 실패 섹션: 오류 안내 (in_all 빈 문구 대신)
        expect(screen.getByText(FAILED_TITLE)).toBeInTheDocument();
        expect(screen.queryByText((koSearch as any).empty[failedEmptyKey])).not.toBeInTheDocument();
        // 실패하지 않은 섹션은 종전대로 빈 문구를 그린다
        expect(screen.getByText((koSearch as any).empty[normalEmptyKey])).toBeInTheDocument();

        testUtils.cleanup();
      }
    );

    it('실패가 없으면 posts 섹션은 종전대로 빈 문구를 그린다 (baseline)', async () => {
      const testUtils = createLayoutTest(wrapLayout(searchResultsPartial), RENDER_OPTIONS(
        { q: '문의', type: 'all' },
        searchData({ total: 3, products_count: 3 })
      ));

      await testUtils.render();

      expect(screen.getByText((koSearch as any).empty.posts_in_all)).toBeInTheDocument();
      expect(screen.queryByText(FAILED_TITLE)).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('_search_states.json 전체 탭', () => {
    it('전체 탭 0건 + 실패 없음이면 "검색 결과가 없습니다" 를 그린다 (baseline)', async () => {
      const testUtils = createLayoutTest(wrapLayout(searchStatesPartial), RENDER_OPTIONS(
        { q: '문의', type: 'all' },
        searchData()
      ));

      await testUtils.render();

      expect(screen.getByText((koSearch as any).empty.all)).toBeInTheDocument();
      expect(screen.queryByText(FAILED_TITLE)).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    /**
     * @scenario category=posts, scope=all_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    /**
     * @scenario category=products, scope=all_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    /**
     * @scenario category=pages, scope=all_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    it('전체 탭 0건 + 실패 존재면 "결과 없음" 대신 오류 안내를 그린다', async () => {
      const testUtils = createLayoutTest(wrapLayout(searchStatesPartial), RENDER_OPTIONS(
        { q: '문의', type: 'all' },
        searchData({
          categories_failed: { posts: true, products: true, pages: true },
          search_failed: true,
        })
      ));

      await testUtils.render();

      expect(screen.getByText(FAILED_TITLE)).toBeInTheDocument();
      expect(screen.queryByText((koSearch as any).empty.all)).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    /**
     * 실패 페이로드는 "정확한 0건" 이라고 말하지 않으려고 total_is_exact=false 를 싣는다.
     * 그런데 그 값은 원래 "총 건수 상한 초과" 신호이기도 해서, 가드가 없으면 실패 화면에
     * "검색어를 더 구체적으로 입력하면 정확한 건수를 볼 수 있습니다" 라는 상한 초과용
     * 조치 안내가 오류 안내와 나란히 렌더된다 — 검색어를 좁혀도 서버 오류는 해소되지 않으므로
     * 사용자에게 잘못된 조치를 지시하게 된다.
     *
     * @scenario category=posts, scope=all_tab, failure=all
     * @effects error_notice_instead_of_empty
     */
    it('전체 탭 실패 시 상한 초과용 "검색어를 좁히세요" 안내를 그리지 않는다', async () => {
      const refineHint = (koSearch as any).refine_query_hint;
      expect(refineHint).toBeTruthy();

      // 존재 확정: 실패가 아닌 상한 초과 상황에서는 그 안내가 실제로 렌더된다.
      const exceeded = createLayoutTest(wrapLayout(searchStatesPartial), RENDER_OPTIONS(
        { q: '문의', type: 'all' },
        searchData({ total: 10000, total_is_exact: false })
      ));
      await exceeded.render();
      expect(screen.getByText(refineHint)).toBeInTheDocument();
      exceeded.cleanup();

      // 실패 상황에서는 같은 안내가 사라져야 한다.
      const failed = createLayoutTest(wrapLayout(searchStatesPartial), RENDER_OPTIONS(
        { q: '문의', type: 'all' },
        searchData({
          total_is_exact: false,
          categories_failed: { posts: true, products: true, pages: true },
          search_failed: true,
        })
      ));
      await failed.render();

      expect(screen.getByText(FAILED_TITLE)).toBeInTheDocument();
      expect(screen.queryByText(refineHint)).not.toBeInTheDocument();

      failed.cleanup();
    });

    it('실패 키가 없는 구버전 응답에서는 종전 렌더가 유지된다 (하위호환)', async () => {
      const data = searchData();
      delete (data.searchResults as any).data.categories_failed;
      delete (data.searchResults as any).data.search_failed;

      const testUtils = createLayoutTest(wrapLayout(searchStatesPartial), RENDER_OPTIONS(
        { q: '문의', type: 'all' },
        data
      ));

      await testUtils.render();

      expect(screen.getByText((koSearch as any).empty.all)).toBeInTheDocument();
      expect(screen.queryByText(FAILED_TITLE)).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
