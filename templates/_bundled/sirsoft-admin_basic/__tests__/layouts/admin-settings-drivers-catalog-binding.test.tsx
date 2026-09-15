/**
 * @file admin-settings-drivers-catalog-binding.test.tsx
 * @description 드라이버 셀렉트의 카탈로그 바인딩 + 죽은 저장값 안내
 *
 * 검색엔진 셀렉트만 옵션이 레이아웃에 박혀 있어, 플러그인이 검색엔진을 등록해도
 * 화면에서 고를 수 없었다. 그리고 저장값이 카탈로그에 없으면(공급 플러그인 제거)
 * 셀렉트는 빈 칸으로만 보여, 운영자가 "설정이 비어 있다" 로 오해하고 실제 저장값이
 * 무엇인지 알 방법이 없었다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const PARTIAL_DIR = resolve(__dirname, '../../layouts/partials/admin_settings');

const driversPartial = JSON.parse(readFileSync(resolve(PARTIAL_DIR, '_tab_drivers.json'), 'utf-8'));
const mailPartial = JSON.parse(readFileSync(resolve(PARTIAL_DIR, '_tab_mail.json'), 'utf-8'));

// ---------------------------------------------------------------------------
// 테스트용 컴포넌트
// ---------------------------------------------------------------------------

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSelect: React.FC<any> = ({ name, options }) => (
  <select name={name} data-testid={`select-${name}`}>
    {(options ?? []).map((o: any) => (
      <option key={o.value} value={o.value}>
        {o.label}
      </option>
    ))}
  </select>
);
const TestSpan: React.FC<any> = ({ children, text, className }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ children, text, className, ...rest }) => (
  <p className={className} data-testid={rest['data-testid']}>
    {children || text}
  </p>
);
const TestLabel: React.FC<any> = ({ children, text }) => <label>{children || text}</label>;
const TestInput: React.FC<any> = ({ name }) => <input name={name} data-testid={name} />;
const TestButton: React.FC<any> = ({ children, text }) => <button type="button">{children || text}</button>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * @returns 구성된 레지스트리
 */
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * id 로 노드를 깊이 우선 탐색합니다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const child of node.children ?? []) {
    const found = findNodeById(child, id);
    if (found) return found;
  }
  return null;
}

/**
 * partial 루트들에서 id 노드를 찾습니다.
 *
 * @param partial 대상 partial JSON
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findInPartial(partial: any, id: string): any {
  for (const root of partial.components ?? [partial]) {
    const found = findNodeById(root, id);
    if (found) return found;
  }
  return null;
}

/**
 * 노드 트리를 순회하며 조건을 만족하는 노드를 모읍니다.
 *
 * @param node 탐색 시작 노드
 * @param predicate 판정 함수
 * @param acc 누적 배열
 * @returns 조건을 만족하는 노드 목록
 */
function collect(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const item of node) collect(item, predicate, acc);
    return acc;
  }
  if (predicate(node)) acc.push(node);
  for (const key of Object.keys(node)) collect(node[key], predicate, acc);
  return acc;
}

/**
 * 검색엔진 필드 블록을 주어진 상태로 렌더합니다.
 *
 * @param form 폼 상태 (_local.form)
 * @returns 레이아웃 테스트 유틸
 */
function renderSearchField(form: Record<string, unknown>) {
  const field = findInPartial(driversPartial, 'field_search_engine_driver');
  expect(field).not.toBeNull();

  return createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_drivers_catalog_binding',
      components: [field],
    } as any,
    { initialState: { _local: { form, errors: {} } } }
  );
}

const CORE_SEARCH_CATALOG = [{ id: 'mysql-fulltext', label: { ko: 'MySQL 전문검색', en: 'MySQL Full-Text' } }];

