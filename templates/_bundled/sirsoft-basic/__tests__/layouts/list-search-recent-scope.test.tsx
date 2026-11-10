/**
 * @file list-search-recent-scope.test.tsx
 * @description 상품 목록·분류·게시판 목록 검색칸이 확장 범위로 최근 검색어를 보관한다
 *
 * 이커머스는 `sirsoft-ecommerce.products`(상품 검색), 게시판은 `sirsoft-board.posts`(게시글 검색) 범위를 선언하고
 * 운영자 화면(환경설정 > 개인정보 「최근 검색어」·회원 상세 탭)도 그 범위를 보인다. 방문자 화면의 검색칸이 일반 입력칸이면
 * 두 범위를 채울 길이 없다 — 그래서 검색칸을 `RecentSearchCombobox` 로 그리고 범위를 건넨다.
 *
 * 테스트 대상 (실제 굽기 산출 `.json`):
 * - layouts/partials/shop/list/_search_filter_bar.json — 상품 목록(/shop/products) 검색줄
 * - layouts/shop/category.json — 분류 화면(/shop/category/:slug) 검색줄
 * - layouts/partials/board/types/{basic,card,gallery}/index.json — 게시판 목록 세 유형 검색칸
 *
 * 검증 항목:
 * - 범위·입력칸 이름·주소 검색어(value) — 주소로 들어온 검색어는 보이기만 하고 기록하지 않는다
 * - 종전 검색 동작 보존 — 다른 조건(분류·정렬·삭제글 보기·찾을 곳)은 그대로, 검색어만 갈아 끼우고 1쪽으로
 * - 칸을 비우고 제출하면 검색 조건이 풀린다(submitEmpty)
 * - 레이아웃은 기록 핸들러를 두지 않는다 — 검색창이 제출 때 기록(이중 기록 방지)
 * - 종전 모양(입력칸·단추 클래스, 상품 검색줄은 아이콘 없음) 유지
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  fireEvent,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';

const LAYOUTS = path.resolve(__dirname, '../../layouts');
const readJson = (rel: string) => JSON.parse(fs.readFileSync(path.join(LAYOUTS, rel), 'utf8'));

type Node = Record<string, any>;

/** 술어를 만족하는 모든 노드 */
function findAll(tree: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(tree)) tree.forEach((t) => findAll(t, pred, out));
  else if (tree && typeof tree === 'object') {
    const node = tree as Node;
    if (pred(node)) out.push(node);
    Object.values(node).forEach((v) => findAll(v, pred, out));
  }
  return out;
}

/** 모든 핸들러 이름 (중첩 sequence 포함) */
function handlersOf(tree: unknown, out: string[] = []): string[] {
  if (Array.isArray(tree)) tree.forEach((t) => handlersOf(t, out));
  else if (tree && typeof tree === 'object') {
    const node = tree as Node;
    if (typeof node.handler === 'string') out.push(node.handler);
    Object.values(node).forEach((v) => handlersOf(v, out));
  }
  return out;
}

const SHOP = [
  { surface: 'shop_list', file: 'partials/shop/list/_search_filter_bar.json', path: '{{_global.shopBase}}/products' },
  { surface: 'shop_category', file: 'shop/category.json', path: '{{route.path}}' },
] as const;

const BOARD = ['basic', 'card', 'gallery'].map((type) => ({ surface: 'board_list', type, file: `partials/board/types/${type}/index.json` }));

/** 그 파일의 최근 검색어 검색창 (하나여야 한다) */
function comboboxOf(file: string): Node {
  const found = findAll(readJson(file), (n) => n.name === 'RecentSearchCombobox');
  expect(found, file).toHaveLength(1);
  return found[0];
}

/** 검색창 대역 — 단추로 onSearch(검색어)·onSearch('') 를 부른다 */
const StubCombobox: React.FC<{ onSearch?: (keyword: string) => void; value?: string; scope?: string }> = ({ onSearch, value, scope }) => (
  <div data-testid="stub-combobox" data-scope={scope} data-value={value ?? ''}>
    <button type="button" data-testid="stub-search" onClick={() => onSearch?.('등산화 끈')}>search</button>
    <button type="button" data-testid="stub-clear" onClick={() => onSearch?.('')}>clear</button>
  </div>
);

function registry() {
  const reg = createMockComponentRegistryWithBasics();
  reg.register('composite', 'RecentSearchCombobox', StubCombobox as any);
  return reg;
}

function layoutOf(node: Node) {
  return { version: '1.0', layout_name: 'list_search_wiring', meta: { title: 't' }, components: [node] };
}

/** 마지막 이동 주소 → [경로, 쿼리] */
function lastNavigation(utils: ReturnType<typeof createLayoutTest>): [string, URLSearchParams] {
  const last = utils.getNavigationHistory().at(-1) ?? '';
  const [p, q = ''] = last.split('?');
  return [p, new URLSearchParams(q)];
}

