/**
 * @file admin-list-loading-empty-state.test.tsx
 * @description 관리자 페이지 목록·버전 목록 표 — 목록을 받는 동안에는 「없음」 문구를 보이지 않는다
 *
 * 목록 데이터소스는 화면이 먼저 그려진 뒤에 받는다(progressive, 전략을 적지 않으면 이 값이다).
 * 받기 전에는 데이터소스 값이 `undefined` 라서 표의 행이 0개로 보이고, 표는 행이 0개이면 빈 문구를
 * 그린다(빈 문구를 적지 않으면 「데이터가 없습니다」 기본 문구). 그래서 페이지 수정 화면에서
 * 「목록」으로 돌아온 직후나 페이지 상세의 버전 목록이 아직 오지 않은 순간에 「없음」이 먼저 보였다.
 *
 * 각 화면의 실제 레이아웃 JSON 에서 표 노드를 꺼내 하네스로 렌더한다:
 *
 *  1. 받는 중(값이 `undefined`) — 빈 문구가 없고, 표가 흐림 처리된다.
 *  2. 받은 뒤 0건 — 빈 문구가 보이고 흐림이 풀린다.
 *  3. 받은 뒤 1건 이상 — 행이 그려지고 빈 문구가 없다.
 *  4. 전수 — 관리자 레이아웃의 표는 모두 위 규칙을 지킨다.
 *
 * 표 컴포넌트는 스텁이다. 실제 `DataGrid` 와 같은 규칙만 흉내 낸다 — 행이 없으면
 * `emptyMessage ?? '기본 문구'` 를 그린다(빈 문자열이면 아무것도 그리지 않는다).
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

import pageList from '../../../layouts/admin/admin_page_list.json';
import pageDetail from '../../../layouts/admin/admin_page_detail.json';

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
  /** 행 배열을 그 데이터소스의 응답 모양으로 감싼다 */
  respond: (rows: Node[]) => unknown;
}

/**
 * 페이지네이션 목록 응답 봉투.
 *
 * @param rows 행 배열
 * @returns 데이터소스 값
 */
function pagedResponse(rows: Node[]) {
  return {
    data: {
      data: rows,
      abilities: {},
      pagination: { current_page: 1, per_page: 20, last_page: 1, total: rows.length, has_more_pages: false },
    },
  };
}

/**
 * 배열 응답 봉투(버전 목록 — 페이지네이션 없음).
 *
 * @param rows 행 배열
 * @returns 데이터소스 값
 */
function arrayResponse(rows: Node[]) {
  return { data: rows };
}

const CASES: GridCase[] = [
  { screen: '페이지 목록', layout: pageList, source: 'pages', emptyKey: 'common.no_data', respond: pagedResponse },
  { screen: '페이지 상세 버전 목록', layout: pageDetail, source: 'versions', emptyKey: 'common.no_data', respond: arrayResponse },
];

/** 스텁이 쓰는 기본 빈 문구 — 실제 DataGrid 의 `t('common.no_data')` 자리 */
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
 * @returns DataGrid 노드
 */
function gridOf(layout: Node): Node {
  const grids = findAll(layout, (n) => n.name === 'DataGrid');
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
});

/**
 * 표 노드 하나를 데이터소스 값과 함께 렌더한다.
 *
 * @param gridCase 화면
 * @param value 데이터소스 값 — `undefined` 는 「받는 중」(엔진이 화면을 먼저 그릴 때 넣는 값)
 * @returns 렌더 결과와 정리 함수
 */
async function renderGrid(gridCase: GridCase, value: unknown) {
  const { meta, ...grid } = gridOf(gridCase.layout);
  const utils = createLayoutTest(
    { version: '1.0.0', layout_name: `test/loading-empty/${gridCase.source}`, components: [grid] } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: translationsFor([gridCase.emptyKey]),
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
    blurred: container.querySelector('.blur-sm') !== null,
    cleanup: utils.cleanup,
  };
}

describe('[case:components-datagrid-empty-message-while-loading] 관리자 페이지 목록 — 받는 중에는 빈 문구가 없다', () => {
  /**
   * 목록을 아직 받지 못했으면(값 `undefined`) 「없음」 문구를 그리지 않고 표를 흐리게 덮는다.
   *
   * @scenario load_state=loading
   * @effects loading_hides_empty_message, loading_blurs_grid
   */
  it.each(CASES)('$screen: 받는 중에는 빈 문구가 없고 표가 흐려진다', async (gridCase) => {
    const result = await renderGrid(gridCase, undefined);

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
    const result = await renderGrid(gridCase, gridCase.respond([]));

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
    const result = await renderGrid(gridCase, gridCase.respond([{ id: 11 }, { id: 12 }]));

    expect(result.rowCount).toBe(2);
    expect(result.emptyText).toBeNull();
    expect(result.blurred).toBe(false);
    result.cleanup();
  });
});

describe('관리자 페이지 목록 — 전수', () => {
  const LAYOUT_ROOT = path.resolve(__dirname, '../../../layouts/admin');

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
   * 모든 DataGrid 가 표본(CASES)에 들고 조건식·흐림을 가져야 한다.
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
      const json = JSON.parse(fs.readFileSync(file, 'utf8'));
      for (const grid of findAll(json, (n) => n.name === 'DataGrid')) {
        gridCount += 1;
        const source = String(grid.props?.data ?? '').match(/^\{\{\s*([A-Za-z_]\w*)/)?.[1] ?? '?';
        // 「받는 중」은 데이터소스 값이 아직 없는 것이다 — 엔진의 흐림 판정(blur_until_loaded)과 같은 기준
        const gated = String(grid.props?.emptyMessage ?? '').startsWith(`{{${source} === undefined ? '' : $t(`);
        if (!covered.has(source) || !gated || !grid.blur_until_loaded) {
          offenders.push(`${rel} (${source})`);
        }
      }
    }

    expect(gridCount).toBe(CASES.length);
    expect(offenders).toEqual([]);
  });
});