// @scenario saved_value_state=live
// @effects driver_select_options_built_from_catalog, live_saved_value_shows_no_notice
describe('드라이버 셀렉트 카탈로그 바인딩', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  it('검색엔진 옵션을 카탈로그에서 만든다 — 플러그인 등록 드라이버가 화면에 뜬다', async () => {
    const testUtils = renderSearchField({
      drivers: { search_engine_driver: 'meilisearch' },
      available_drivers: {
        search: [
          ...CORE_SEARCH_CATALOG,
          { id: 'meilisearch', label: { ko: 'Meilisearch', en: 'Meilisearch' } },
        ],
      },
    });
    await testUtils.render();

    const select = screen.getByTestId('select-drivers.search_engine_driver');
    const values = Array.from(select.querySelectorAll('option')).map((o) => o.getAttribute('value'));

    expect(values).toContain('mysql-fulltext');
    expect(values).toContain('meilisearch');

    testUtils.cleanup();
  });

  it('저장값이 카탈로그에 있으면 안내를 띄우지 않는다', async () => {
    const testUtils = renderSearchField({
      drivers: { search_engine_driver: 'mysql-fulltext' },
      available_drivers: { search: CORE_SEARCH_CATALOG },
    });
    await testUtils.render();

    // 존재를 먼저 확정한 뒤 부재를 단언한다 (부재 단독 단언 금지 규율)
    expect(screen.getByTestId('select-drivers.search_engine_driver')).toBeInTheDocument();
    expect(screen.queryByTestId('driver-unavailable-search')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  it('공급 플러그인이 사라진 저장값은 안내와 함께 값 자체를 드러낸다', async () => {
    const testUtils = renderSearchField({
      drivers: { search_engine_driver: 'elasticsearch' },
      available_drivers: { search: CORE_SEARCH_CATALOG },
    });
    await testUtils.render();

    const notice = screen.getByTestId('driver-unavailable-search');
    expect(notice).toBeInTheDocument();
    expect(notice.textContent).toContain('elasticsearch');

    testUtils.cleanup();
  });

  it('저장값이 비어 있으면 안내를 띄우지 않는다', async () => {
    const present = renderSearchField({
      drivers: { search_engine_driver: 'elasticsearch' },
      available_drivers: { search: CORE_SEARCH_CATALOG },
    });
    await present.render();
    expect(screen.getByTestId('driver-unavailable-search')).toBeInTheDocument();
    present.cleanup();

    const testUtils = renderSearchField({
      drivers: { search_engine_driver: '' },
      available_drivers: { search: CORE_SEARCH_CATALOG },
    });
    await testUtils.render();

    expect(screen.queryByTestId('driver-unavailable-search')).not.toBeInTheDocument();

    testUtils.cleanup();
  });
});

// @scenario saved_value_state=dead
// @effects dead_saved_value_notice_reveals_the_value, every_catalog_bound_select_has_dead_value_notice
describe('카탈로그 바인딩 셀렉트 전수', () => {
  const CATALOG_RE = /_local\.form\?\.available_drivers\?\.([a-z_]+)\s*\?\?/;

  it('카탈로그 바인딩 셀렉트마다 죽은 저장값 안내가 붙어 있다', () => {
    for (const [name, partial] of [
      ['_tab_drivers.json', driversPartial],
      ['_tab_mail.json', mailPartial],
    ] as const) {
      const selects = collect(
        partial,
        (n) => n.name === 'Select' && typeof n.props?.options === 'string' && CATALOG_RE.test(n.props.options)
      );

      expect(selects.length, `${name} 에서 카탈로그 바인딩 셀렉트를 찾지 못했습니다.`).toBeGreaterThan(0);

      const notices = collect(partial, (n) => typeof n.id === 'string' && n.id.startsWith('unavailable_driver_notice_'));

      const selectCategories = selects.map((s) => s.props.options.match(CATALOG_RE)![1]).sort();
      const noticeCategories = notices.map((n) => n.id.replace('unavailable_driver_notice_', '')).sort();

      expect(noticeCategories, `${name} 의 안내 노드가 셀렉트 카테고리와 일치하지 않습니다.`).toEqual(
        selectCategories
      );
    }
  });

  it('안내 조건식이 저장값과 카탈로그를 함께 본다', () => {
    const notices = collect(
      driversPartial,
      (n) => typeof n.id === 'string' && n.id.startsWith('unavailable_driver_notice_')
    );

    expect(notices.length).toBeGreaterThan(0);

    for (const notice of notices) {
      expect(notice.if).toMatch(/available_drivers\?\.[a-z_]+ \?\? \[\]\)\.some\(/);
      expect(notice.if).toMatch(/^\{\{!!_local\.form\?\./);
    }
  });
});
