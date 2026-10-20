/**
 * @file admin-list-loading-empty-state.test.tsx
 * @description 관리자 게시판 목록 표 — 목록을 받는 동안에는 「없음」 문구를 보이지 않는다
 *
 * 목록 데이터소스는 화면이 먼저 그려진 뒤에 받는다(progressive). 받기 전에는 데이터소스 값이
 * `undefined` 라서 표의 행이 0개로 보이고, 표는 행이 0개이면 빈 문구를 그린다. 그래서 글 상세에서
 * 「목록」으로 돌아온 직후처럼 목록을 아직 받지 못한 순간에 「데이터가 없습니다」가 흐릿하게
 * 먼저 떴다가, 받고 나서야 행이 들어왔다 — 데이터가 있는데도 없다고 말하는 것이다.
 *
 * 각 화면의 실제 레이아웃 JSON 에서 표 노드를 꺼내 하네스로 렌더한다:
 *
 *  1. 받는 중(값이 `undefined`) — 빈 문구가 없고, 표가 흐림 처리된다.
 *  2. 받은 뒤 0건 — 빈 문구가 보이고 흐림이 풀린다.
 *  3. 받은 뒤 1건 이상 — 행이 그려지고 빈 문구가 없다.
 *  3-1. 처음 받기에 실패(엔진이 값을 `null` 로 둔다) — 이 표들의 데이터소스는 일부 상태 코드만 본문 오류 화면
 *       (`showErrorPage`)으로 보내고(게시글 403·404·500, 반응·신고·스크랩 403) 기본 오류 처리·대체값이 없다.
 *       그 밖의 실패(게시글은 502·503·네트워크 오류 등, 나머지는 500 포함)에 「데이터가 없습니다」를 보이면 거짓 안내라,
 *       실패 문구를 그린다. 오류 화면으로 가는 코드에서는 이 표 자체가 화면에 남지 않는다.
 *  4. 게시판 목록 카드 표(CardGrid) — 값이 없으면 컴포넌트가 스스로 스켈레톤을 그리므로 빈 문구가
 *     나오지 않는다. 묶는 식이 받는 중에 빈 배열로 바뀌지 않는지(`?? []` 금지)를 잰다.
 *  5. 전수 — 관리자 레이아웃의 표는 모두 위 규칙을 지키거나 받는 중 상태가 없는 이유가 적혀 있다.
 *
 * 표 컴포넌트는 스텁이다. 실제 컴포넌트와 같은 규칙만 흉내 낸다 —
 *  - DataGrid: 행이 없으면 `emptyMessage ?? '기본 문구'` 를 그린다(빈 문자열이면 아무것도 그리지 않는다).
 *  - CardGrid: `data` 가 없으면(`!data`) 스켈레톤, 배열이 비면 `emptyMessage ?? '기본 문구'`.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import postsIndex from '../../../layouts/admin/admin_board_posts_index.json';
import reactionsIndex from '../../../layouts/admin/admin_board_reactions_index.json';
import reportsIndex from '../../../layouts/admin/admin_board_reports_index.json';
import scrapsIndex from '../../../layouts/admin/admin_board_scraps_index.json';
import boardIndex from '../../../layouts/admin/admin_board_index.json';

type Node = Record<string, any>;

interface GridCase {
  /** 화면 이름(시험 제목용) */
  screen: string;
  /** 표 노드를 담은 레이아웃 JSON */
  layout: Node;
  /** 표가 묶인 목록 데이터소스 */
  source: string;
  /** 빈 문구 다국어 키 */
  emptyKey: string;
}

const CASES: GridCase[] = [
  { screen: '게시글 목록', layout: postsIndex, source: 'posts', emptyKey: 'common.no_data' },
  { screen: '반응 목록', layout: reactionsIndex, source: 'reactions', emptyKey: 'sirsoft-board.admin.reactions.table_empty' },
  { screen: '신고 목록', layout: reportsIndex, source: 'reports', emptyKey: 'common.no_data' },
  { screen: '스크랩 목록', layout: scrapsIndex, source: 'scraps', emptyKey: 'sirsoft-board.admin.scraps.table_empty' },
];

