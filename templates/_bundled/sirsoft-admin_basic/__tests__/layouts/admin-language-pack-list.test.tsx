/**
 * @file admin-language-pack-list.test.tsx
 * @description 언어팩 관리 통합 페이지 + 설치 모달 + protected 팩 액션 비활성 렌더링 테스트
 *
 * 테스트 범위 (계획서 §16.10):
 *  - 71: 통합 페이지 언어팩 목록 렌더링 (Vendor 열 포함)
 *  - 72: scope 필터 변경 — _local.filterScope 상태 갱신
 *  - 73: 설치 모달 ZIP/GitHub/URL 탭 전환
 *  - 74: 빈 상태 (언어팩 0개) 안내 + 설치 버튼 표시
 *  - 75: protected 팩 — 제거 버튼 disabled
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen, fireEvent } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestButton: React.FC<any> = ({ className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} onClick={onClick} data-testid={testId}>{children || text}</button>
);
const TestSpan: React.FC<any> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);
const TestSelect: React.FC<any> = ({ value, onChange, options, 'data-testid': testId }) => (
  <select value={value ?? ''} onChange={onChange} data-testid={testId}>
    {(options ?? []).map((o: any) => <option key={o.value} value={o.value}>{o.label}</option>)}
  </select>
);
const TestPageHeader: React.FC<any> = ({ title, description, children }) => (
  <div data-testid="page-header">
    {title && <h1>{title}</h1>}
    {description && <p>{description}</p>}
    {children}
  </div>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    PageHeader: { component: TestPageHeader, metadata: { name: 'PageHeader', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const listLayout = {
  version: '1.0.0',
  layout_name: 'test_lang_pack_list',
  data_sources: [
    {
      id: 'language_packs',
      type: 'api',
      endpoint: '/api/admin/language-packs',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      fallback: { data: { data: [], meta: { total: 0 }, abilities: {} } },
    },
  ],
  state: { filterScope: '' },
  components: [
    {
      id: 'page',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'page_header',
          type: 'composite',
          name: 'PageHeader',
          props: { title: '$t:admin.language_packs.title' },
          children: [
            {
              id: 'install_btn',
              type: 'basic',
              name: 'Button',
              props: { 'data-testid': 'install-button' },
              text: '설치',
            },
          ],
        },
        {
          id: 'filter_scope',
          type: 'basic',
          name: 'Select',
          props: {
            value: '{{_local.filterScope ?? ""}}',
            'data-testid': 'filter-scope',
            options: [
              { value: '', label: '전체' },
              { value: 'core', label: '코어' },
              { value: 'module', label: '모듈' },
            ],
          },
          actions: [
            {
              event: 'onChange',
              handler: 'setState',
              params: { target: 'local', filterScope: '$event.target.value' },
            },
          ],
        },
        {
          id: 'empty_message',
          type: 'basic',
          name: 'Div',
          if: '{{(language_packs?.data?.data?.length ?? 0) === 0}}',
          props: { 'data-testid': 'empty-state' },
          children: [
            { id: 'empty_lbl', type: 'basic', name: 'Span', text: '설치된 언어팩이 없습니다.' },
          ],
        },
        {
          id: 'rows',
          type: 'basic',
          name: 'Div',
          if: '{{(language_packs?.data?.data?.length ?? 0) > 0}}',
          props: { 'data-testid': 'rows-container' },
          iteration: {
            source: 'language_packs?.data?.data',
            item_var: 'item',
          },
          children: [
            { id: 'r_vendor', type: 'basic', name: 'Span', text: '{{item.vendor}}' },
            { id: 'r_locale', type: 'basic', name: 'Span', text: '{{item.locale}}' },
            { id: 'r_version', type: 'basic', name: 'Span', text: '{{item.version}}' },
            {
              id: 'r_uninstall',
              type: 'basic',
              name: 'Button',
              props: { disabled: '{{item.is_protected === true}}' },
              text: '제거-{{item.identifier}}',
            },
          ],
        },
      ],
    },
  ],
};

const modalLayout = {
  version: '1.0.0',
  layout_name: 'test_lang_pack_install_modal',
  data_sources: [],
  state: { installTab: 'zip' },
  components: [
    {
      id: 'modal_body',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'panel_zip',
          type: 'basic',
          name: 'Div',
          if: '{{_local.installTab === "zip"}}',
          props: { 'data-testid': 'panel-zip' },
          children: [{ id: 'p1', type: 'basic', name: 'Span', text: 'ZIP 패널' }],
        },
        {
          id: 'panel_github',
          type: 'basic',
          name: 'Div',
          if: '{{_local.installTab === "github"}}',
          props: { 'data-testid': 'panel-github' },
          children: [{ id: 'p2', type: 'basic', name: 'Span', text: 'GitHub 패널' }],
        },
        {
          id: 'panel_url',
          type: 'basic',
          name: 'Div',
          if: '{{_local.installTab === "url"}}',
          props: { 'data-testid': 'panel-url' },
          children: [{ id: 'p3', type: 'basic', name: 'Span', text: 'URL 패널' }],
        },
      ],
    },
  ],
};

describe('admin_language_pack_list 레이아웃 렌더링 (계획서 §16.10)', () => {
  beforeEach(() => {
    setupRegistry();
  });

  it('케이스 71: 언어팩 목록 — Vendor 열 포함 row 렌더링', async () => {
    const testUtils = createLayoutTest(listLayout);
    testUtils.mockApi('language_packs', {
      response: {
        data: {
          data: [
            { id: 1, identifier: 'g7-core-ko', vendor: 'g7', locale: 'ko', version: '1.0.0', status: 'active', is_protected: true },
            { id: 2, identifier: 'sirsoft-core-ja', vendor: 'sirsoft', locale: 'ja', version: '1.0.0', status: 'active', is_protected: false },
          ],
          meta: { total: 2 },
          abilities: { can_install: true },
        },
      },
    });

    await testUtils.render();

    // Vendor 열 — 두 벤더 모두 표시
    expect(screen.getByText('g7')).toBeInTheDocument();
    expect(screen.getByText('sirsoft')).toBeInTheDocument();
    expect(screen.getByText('ja')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('케이스 72: scope 필터 변경 → Select 가 _local.filterScope 값에 바인딩됨', async () => {
    // 초기 상태로 'module' 시작 — Select 의 value 가 _local 상태와 바인딩됨을 확인
    const filteredLayout = { ...listLayout, layout_name: 'list_filter_module', state: { filterScope: 'module' } };
    const testUtils = createLayoutTest(filteredLayout);
    testUtils.mockApi('language_packs', {
      response: { data: { data: [], meta: { total: 0 }, abilities: {} } },
    });

    await testUtils.render();

    const select = screen.getByTestId('filter-scope') as HTMLSelectElement;
    expect(select.value).toBe('module');

    // 직접 setState 로 상태 갱신 — getState 가 동일 값 반환
    testUtils.setState('filterScope', 'core', 'local');
    expect(testUtils.getState()._local.filterScope).toBe('core');

    testUtils.cleanup();
  });

  it('케이스 73: 설치 모달 — installTab 상태에 따라 ZIP/GitHub/URL 패널 표시', async () => {
    // 초기 zip 패널 — 기본 상태에서 zip 만 표시
    const utilsZip = createLayoutTest(modalLayout);
    await utilsZip.render();
    expect(screen.getByTestId('panel-zip')).toBeInTheDocument();
    expect(screen.queryByTestId('panel-github')).not.toBeInTheDocument();
    expect(screen.queryByTestId('panel-url')).not.toBeInTheDocument();
    utilsZip.cleanup();

    // installTab='github' 으로 시작하는 별도 layout — github 패널만 표시
    const githubLayout = { ...modalLayout, layout_name: 'modal_github', state: { installTab: 'github' } };
    const utilsGh = createLayoutTest(githubLayout);
    await utilsGh.render();
    expect(screen.queryByTestId('panel-zip')).not.toBeInTheDocument();
    expect(screen.getByTestId('panel-github')).toBeInTheDocument();
    expect(screen.queryByTestId('panel-url')).not.toBeInTheDocument();
    utilsGh.cleanup();

    // installTab='url' 으로 시작 — url 패널만 표시
    const urlLayout = { ...modalLayout, layout_name: 'modal_url', state: { installTab: 'url' } };
    const utilsUrl = createLayoutTest(urlLayout);
    await utilsUrl.render();
    expect(screen.queryByTestId('panel-zip')).not.toBeInTheDocument();
    expect(screen.queryByTestId('panel-github')).not.toBeInTheDocument();
    expect(screen.getByTestId('panel-url')).toBeInTheDocument();
    utilsUrl.cleanup();
  });

  it('케이스 74: 빈 상태 — 안내 메시지 + 설치 버튼 표시', async () => {
    const testUtils = createLayoutTest(listLayout);
    testUtils.mockApi('language_packs', {
      response: { data: { data: [], meta: { total: 0 }, abilities: { can_install: true } } },
    });

    await testUtils.render();

    expect(screen.getByTestId('empty-state')).toBeInTheDocument();
    expect(screen.queryByTestId('rows-container')).not.toBeInTheDocument();
    expect(screen.getByTestId('install-button')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('케이스 76: has_update=true 행 — 업데이트 배지 노출 (P5 회귀)', async () => {
    const updateBadgeLayout = {
      ...listLayout,
      layout_name: 'list_update_badge',
      components: [
        {
          id: 'rows',
          type: 'basic',
          name: 'Div',
          if: '{{(language_packs?.data?.data?.length ?? 0) > 0}}',
          props: { 'data-testid': 'rows-container' },
          iteration: { source: 'language_packs?.data?.data', item_var: 'item' },
          children: [
            { id: 'r_id', type: 'basic', name: 'Span', text: 'item-{{item.id}}' },
            {
              id: 'badge',
              type: 'basic',
              name: 'Span',
              if: '{{item.has_update === true}}',
              text: 'badge-{{item.id}}',
            },
          ],
        },
      ],
    };
    const testUtils = createLayoutTest(updateBadgeLayout);
    testUtils.mockApi('language_packs', {
      response: {
        data: {
          data: [
            { id: 1, identifier: 'g7-core-ko', vendor: 'g7', locale: 'ko', version: '1.0.0', has_update: false },
            { id: 2, identifier: 'sirsoft-core-ja', vendor: 'sirsoft', locale: 'ja', version: '1.0.0', has_update: true },
          ],
          meta: { total: 2 },
          abilities: {},
        },
      },
    });

    await testUtils.render();

    expect(screen.getByText('item-1')).toBeInTheDocument();
    expect(screen.getByText('item-2')).toBeInTheDocument();
    expect(screen.queryByText('badge-1')).not.toBeInTheDocument();
    expect(screen.getByText('badge-2')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('케이스 77: scopeLocked=true + route.identifier — 부모 배너 노출 (P5 회귀)', async () => {
    const bannerLayout = {
      version: '1.0.0',
      layout_name: 'lp_banner',
      data_sources: [],
      state: { scopeLocked: true, filterScope: 'module' },
      components: [
        {
          id: 'banner',
          type: 'basic',
          name: 'Div',
          if: '{{_local.scopeLocked === true}}',
          props: { 'data-testid': 'parent-banner' },
          children: [{ id: 'banner_text', type: 'basic', name: 'Span', text: '확장 범위' }],
        },
        {
          id: 'no_banner',
          type: 'basic',
          name: 'Div',
          if: '{{_local.scopeLocked !== true}}',
          props: { 'data-testid': 'no-banner' },
        },
      ],
    };
    const testUtils = createLayoutTest(bannerLayout);
    await testUtils.render();

    expect(screen.getByTestId('parent-banner')).toBeInTheDocument();
    expect(screen.queryByTestId('no-banner')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  it('케이스 78: 카드형 UI — CardGrid 데이터 + 카드별 액션 영역 렌더 (#263 후속)', async () => {
    const TestCardGrid: React.FC<any> = ({ data, cardColumns, 'data-testid': testId }) => (
      <div data-testid={testId || 'card-grid'}>
        {(data ?? []).map((row: any, idx: number) => (
          <div key={row.id ?? idx} data-testid={`card-${row.id ?? idx}`}>
            {(cardColumns?.[0]?.cellChildren ?? []).map((child: any, ci: number) => (
              <div key={ci}>card-cell-{row.identifier}</div>
            ))}
          </div>
        ))}
      </div>
    );
    const TestToggle: React.FC<any> = ({ checked, disabled, 'data-testid': testId }) => (
      <input type="checkbox" data-testid={testId} defaultChecked={checked} disabled={disabled} />
    );
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry.CardGrid = { component: TestCardGrid, metadata: { name: 'CardGrid', type: 'composite' } };
    (registry as any).registry.Toggle = { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } };

    const cardLayout = {
      version: '1.0.0',
      layout_name: 'lp_card_test',
      data_sources: [
        {
          id: 'language_packs',
          type: 'api',
          endpoint: '/api/admin/language-packs',
          method: 'GET',
          auto_fetch: true,
          fallback: { data: { data: [], meta: { total: 0 }, abilities: {} } },
        },
      ],
      state: {},
      components: [
        {
          id: 'grid',
          type: 'composite',
          name: 'CardGrid',
          if: '{{(language_packs?.data?.data?.length ?? 0) > 0}}',
          props: {
            'data-testid': 'lp-card-grid',
            data: '{{language_packs?.data?.data ?? []}}',
            cardColumns: [
              {
                id: 'body',
                cellChildren: [
                  { id: 'name', type: 'basic', name: 'Span', text: '{{row.locale}}' },
                ],
              },
            ],
          },
        },
      ],
    };
    const utils = createLayoutTest(cardLayout);
    utils.mockApi('language_packs', {
      response: {
        data: {
          data: [
            { id: 1, identifier: 'g7-core-ko', locale: 'ko', status: 'active' },
            { id: null, identifier: 'g7-module-foo-ja', locale: 'ja', status: 'uninstalled' },
          ],
          meta: { total: 2 },
          abilities: { can_install: true, can_bulk_uninstall: true },
        },
      },
    });

    await utils.render();

    expect(screen.getByTestId('lp-card-grid')).toBeInTheDocument();
    expect(screen.getByText(/card-cell-g7-core-ko/)).toBeInTheDocument();
    expect(screen.getByText(/card-cell-g7-module-foo-ja/)).toBeInTheDocument();

    utils.cleanup();
  });

  it('케이스 79: 미설치 번들 (status=uninstalled) — install 버튼 노출, Toggle 미노출 (#263 후속)', async () => {
    const installVisibilityLayout = {
      version: '1.0.0',
      layout_name: 'lp_install_visibility',
      data_sources: [
        {
          id: 'language_packs',
          type: 'api',
          endpoint: '/api/admin/language-packs',
          method: 'GET',
          auto_fetch: true,
          fallback: { data: { data: [], meta: { total: 0 }, abilities: {} } },
        },
      ],
      state: {},
      components: [
        {
          id: 'rows',
          type: 'basic',
          name: 'Div',
          iteration: {
            source: 'language_packs?.data?.data',
            item_var: 'item',
          },
          children: [
            {
              id: 'install_btn',
              type: 'basic',
              name: 'Button',
              if: '{{item.status === "uninstalled" && item.abilities?.can_install}}',
              props: { 'data-testid': 'install-{{item.identifier}}' },
              text: 'install-{{item.identifier}}',
            },
            {
              id: 'toggle_marker',
              type: 'basic',
              name: 'Span',
              if: '{{item.status !== "uninstalled"}}',
              props: { 'data-testid': 'toggle-{{item.identifier}}' },
              text: 'toggle-{{item.identifier}}',
            },
          ],
        },
      ],
    };
    const utils = createLayoutTest(installVisibilityLayout);
    utils.mockApi('language_packs', {
      response: {
        data: {
          data: [
            { id: 1, identifier: 'a', status: 'active', abilities: { can_activate: true, can_uninstall: true } },
            { id: null, identifier: 'b', status: 'uninstalled', abilities: { can_install: true } },
          ],
          meta: { total: 2 },
          abilities: {},
        },
      },
    });

    await utils.render();

    expect(screen.queryByTestId('install-a')).not.toBeInTheDocument();
    expect(screen.getByTestId('install-b')).toBeInTheDocument();
    expect(screen.getByTestId('toggle-a')).toBeInTheDocument();
    expect(screen.queryByTestId('toggle-b')).not.toBeInTheDocument();

    utils.cleanup();
  });

  it('케이스 75: protected 팩 — 제거 버튼 disabled', async () => {
    const testUtils = createLayoutTest(listLayout);
    testUtils.mockApi('language_packs', {
      response: {
        data: {
          data: [
            { id: 1, identifier: 'g7-core-ko', vendor: 'g7', locale: 'ko', version: '1.0.0', status: 'active', is_protected: true },
            { id: 2, identifier: 'sirsoft-core-ja', vendor: 'sirsoft', locale: 'ja', version: '1.0.0', status: 'active', is_protected: false },
          ],
          meta: { total: 2 },
          abilities: { can_install: true },
        },
      },
    });

    await testUtils.render();

    const protectedBtn = screen.getByText('제거-g7-core-ko');
    const normalBtn = screen.getByText('제거-sirsoft-core-ja');

    expect(protectedBtn).toBeDisabled();
    expect(normalBtn).not.toBeDisabled();

    testUtils.cleanup();
  });
});
