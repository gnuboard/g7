/**
 * @file user-base-mobile-search.test.tsx
 * @description 최근 검색어 검색창 연결 — 모바일 서랍·통합검색 화면·데스크톱 머리글
 *
 * 테스트 대상 (실제 굽기 산출 `.json`):
 * - layouts/_user_base.json — 모바일 서랍 검색(`mobile_drawer_search_box`)과 머리글 Header 의 `searchHistoryScope`
 * - layouts/partials/search/_search_input.json — 통합검색 화면 검색줄(`search_page_input`)
 *
 * 검증 항목:
 * - 세 검색창이 같은 범위(core.site)라 목록을 공유한다
 * - 서랍: onSearch → 서랍 닫기 → /search?q={검색어} (실제 엔진 액션 경로로 실행)
 * - 검색 화면: 주소 q 를 value 로 보이기만 하고, onSearch → /search?q={검색어} (기록은 검색창이 제출 때만)
 * - 레이아웃은 기록 핸들러(recordSearchKeyword)를 따로 두지 않는다 — 검색창이 기다리지 않고 기록한다(이중 기록 방지)
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

/** id 또는 name 으로 노드를 찾는다 */
function find(tree: unknown, pred: (n: Node) => boolean): Node | null {
  if (Array.isArray(tree)) {
    for (const item of tree) {
      const hit = find(item, pred);
      if (hit) return hit;
    }
    return null;
  }
  if (!tree || typeof tree !== 'object') return null;
  const node = tree as Node;
  if (pred(node)) return node;
  for (const value of Object.values(node)) {
    const hit = find(value, pred);
    if (hit) return hit;
  }
  return null;
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

const userBase = readJson('_user_base.json');
const searchInput = readJson('partials/search/_search_input.json');
const drawerBox = find(userBase, (n) => n.id === 'mobile_drawer_search_box')!;
const pageBox = find(searchInput, (n) => n.id === 'search_page_input')!;
const header = find(userBase, (n) => n.id === 'desktop_header')!;

/** 검색창 대역 — 누르면 onSearch(검색어) 를 부른다 */
const StubCombobox: React.FC<{ onSearch?: (keyword: string) => void; value?: string; scope?: string }> = ({ onSearch, value, scope }) => (
  <button type="button" data-testid="stub-combobox" data-scope={scope} data-value={value ?? ''} onClick={() => onSearch?.('등산화 끈')}>
    search
  </button>
);

function registry() {
  const reg = createMockComponentRegistryWithBasics();
  reg.register('composite', 'RecentSearchCombobox', StubCombobox as any);
  return reg;
}

function layoutOf(node: Node) {
  return { version: '1.0', layout_name: 'recent_search_wiring', meta: { title: 't' }, components: [node] };
}

describe('최근 검색어 검색창 연결 — 구조', () => {
  /**
   * @scenario surface=mobile_drawer
   * @effects same_scope_boxes_share_list
   */
  it('세 검색창이 같은 범위 core.site 를 쓴다(목록 공유)', () => {
    expect(drawerBox).toBeTruthy();
    expect(pageBox).toBeTruthy();
    expect(drawerBox.name).toBe('RecentSearchCombobox');
    expect(pageBox.name).toBe('RecentSearchCombobox');
    expect(drawerBox.props.scope).toBe('core.site');
    expect(pageBox.props.scope).toBe('core.site');
    expect(header.props.searchHistoryScope).toBe('core.site');
  });

  /**
   * @scenario surface=mobile_drawer
   * @effects drawer_closes_then_navigates_search, touch_targets_44px_and_dark_pairs
   */
  it('서랍 검색창은 onSearch → 서랍 닫기 → /search?q=$args[0] 이다', () => {
    const action = drawerBox.actions[0];
    expect(action.event).toBe('onSearch');
    expect(action.handler).toBe('sequence');
    expect(action.actions[0]).toEqual({ handler: 'setState', params: { target: 'global', mobileMenuOpen: false } });
    expect(action.actions[1]).toEqual({ handler: 'navigate', params: { path: '/search', query: { q: '{{$args[0]}}' } } });
    // 모바일 누름 영역 44px
    expect(drawerBox.props.inputClassName).toContain('min-h-[44px]');
  });

  /**
   * @scenario surface=mobile_drawer
   * @effects search_box_follows_result_url
   */
  it('서랍 검색창은 통합검색 화면(/search)을 결과 화면으로 두고 주소를 따라간다 — 검색 뒤 다른 화면에 검색어가 남지 않는다', () => {
    expect(drawerBox.props.resultPath).toBe('/search');
    expect(drawerBox.props.name).toBe('q');
    // 통합검색 화면 검색줄은 화면이 다시 그리므로 value 로 받는다(주소 따라가기 불필요)
    expect(pageBox.props.resultPath).toBeUndefined();
  });

  /**
   * @scenario surface=search_page
   * @effects search_page_shows_query_and_navigates, url_query_value_not_recorded
   */
  it('검색 화면은 주소 q 를 value 로 보이고 onSearch 로만 이동한다(옛 _local.q 사본 제거)', () => {
    expect(pageBox.props.value).toBe("{{query?.q ?? ''}}");
    expect(pageBox.props.showButton).toBe(true);
    expect(pageBox.actions).toHaveLength(1);
    expect(pageBox.actions[0]).toMatchObject({ event: 'onSearch', handler: 'navigate', params: { path: '/search', query: { q: '{{$args[0]}}' } } });
    expect(JSON.stringify(searchInput)).not.toContain('_local.q');
  });

  /**
   * @scenario surface=mobile_drawer
   * @effects layout_has_no_duplicate_record_handler
   */
  it('레이아웃은 기록 핸들러를 두지 않는다 — 검색창이 제출 때 기록(이중 기록 방지)', () => {
    expect(handlersOf(drawerBox)).not.toContain('recordSearchKeyword');
    expect(handlersOf(searchInput)).not.toContain('recordSearchKeyword');
    expect(handlersOf(userBase)).not.toContain('recordSearchKeyword');
  });
});

describe('최근 검색어 검색창 연결 — 엔진 액션 실행', () => {
  let utils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    utils?.cleanup();
    utils = null;
  });

  /**
   * @scenario surface=mobile_drawer
   * @effects drawer_closes_then_navigates_search
   */
  it('서랍 검색창에서 검색하면 서랍이 닫히고 통합검색으로 이동한다', async () => {
    utils = createLayoutTest(layoutOf(drawerBox), { translations: {}, locale: 'ko', componentRegistry: registry() } as any);
    utils.setState('mobileMenuOpen', true, 'global');
    await utils.render();

    fireEvent.click(screen.getByTestId('stub-combobox'));
    await new Promise((r) => setTimeout(r, 0));

    expect(utils.getState()._global.mobileMenuOpen).toBe(false);
    expect(utils.getNavigationHistory().join(' ')).toContain('/search');
    expect(decodeURIComponent(utils.getNavigationHistory().join(' ')).replace(/\+/g, ' ')).toContain('q=등산화 끈');
  });

  /**
   * @scenario surface=search_page
   * @effects search_page_shows_query_and_navigates
   */
  it('검색 화면 검색창은 주소의 q 를 받고, 검색하면 통합검색으로 이동한다', async () => {
    utils = createLayoutTest(layoutOf(pageBox), {
      translations: {},
      locale: 'ko',
      componentRegistry: registry(),
      initialData: { query: { q: '침낭' } } as any,
    } as any);
    await utils.render();

    const stub = screen.getByTestId('stub-combobox');
    expect(stub.getAttribute('data-scope')).toBe('core.site');

    fireEvent.click(stub);
    await new Promise((r) => setTimeout(r, 0));
    expect(decodeURIComponent(utils.getNavigationHistory().join(' ')).replace(/\+/g, ' ')).toContain('/search?q=등산화 끈');
  });
});