describe('목록 검색칸 최근 검색어 — 구조', () => {
  for (const { surface, file, path: navPath } of SHOP) {
    /**
     * @scenario surface=shop_list
     * @scenario surface=shop_category
     * @effects list_search_box_uses_extension_scope, url_query_value_not_recorded, layout_has_no_duplicate_record_handler
     */
    it(`${surface}: 상품 검색칸이 sirsoft-ecommerce.products 범위의 최근 검색어 검색창이다`, () => {
      const box = comboboxOf(file);
      expect(box.props.scope).toBe('sirsoft-ecommerce.products');
      expect(box.props.name).toBe('keyword');
      expect(box.props.value).toBe("{{query.keyword ?? ''}}");
      expect(box.props.placeholder).toBe('$t:shop.search_placeholder');
      expect(box.props.buttonText).toBe('$t:shop.search');
      expect(box.props.showButton).toBe(true);
      expect(box.props.submitEmpty).toBe(true);
      // 옛 일반 입력칸·지역 사본이 남지 않는다
      expect(findAll(readJson(file), (n) => n.name === 'Input' && n.props?.name === 'keyword')).toHaveLength(0);
      expect(JSON.stringify(readJson(file))).not.toContain('_local.keyword');
      expect(handlersOf(readJson(file))).not.toContain('recordSearchKeyword');
    });

    /**
     * @scenario surface=shop_list
     * @scenario surface=shop_category
     * @effects list_search_keeps_other_conditions_and_resets_page
     */
    it(`${surface}: onSearch 는 다른 조건을 둔 채 검색어만 바꾸고 1쪽으로 간다`, () => {
      const box = comboboxOf(file);
      expect(box.actions).toHaveLength(1);
      expect(box.actions[0]).toMatchObject({
        event: 'onSearch',
        handler: 'navigate',
        params: { path: navPath, mergeQuery: true, query: { keyword: '{{$args[0]}}', page: '1' } },
      });
    });

    /**
     * @scenario surface=shop_list
     * @scenario surface=shop_category
     * @effects list_search_keeps_previous_look
     */
    it(`${surface}: 종전 모양(아이콘 없는 입력칸·검정 단추·라이트/다크 짝)을 그대로 쓴다`, () => {
      const box = comboboxOf(file);
      expect(box.props.showIcon).toBe(false);
      expect(String(box.props.inputClassName).split(/\s+/)).toEqual(
        expect.arrayContaining(['w-full', 'px-4', 'py-2', 'border-gray-300', 'dark:border-gray-600', 'rounded-lg', 'bg-white', 'dark:bg-gray-800', 'text-gray-900', 'dark:text-white']),
      );
      expect(box.props.buttonClassName).toBe('px-4 py-2 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg font-medium');
    });
  }

  for (const { type, file } of BOARD) {
    /**
     * @scenario surface=board_list
     * @effects list_search_box_uses_extension_scope, url_query_value_not_recorded, layout_has_no_duplicate_record_handler
     */
    it(`게시판 ${type} 형: 게시글 검색칸이 sirsoft-board.posts 범위의 최근 검색어 검색창이다`, () => {
      const box = comboboxOf(file);
      expect(box.props.scope).toBe('sirsoft-board.posts');
      expect(box.props.name).toBe('search');
      expect(box.props.value).toBe("{{query.search || ''}}");
      expect(box.props.placeholder).toBe('$t:board.search_placeholder');
      // 종전처럼 단추 없이 Enter 로 제출한다(기본값 false)
      expect(box.props.showButton ?? false).toBe(false);
      expect(box.props.submitEmpty).toBe(true);
      // 종전 검색 입력칸(type=search)의 × 지우기 단추 — 글자와 겹치지 않게 오른쪽 여백을 단추 폭만큼 둔다
      expect(box.props.showClear).toBe(true);
      expect(String(box.props.inputClassName).split(/\s+/)).toContain('pr-11');
      // 종전 폭(모바일 한 줄 꽉 참, 태블릿 이상 w-64)
      expect(box.props.className).toBe('w-full sm:w-64');
      expect(findAll(readJson(file), (n) => n.name === 'SearchBar')).toHaveLength(0);
      expect(handlersOf(readJson(file))).not.toContain('recordSearchKeyword');
    });

    /**
     * @scenario surface=board_list
     * @effects list_search_keeps_other_conditions_and_resets_page
     */
    it(`게시판 ${type} 형: onSearch 는 찾을 곳을 함께 싣고 다른 조건을 둔 채 1쪽으로 간다`, () => {
      const box = comboboxOf(file);
      expect(box.actions).toHaveLength(1);
      expect(box.actions[0]).toMatchObject({
        event: 'onSearch',
        handler: 'navigate',
        params: {
          path: '/board/{{route.slug}}',
          mergeQuery: true,
          query: { search: '{{$args[0]}}', search_field: "{{_local.boardSearchField ?? query.search_field ?? 'title_content'}}", page: 1 },
        },
      });
    });
  }
});