/** 처음 받기에 실패했을 때 빈 문구 자리에 보일 문구 */
const FAIL_KEY = 'sirsoft-board.common.list_load_failed';

/** 게시판 목록 카드 표 */
const BOARD_CARD_CASE = {
  screen: '게시판 목록(카드)',
  layout: boardIndex as Node,
  source: 'boards',
  emptyKey: 'sirsoft-board.admin.board.index.empty_description',
};

/** 스텁이 쓰는 기본 빈 문구 — 실제 컴포넌트의 `t('common.no_data')` 자리 */
const DEFAULT_EMPTY = '__default_empty__';

/**
 * 트리에서 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param out 결과 배열
 * @returns 찾은 노드 목록
 */
function findAll(node: any, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
    return out;
  }
  if (predicate(node)) out.push(node);
  for (const value of Object.values(node)) findAll(value, predicate, out);
  return out;
}

/**
 * 레이아웃에서 표 노드 하나를 꺼낸다.
 *
 * @param layout 레이아웃 JSON
 * @param name 컴포넌트 이름
 * @returns 표 노드
 */
function gridOf(layout: Node, name: 'DataGrid' | 'CardGrid'): Node {
  const grids = findAll(layout, (n) => n.name === name);
  expect(grids).toHaveLength(1);
  return grids[0];
}

/**
 * 점 경로 다국어 키를 중첩 객체로 만든다. 값은 키를 알아볼 수 있는 표지 문자열이다.
 *
 * @param keys 다국어 키 목록
 * @returns 번역 사전
 */
function translationsFor(keys: string[]): Record<string, any> {
  const root: Record<string, any> = {};
  for (const key of keys) {
    const parts = key.split('.');
    let cursor = root;
    parts.slice(0, -1).forEach((part) => {
      cursor[part] = cursor[part] ?? {};
      cursor = cursor[part];
    });
    cursor[parts[parts.length - 1]] = `빈문구:${key}`;
  }
  return root;
}

/**
 * 목록 응답 봉투.
 *
 * @param rows 행 배열
 * @returns 데이터소스 값
 */
function listResponse(rows: Node[]) {
  return {
    data: {
      data: rows,
      abilities: {},
      pagination: { current_page: 1, per_page: 20, last_page: 1, total: rows.length, has_more_pages: false },
    },
  };
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'DataGrid', ({ data, emptyMessage }: any) => {
    const rows: any[] = Array.isArray(data) ? data : [];
    return React.createElement(
      'div',
      { 'data-testid': 'grid' },
      rows.length > 0
        ? rows.map((row, index) => React.createElement('div', { key: index, 'data-testid': 'grid-row' }, String(row.id)))
        : React.createElement('div', { 'data-testid': 'grid-empty' }, emptyMessage ?? DEFAULT_EMPTY)
    );
  });
  // 실제 CardGrid: `if (!data)` → 스켈레톤, `paginatedData.length === 0` → resolvedEmptyMessage
  registry.register('composite', 'CardGrid', ({ data, emptyMessage }: any) => {
    if (!data) return React.createElement('div', { 'data-testid': 'grid-skeleton' });
    const rows: any[] = Array.isArray(data) ? data : [];
    return React.createElement(
      'div',
      { 'data-testid': 'grid' },
      rows.length > 0
        ? rows.map((row, index) => React.createElement('div', { key: index, 'data-testid': 'grid-row' }, String(row.id)))
        : React.createElement('div', { 'data-testid': 'grid-empty' }, emptyMessage ?? DEFAULT_EMPTY)
    );
  });
});

/**
 * 표 노드 하나를 데이터소스 값과 함께 렌더한다.
 *
 * @param gridCase 화면
 * @param name 표 컴포넌트 이름
 * @param value 데이터소스 값 — `undefined` 는 「받는 중」(엔진이 화면을 먼저 그릴 때 넣는 값)
 * @returns 렌더 결과와 정리 함수
 */
async function renderGrid(gridCase: GridCase, name: 'DataGrid' | 'CardGrid', value: unknown) {
  const { meta, ...grid } = gridOf(gridCase.layout, name);
  const utils = createLayoutTest(
    { version: '1.0.0', layout_name: `test/loading-empty/${gridCase.source}`, components: [grid] } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: translationsFor([gridCase.emptyKey, FAIL_KEY]),
      // 표현식 안 `$t('…')` 은 렌더 컨텍스트의 템플릿·로케일로 사전을 고른다 — 실제 앱은 이 값을 싣는다
      initialData: { [gridCase.source]: value, $templateId: 'test-template', $locale: 'ko' },
    }
  );
  const { container } = await utils.render();
  const empty = container.querySelector('[data-testid="grid-empty"]');
  return {
    container,
    emptyText: empty ? (empty.textContent ?? '') : null,
    rowCount: container.querySelectorAll('[data-testid="grid-row"]').length,
    skeleton: container.querySelector('[data-testid="grid-skeleton"]') !== null,
    blurred: container.querySelector('.blur-sm') !== null,
    cleanup: utils.cleanup,
  };
}

describe('[case:components-datagrid-empty-message-while-loading] 관리자 게시판 목록 — 받는 중에는 빈 문구가 없다', () => {
  /**
   * 목록을 아직 받지 못했으면(값 `undefined`) 「없음」 문구를 그리지 않고 표를 흐리게 덮는다.
   *
   * @scenario load_state=loading
   * @effects loading_hides_empty_message, loading_blurs_grid
   */
  it.each(CASES)('$screen: 받는 중에는 빈 문구가 없고 표가 흐려진다', async (gridCase) => {
    const result = await renderGrid(gridCase, 'DataGrid', undefined);

    expect(result.rowCount).toBe(0);
    expect(result.emptyText).toBe('');
    expect(result.blurred).toBe(true);
    result.cleanup();
  });

  /**
   * 받은 결과가 0건이면 그 화면의 빈 문구가 보이고 흐림이 풀린다.
   *
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_message
   */
  it.each(CASES)('$screen: 받은 뒤 0건이면 빈 문구가 보인다', async (gridCase) => {
    const result = await renderGrid(gridCase, 'DataGrid', listResponse([]));

    expect(result.rowCount).toBe(0);
    expect(result.emptyText).toBe(`빈문구:${gridCase.emptyKey}`);
    expect(result.blurred).toBe(false);
    result.cleanup();
  });

  /**
   * 받은 결과가 있으면 행이 그려지고 빈 문구는 없다.
   *
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_message
   */
  it.each(CASES)('$screen: 받은 뒤 행이 있으면 빈 문구가 없다', async (gridCase) => {
    const result = await renderGrid(gridCase, 'DataGrid', listResponse([{ id: 11 }, { id: 12 }]));

    expect(result.rowCount).toBe(2);
    expect(result.emptyText).toBeNull();
    expect(result.blurred).toBe(false);
    result.cleanup();
  });
});

describe('관리자 게시판 목록 — 받기에 실패하면 「없음」 대신 실패 문구', () => {
  /**
   * 처음 받기에 실패하면(값 `null`) 흐림이 풀리고 빈 문구 자리에 실패 문구를 그린다.
   *
   * @scenario load_state=error
   * @effects error_shows_load_failed_message
   */
  it.each(CASES)('$screen: 받기에 실패하면 실패 문구가 보이고 「없음」은 없다', async (gridCase) => {
    const result = await renderGrid(gridCase, 'DataGrid', null);

    expect(result.rowCount).toBe(0);
    expect(result.emptyText).toBe(`빈문구:${FAIL_KEY}`);
    expect(result.blurred).toBe(false);
    result.cleanup();
  });
});