describe('목록 검색칸 최근 검색어 — 엔진 액션 실행', () => {
  let utils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    utils?.cleanup();
    utils = null;
    window.history.pushState({}, '', '/');
  });

  /**
   * @scenario surface=shop_list
   * @effects list_search_keeps_other_conditions_and_resets_page, empty_submit_clears_list_filter
   */
  it('상품 목록: 분류·정렬을 두고 검색어만 바꿔 1쪽으로, 빈 제출은 검색어를 푼다', async () => {
    window.history.pushState({}, '', '/shop/products?category=3&sort=price_asc&page=4&keyword=%EC%B9%A8%EB%82%AD');
    utils = createLayoutTest(layoutOf(comboboxOf(SHOP[0].file)), {
      translations: {},
      locale: 'ko',
      componentRegistry: registry(),
      queryParams: { category: '3', sort: 'price_asc', page: '4', keyword: '침낭' },
    } as any);
    utils.setState('shopBase', '/shop', 'global');
    await utils.render();

    const stub = screen.getByTestId('stub-combobox');
    expect(stub.getAttribute('data-scope')).toBe('sirsoft-ecommerce.products');
    expect(stub.getAttribute('data-value')).toBe('침낭');

    fireEvent.click(screen.getByTestId('stub-search'));
    await new Promise((r) => setTimeout(r, 0));
    let [p, q] = lastNavigation(utils);
    expect(p).toBe('/shop/products');
    expect(Object.fromEntries(q)).toEqual({ category: '3', sort: 'price_asc', page: '1', keyword: '등산화 끈' });

    fireEvent.click(screen.getByTestId('stub-clear'));
    await new Promise((r) => setTimeout(r, 0));
    [p, q] = lastNavigation(utils);
    expect(p).toBe('/shop/products');
    expect(Object.fromEntries(q)).toEqual({ category: '3', sort: 'price_asc', page: '1' });
  });

  /**
   * @scenario surface=shop_category
   * @effects list_search_keeps_other_conditions_and_resets_page, empty_submit_clears_list_filter
   */
  it('분류 화면: 그 분류 주소에 남아 정렬을 두고 검색어만 바꿔 1쪽으로, 빈 제출은 검색어를 푼다', async () => {
    window.history.pushState({}, '', '/shop/category/clothing?sort=sales&page=2');
    utils = createLayoutTest(layoutOf(comboboxOf(SHOP[1].file)), {
      translations: {},
      locale: 'ko',
      componentRegistry: registry(),
      routePath: '/shop/category/clothing',
      routeParams: { slug: 'clothing' },
      queryParams: { sort: 'sales', page: '2' },
    } as any);
    await utils.render();

    fireEvent.click(screen.getByTestId('stub-search'));
    await new Promise((r) => setTimeout(r, 0));
    let [p, q] = lastNavigation(utils);
    expect(p).toBe('/shop/category/clothing');
    expect(Object.fromEntries(q)).toEqual({ sort: 'sales', page: '1', keyword: '등산화 끈' });

    window.history.pushState({}, '', '/shop/category/clothing?sort=sales&page=1&keyword=x');
    fireEvent.click(screen.getByTestId('stub-clear'));
    await new Promise((r) => setTimeout(r, 0));
    [p, q] = lastNavigation(utils);
    expect(p).toBe('/shop/category/clothing');
    expect(Object.fromEntries(q)).toEqual({ sort: 'sales', page: '1' });
  });

  /**
   * @scenario surface=board_list
   * @effects list_search_keeps_other_conditions_and_resets_page, empty_submit_clears_list_filter
   */
  it('게시판 목록: 분류·삭제글 보기·찾을 곳을 두고 검색어만 바꿔 1쪽으로, 빈 제출은 검색어를 푼다', async () => {
    window.history.pushState({}, '', '/board/free?category=notice&del=1&search_field=author&page=3&search=old');
    utils = createLayoutTest(layoutOf(comboboxOf(BOARD[0].file)), {
      translations: {},
      locale: 'ko',
      componentRegistry: registry(),
      routePath: '/board/free',
      routeParams: { slug: 'free' },
      queryParams: { category: 'notice', del: '1', search_field: 'author', page: '3', search: 'old' },
    } as any);
    await utils.render();

    const stub = screen.getByTestId('stub-combobox');
    expect(stub.getAttribute('data-scope')).toBe('sirsoft-board.posts');
    expect(stub.getAttribute('data-value')).toBe('old');

    fireEvent.click(screen.getByTestId('stub-search'));
    await new Promise((r) => setTimeout(r, 0));
    let [p, q] = lastNavigation(utils);
    expect(p).toBe('/board/free');
    expect(Object.fromEntries(q)).toEqual({ category: 'notice', del: '1', search_field: 'author', page: '1', search: '등산화 끈' });

    fireEvent.click(screen.getByTestId('stub-clear'));
    await new Promise((r) => setTimeout(r, 0));
    [p, q] = lastNavigation(utils);
    expect(Object.fromEntries(q)).toEqual({ category: 'notice', del: '1', search_field: 'author', page: '1' });
  });
});