describe('관리자 게시판 목록(카드) — 받는 중에는 컴포넌트가 스켈레톤을 그린다', () => {
  /**
   * 받기 전 값은 `undefined` 그대로 카드 표에 넘어가야 한다. 묶는 식이 빈 배열로 바꾸면
   * 카드 표는 받는 중을 알 수 없어 빈 문구를 그린다.
   *
   * @scenario load_state=loading
   * @effects card_grid_loading_shows_skeleton
   */
  it('받는 중에는 스켈레톤이고 빈 문구가 없다', async () => {
    const result = await renderGrid(BOARD_CARD_CASE, 'CardGrid', undefined);

    expect(result.skeleton).toBe(true);
    expect(result.emptyText).toBeNull();
    result.cleanup();
  });

  /**
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_message
   */
  it('받은 뒤 0건이면 빈 문구가 보인다', async () => {
    const result = await renderGrid(BOARD_CARD_CASE, 'CardGrid', listResponse([]));

    expect(result.skeleton).toBe(false);
    expect(result.emptyText).toBe(`빈문구:${BOARD_CARD_CASE.emptyKey}`);
    result.cleanup();
  });

  /**
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_message
   */
  it('받은 뒤 행이 있으면 빈 문구가 없다', async () => {
    const result = await renderGrid(BOARD_CARD_CASE, 'CardGrid', listResponse([{ id: 1 }]));

    expect(result.skeleton).toBe(false);
    expect(result.rowCount).toBe(1);
    expect(result.emptyText).toBeNull();
    result.cleanup();
  });
});

describe('관리자 게시판 목록 — 전수', () => {
  const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts/admin');

  /** 받는 중 상태가 화면에 나오지 않는 표 — 이유를 적는다 */
  const NOT_APPLICABLE = new Set([
    // 이력이 1건 이상일 때만 표를 붙인다(`if`) — 받는 중·0건에는 표 자체가 없다
    'partials/admin_board_reports_detail/_card_history.json',
  ]);

  /**
   * 디렉터리 아래 JSON 레이아웃을 모두 모은다.
   *
   * @param dir 디렉터리
   * @param out 결과
   * @returns 파일 경로 목록
   */
  function walk(dir: string, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full, out);
      else if (entry.name.endsWith('.json')) out.push(full);
    }
    return out;
  }

  /**
   * DataGrid 는 빈 문구를 명시하지 않아도 기본 문구를 그린다 — 그래서 빈 문구 유무와 관계없이
   * 모든 DataGrid 가 표본(CASES)에 들거나 이유가 적혀 있어야 한다. CardGrid 는 값이 없으면
   * 스켈레톤을 그리므로, 묶는 식이 받는 중 값을 빈 배열로 바꾸지 않는지만 본다.
   *
   * @scenario load_state=loading
   * @effects every_progressive_list_grid_gated
   */
  it('관리자 표는 모두 받는 중 규칙을 지킨다', () => {
    const covered = new Set(CASES.map((c) => c.source));
    const offenders: string[] = [];
    let gridCount = 0;

    for (const file of walk(LAYOUT_ROOT)) {
      const rel = path.relative(LAYOUT_ROOT, file).split(path.sep).join('/');
      if (NOT_APPLICABLE.has(rel)) continue;
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const grid of findAll(json, (n) => n.name === 'DataGrid')) {
        gridCount += 1;
        const source = String(grid.props?.data ?? '').match(/^\{\{\s*([A-Za-z_]\w*)/)?.[1] ?? '?';
        // 「받는 중」은 데이터소스 값이 아직 없는 것이다 — 엔진의 흐림 판정(blur_until_loaded)과 같은 기준
        // 받는 중에는 빈 칸, 처음 받기 실패(null)에는 실패 문구, 받은 뒤 0건에만 「없음」
        const gated = String(grid.props?.emptyMessage ?? '').startsWith(`{{${source} === undefined ? '' : (${source} === null ? $t('${FAIL_KEY}') : $t(`);
        if (!covered.has(source) || !gated || !grid.blur_until_loaded) {
          offenders.push(`${rel} DataGrid (${source})`);
        }
      }
      for (const grid of findAll(json, (n) => n.name === 'CardGrid')) {
        gridCount += 1;
        if (/\?\?/.test(String(grid.props?.data ?? ''))) {
          offenders.push(`${rel} CardGrid (${grid.props?.data})`);
        }
      }
    }

    expect(gridCount).toBeGreaterThanOrEqual(CASES.length + 1);
    expect(offenders).toEqual([]);
  });
});
