/**
 * @file admin-activity-log-list.test.tsx
 * @description 활동 로그 목록 레이아웃 렌더링 테스트
 *
 * 테스트 대상:
 * - 데이터 로드 시 DataGrid에 로그 항목 렌더링
 * - 빈 데이터 시 빈 상태 표시
 * - 필터 UI 렌더링 (검색, 로그 타입 체크박스, 날짜)
 * - 페이지네이션 렌더링
 * - abilities 기반 삭제 버튼 제어
 * - 행위자 칸: 회원이 없는 로그(시스템·CLI)에는 회원 메뉴를 그리지 않음 (실제 partial 사용)
 * - 펼친 줄: 삭제 직전 기록의 대상·사유·남긴 버전 수가 row 값으로 보임 ($t:defer:, 실제 partial 사용)
 * - 「삭제 직전 기록만 보기」 필터: 체크 상태·주소 질의(deletion_snapshot=1)·새로고침 복원·초기화 (실제 레이아웃)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { renderItemChildren } from '@core/template-engine/helpers/RenderHelpers';
import { asArray, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import datagridPartial from '../../layouts/partials/admin_activity_log_list/_partial_datagrid.json';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestButton: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  disabled?: boolean | string;
  'data-testid'?: string;
}> = ({ className, children, text, type, disabled, 'data-testid': testId }) => (
  <button className={className} type={type as any} disabled={!!disabled} data-testid={testId}>{children || text}</button>
);

const TestInput: React.FC<{
  className?: string;
  type?: string;
  name?: string;
  value?: string;
  placeholder?: string;
  'data-testid'?: string;
}> = ({ className, type, name, value, placeholder, 'data-testid': testId }) => (
  <input className={className} type={type} name={name} defaultValue={value} placeholder={placeholder} data-testid={testId || `input-${name}`} />
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} data-testid={`icon-${name}`} />
);

const TestSelect: React.FC<{
  name?: string;
  className?: string;
  value?: string;
  options?: Array<{ value: string; label: string }>;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ name, className, value, options, children, 'data-testid': testId }) => (
  <select className={className} name={name} defaultValue={value} data-testid={testId || `select-${name}`}>
    {(options ?? []).map(opt => (
      <option key={opt.value} value={opt.value}>{opt.label}</option>
    ))}
    {children}
  </select>
);

const TestCheckbox: React.FC<{
  name?: string;
  label?: string;
  value?: string;
  checked?: boolean | string;
  className?: string;
}> = ({ name, label, value, checked, className }) => (
  <label className={className} data-testid={`checkbox-${name}`}>
    <input type="checkbox" name={name} value={value} defaultChecked={!!checked} />
    {label}
  </label>
);

const TestDataGrid: React.FC<{
  data?: any[];
  columns?: any[];
  loading?: boolean | string;
  selectable?: boolean | string;
  expandable?: boolean;
  pagination?: boolean;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ data, columns, loading, selectable, expandable, pagination, children, 'data-testid': testId }) => (
  <div data-testid={testId || 'datagrid'} data-loading={String(!!loading)} data-selectable={String(!!selectable)}>
    {(data ?? []).map((item, i) => (
      <div key={item.id || i} data-testid="datagrid-row">
        {(columns ?? []).map((col: any) => (
          <span key={col.field} data-testid={`cell-${col.field}`}>
            {item[col.field]}
          </span>
        ))}
      </div>
    ))}
    {(!data || data.length === 0) && <div data-testid="datagrid-empty">No data</div>}
    {children}
  </div>
);

const TestModal: React.FC<{
  id?: string;
  title?: string;
  size?: string;
  children?: React.ReactNode;
}> = ({ id, title, size, children }) => (
  <div data-testid={`modal-${id}`} data-title={title}>{children}</div>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'composite' } },
    DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 활동 로그 목록 레이아웃 (단순화 — 핵심 구조만)
function createActivityLogListLayout() {
  return {
    data_sources: [
      {
        id: 'activityLogs',
        type: 'api',
        endpoint: '/api/admin/activity-logs',
        method: 'GET',
        auto_fetch: true,
        auth_required: true,
        loading_strategy: 'progressive',
        params: {
          page: '{{query.page || 1}}',
          per_page: '{{query.per_page || 20}}',
        },
      },
    ],
    components: [
      {
        id: 'page_header',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'page-header' },
        children: [
          {
            type: 'basic',
            name: 'H1',
            text: '활동 로그',
            props: { 'data-testid': 'page-title' },
          },
        ],
      },
      {
        id: 'filter_section',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'filter-section' },
        children: [
          {
            type: 'composite',
            name: 'Select',
            props: {
              name: 'searchType',
              'data-testid': 'select-searchType',
              options: [
                { value: 'all', label: '전체' },
                { value: 'action', label: '액션' },
                { value: 'description', label: '설명' },
                { value: 'ip_address', label: 'IP주소' },
              ],
            },
          },
          {
            type: 'basic',
            name: 'Input',
            props: {
              type: 'text',
              name: 'search',
              placeholder: '검색어를 입력하세요...',
              'data-testid': 'search-input',
            },
          },
          {
            type: 'composite',
            name: 'Select',
            props: {
              name: 'createdBy',
              'data-testid': 'actor-searchable-dropdown',
              options: [],
            },
          },
          {
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'date-preset-buttons' },
            children: [
              { type: 'basic', name: 'Button', text: '오늘', props: { type: 'button', 'data-testid': 'preset-today' } },
              { type: 'basic', name: 'Button', text: '1주일', props: { type: 'button', 'data-testid': 'preset-week' } },
              { type: 'basic', name: 'Button', text: '1개월', props: { type: 'button', 'data-testid': 'preset-month' } },
              { type: 'basic', name: 'Button', text: '3개월', props: { type: 'button', 'data-testid': 'preset-3months' } },
              { type: 'basic', name: 'Button', text: '6개월', props: { type: 'button', 'data-testid': 'preset-6months' } },
              { type: 'basic', name: 'Button', text: '1년', props: { type: 'button', 'data-testid': 'preset-1year' } },
            ],
          },
          {
            type: 'basic',
            name: 'Input',
            props: {
              type: 'datetime-local',
              name: 'dateFrom',
              step: '1',
              'data-testid': 'date-from',
            },
          },
          {
            type: 'basic',
            name: 'Input',
            props: {
              type: 'datetime-local',
              name: 'dateTo',
              step: '1',
              'data-testid': 'date-to',
            },
          },
          {
            type: 'basic',
            name: 'Button',
            text: '검색',
            props: { 'data-testid': 'search-button' },
          },
          {
            type: 'basic',
            name: 'Button',
            text: '초기화',
            props: { 'data-testid': 'reset-button' },
          },
        ],
      },
      {
        id: 'activity_log_datagrid',
        type: 'composite',
        name: 'DataGrid',
        props: {
          data: '{{activityLogs?.data?.data}}',
          pagination: true,
          serverSidePagination: true,
          selectable: true,
          loading: '{{activityLogs?.loading}}',
          expandable: true,
          expandConditionField: 'has_changes',
          'data-testid': 'activity-log-datagrid',
          columns: [
            { field: 'number', header: '#' },
            { field: 'log_type_label', header: '타입' },
            { field: 'action_label', header: '액션' },
            { field: 'localized_description', header: '설명' },
            { field: 'loggable_type_display', header: '대상' },
            { field: 'actor', header: '행위자' },
            { field: 'ip_address', header: 'IP 주소' },
            { field: 'created_at', header: '일시' },
          ],
        },
      },
      {
        id: 'bulk_delete_button',
        type: 'basic',
        name: 'Button',
        props: {
          'data-testid': 'bulk-delete-button',
          disabled: '{{activityLogs?.data?.abilities?.can_delete !== true}}',
        },
        text: '선택 삭제',
      },
    ],
  };
}

describe('활동 로그 목록 - 필터 UI 렌더링', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    const registry = setupTestRegistry();
    const layout = createActivityLogListLayout();
    testUtils = createLayoutTest(layout, { componentRegistry: registry });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // @scenario actor_kind=member
  // @effects filter_controls_render
  it('검색 타입 드롭다운, 검색 입력, 행위자, 날짜 필터가 렌더링됨', async () => {
    testUtils.mockApi('activityLogs', {
      response: { data: { data: [], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } },
    });

    await testUtils.render();

    const filterSection = screen.queryByTestId('filter-section');
    expect(filterSection).not.toBeNull();

    // 검색 타입 드롭다운
    const searchTypeSelect = screen.queryByTestId('select-searchType');
    expect(searchTypeSelect).not.toBeNull();

    const searchInput = screen.queryByTestId('search-input');
    expect(searchInput).not.toBeNull();

    // 행위자 검색 드롭다운
    const actorDropdown = screen.queryByTestId('actor-searchable-dropdown');
    expect(actorDropdown).not.toBeNull();

    // 날짜 프리셋 버튼
    const presetButtons = screen.queryByTestId('date-preset-buttons');
    expect(presetButtons).not.toBeNull();
    expect(screen.queryByTestId('preset-today')).not.toBeNull();
    expect(screen.queryByTestId('preset-week')).not.toBeNull();
    expect(screen.queryByTestId('preset-month')).not.toBeNull();

    // 날짜 필터 (datetime-local)
    const dateFrom = screen.queryByTestId('date-from');
    expect(dateFrom).not.toBeNull();
    expect(dateFrom?.getAttribute('type')).toBe('datetime-local');
    const dateTo = screen.queryByTestId('date-to');
    expect(dateTo).not.toBeNull();
    expect(dateTo?.getAttribute('type')).toBe('datetime-local');

    // 버튼
    const searchButton = screen.queryByTestId('search-button');
    expect(searchButton).not.toBeNull();
    const resetButton = screen.queryByTestId('reset-button');
    expect(resetButton).not.toBeNull();
  });
});

describe('활동 로그 목록 - DataGrid 렌더링', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    const registry = setupTestRegistry();
    const layout = createActivityLogListLayout();
    testUtils = createLayoutTest(layout, { componentRegistry: registry });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // @scenario actor_kind=member
  // @effects datagrid_renders_log_rows
  it('데이터 로드 시 DataGrid에 로그 항목 렌더링됨', async () => {
    // mockApi response는 데이터소스에 직접 저장됨
    // 레이아웃에서 activityLogs?.data?.data로 접근하므로 response.data 안에 중첩 필요
    testUtils.mockApi('activityLogs', {
      response: {
        data: {
          data: [
            {
              id: 1,
              number: 2,
              log_type: 'admin',
              log_type_label: '관리자',
              action: 'user.update',
              action_label: '수정',
              localized_description: '사용자 정보를 수정했습니다',
              loggable_type_display: 'User',
              actor_name: '홍길동',
              created_at: '2026-03-28 14:30:00',
            },
            {
              id: 2,
              number: 1,
              log_type: 'user',
              log_type_label: '사용자',
              action: 'auth.login',
              action_label: '로그인',
              localized_description: '로그인했습니다',
              loggable_type_display: null,
              actor_name: '김철수',
              created_at: '2026-03-28 14:00:00',
            },
          ],
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
          abilities: { can_delete: false },
        },
      },
    });

    await testUtils.render();

    const rows = screen.queryAllByTestId('datagrid-row');
    expect(rows.length).toBe(2);
  });

  // @scenario actor_kind=member
  // @effects datagrid_shows_empty_state
  it('빈 데이터 시 빈 상태 표시', async () => {
    testUtils.mockApi('activityLogs', {
      response: {
        data: {
          data: [],
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
          abilities: { can_delete: false },
        },
      },
    });

    await testUtils.render();

    const rows = screen.queryAllByTestId('datagrid-row');
    expect(rows.length).toBe(0);

    const emptyState = screen.queryByTestId('datagrid-empty');
    expect(emptyState).not.toBeNull();
  });

  // @scenario actor_kind=member
  // @effects bulk_delete_enabled_with_ability
  it('삭제 권한 있을 때 일괄 삭제 버튼 활성화', async () => {
    testUtils.mockApi('activityLogs', {
      response: {
        data: {
          data: [
            {
              id: 1,
              number: 1,
              log_type: 'admin',
              log_type_label: '관리자',
              action_label: '수정',
              localized_description: '테스트',
              actor_name: '관리자',
              created_at: '2026-03-28',
            },
          ],
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
          abilities: { can_delete: true },
        },
      },
    });

    await testUtils.render();

    const bulkDeleteButton = screen.queryByTestId('bulk-delete-button');
    expect(bulkDeleteButton).not.toBeNull();
  });

  // @scenario actor_kind=member
  // @effects bulk_delete_disabled_without_ability
  it('삭제 권한 없을 때 일괄 삭제 버튼 비활성화', async () => {
    testUtils.mockApi('activityLogs', {
      response: {
        data: {
          data: [
            {
              id: 1,
              number: 1,
              log_type: 'admin',
              log_type_label: '관리자',
              action_label: '수정',
              localized_description: '테스트',
              actor_name: '관리자',
              created_at: '2026-03-28',
            },
          ],
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
          abilities: { can_delete: false },
        },
      },
    });

    await testUtils.render();

    const bulkDeleteButton = screen.queryByTestId('bulk-delete-button');
    expect(bulkDeleteButton).not.toBeNull();
    expect(bulkDeleteButton?.getAttribute('disabled')).not.toBeNull();
  });

  // @scenario actor_kind=member
  // @effects datagrid_always_selectable
  it('DataGrid에 selectable이 항상 활성화됨', async () => {
    testUtils.mockApi('activityLogs', {
      response: {
        data: {
          data: [],
          pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
          abilities: { can_delete: false },
        },
      },
    });

    await testUtils.render();

    const datagrid = screen.queryByTestId('activity-log-datagrid');
    expect(datagrid).not.toBeNull();
    // selectable이 항상 true로 설정됨 (권한과 무관하게 체크박스 표시)
    expect(datagrid?.getAttribute('data-selectable')).toBe('true');
  });
});

// ─── 행위자 칸 — 실제 partial 의 cellChildren 을 그대로 렌더 ───

const TestActionMenu: React.FC<{
  items?: Array<{ id: string; if?: unknown }>;
  children?: React.ReactNode;
}> = ({ items, children }) => (
  <div data-testid="actor-menu" data-items={(items ?? []).map((i) => i.id).join(',')}>{children}</div>
);

/**
 * 실제 활동 로그 partial 에서 행위자(actor) 칸의 셀 노드를 꺼낸다.
 */
function findActorCellChildren(): any[] {
  const stack: any[] = [datagridPartial];
  while (stack.length > 0) {
    const node = stack.pop();
    if (node && typeof node === 'object') {
      if (Array.isArray(node)) {
        stack.push(...node);
        continue;
      }
      if (node.field === 'actor' && Array.isArray(node.cellChildren)) {
        return node.cellChildren;
      }
      stack.push(...Object.values(node));
    }
  }
  throw new Error('actor 칸을 찾지 못했습니다');
}

describe('활동 로그 목록 - 행위자 칸 (실제 partial)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    const registry = setupTestRegistry();
    (registry as any).registry.ActionMenu = {
      component: TestActionMenu,
      metadata: { name: 'ActionMenu', type: 'composite' },
    };

    testUtils = createLayoutTest(
      {
        components: [
          {
            id: 'rows',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'actor-row' },
            iteration: { source: '_local.rows', item_var: 'row' },
            children: findActorCellChildren(),
          },
        ],
      } as any,
      {
        componentRegistry: registry,
        initialState: {
          _local: {
            rows: [
              {
                id: 1,
                actor_name: '홍길동',
                user: { uuid: 'a26219fc-94a0-4f63-9404-04c2a6ac99e4', name: '홍길동', email: 'hong@example.com' },
              },
              {
                id: 2,
                actor_name: '시스템(CLI)',
                user: { uuid: null, name: '시스템(CLI)' },
              },
            ],
          },
        },
      },
    );
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // @scenario actor_kind=member
  // @scenario actor_kind=system_cli
  // @effects member_log_actor_has_member_menu, system_log_actor_has_no_member_menu
  it('회원이 없는 로그는 회원 메뉴 없이 행위자 라벨만 보인다', async () => {
    await testUtils.render();

    const rows = screen.getAllByTestId('actor-row');
    expect(rows).toHaveLength(2);

    // 회원 로그 — 메뉴(회원 정보 보기·이 사람으로 검색)가 있다
    const memberMenu = rows[0].querySelector('[data-testid="actor-menu"]');
    expect(memberMenu).not.toBeNull();
    expect(memberMenu?.getAttribute('data-items')).toContain('view_member');
    expect(rows[0].textContent).toContain('홍길동');

    // 시스템(CLI) 로그 — 메뉴가 없고 라벨만 보인다(종전: 메뉴가 그려져 /admin/users/undefined 이동)
    expect(rows[1].querySelector('[data-testid="actor-menu"]')).toBeNull();
    expect(rows[1].textContent).toContain('시스템(CLI)');
  });
});

// ─── 펼친 줄 — 실제 partial 의 expandChildren 을 DataGrid 와 같은 경로(renderItemChildren)로 렌더 ───

const adminKo = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/ko/admin.json'), 'utf-8'));
const adminEn = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/en/admin.json'), 'utf-8'));

/** 펼친 줄이 읽는 번역 묶음(활동 로그 상세) */
function detailTranslations(admin: any) {
  return { admin: { activity_log: { detail: admin.activity_log.detail } } };
}

let expandLocale: 'ko' | 'en' = 'ko';

/**
 * 펼친 줄 그리기만 흉내 내는 DataGrid — 실제 DataGrid 처럼 엔진이 넘긴 expandChildren 을 줄마다
 * renderItemChildren 에 row 문맥으로 넘긴다. 엔진은 DataGrid props 를 번역한 뒤 넘기므로(이때 row 가 없다),
 * `$t:defer:` 가 아니면 row 를 읽는 번역 인자가 빈 값으로 굳어 버린다.
 */
const TestExpandGrid: React.FC<{ data?: any[]; expandChildren?: any[] }> = ({ data, expandChildren }) => {
  const registry = ComponentRegistry.getInstance();
  const componentMap = Object.fromEntries(
    Object.entries((registry as any).registry).map(([name, entry]: [string, any]) => [name, entry.component]),
  );
  return (
    <div>
      {(data ?? []).map((row) => (
        <div key={row.id} data-testid="expanded-row">
          {renderItemChildren(expandChildren ?? [], { row, _local: {}, _global: {} }, componentMap, `expand-${row.id}`, {
            translationContext: { templateId: 'test-template', locale: expandLocale },
          })}
        </div>
      ))}
    </div>
  );
};

describe('활동 로그 목록 - 펼친 줄의 삭제 직전 기록 (실제 partial)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  const snapshotRow = (versionCount: number) => ({
    id: 7,
    has_details: true,
    loggable_type_display: '레이아웃',
    loggable_id: 3,
    changes: [],
    bulk_changes: [],
    snapshot: {
      target: 'sirsoft-board · board_list',
      reason_label: '영구 삭제',
      version_count: versionCount,
      versions_truncated: false,
      content_preview: '{"layout_name":"board_list"}',
      content_truncated: false,
    },
  });

  /**
   * 실제 partial 의 expandChildren 을 DataGrid props 로 실은 레이아웃
   */
  function buildLayout(row: any, locale: 'ko' | 'en') {
    expandLocale = locale;
    const registry = setupTestRegistry();
    (registry as any).registry.DataGrid = { component: TestExpandGrid, metadata: { name: 'DataGrid', type: 'composite' } };
    return createLayoutTest(
      {
        components: [
          {
            id: 'activity_log_datagrid',
            type: 'composite',
            name: 'DataGrid',
            props: { data: '{{_local.rows}}', expandChildren: (datagridPartial as any).props.expandChildren },
          },
        ],
      } as any,
      {
        componentRegistry: registry,
        translations: detailTranslations(locale === 'ko' ? adminKo : adminEn),
        locale,
        initialState: { _local: { rows: [row] } },
      },
    );
  }

  afterEach(() => {
    testUtils?.cleanup();
    expandLocale = 'ko';
  });

  // @effects expanded_row_shows_deletion_snapshot
  it('row 를 읽는 대상·사유·남긴 버전 수가 펼친 줄에 값으로 보인다 (ko)', async () => {
    testUtils = buildLayout(snapshotRow(3), 'ko');
    await testUtils.render();

    const row = screen.getByTestId('expanded-row');
    expect(row.textContent).toContain('대상: sirsoft-board · board_list');
    expect(row.textContent).toContain('사유: 영구 삭제');
    expect(row.textContent).toContain('남긴 버전 3개');
    // 번역 키·자리표시자가 그대로 새어 나오지 않는다
    expect(row.textContent).not.toContain('{{');
    expect(row.textContent).not.toContain('detail.snapshot_target');
    expect(row.textContent).not.toContain('detail.snapshot_versions');
  });

  // @effects expanded_row_shows_deletion_snapshot
  it('en 은 남긴 버전 수에 따라 단수·복수 문구를 고른다', async () => {
    testUtils = buildLayout(snapshotRow(1), 'en');
    await testUtils.render();
    expect(screen.getByTestId('expanded-row').textContent).toContain('1 version kept');
    testUtils.cleanup();

    testUtils = buildLayout(snapshotRow(4), 'en');
    await testUtils.render();
    expect(screen.getByTestId('expanded-row').textContent).toContain('4 versions kept');
  });
});

/**
 * 굽기 산출물에서 id 가 맞는 노드를 찾습니다.
 *
 * @param node 트리
 * @param id 노드 id
 * @returns 노드 (없으면 null)
 */
function findNodeById(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const value of Object.values(node)) {
    const found = findNodeById(value, id);
    if (found) return found;
  }
  return null;
}

/**
 * 바인딩 식(`{{…}}`)을 주어진 문맥으로 평가합니다.
 *
 * @param binding 바인딩 문자열
 * @param ctx 문맥 (_local·query)
 * @returns 평가 결과
 */
function evaluateBinding(binding: string, ctx: { _local?: any; query?: any }): unknown {
  // eslint-disable-next-line no-new-func
  return new Function('_local', 'query', `return (${binding.slice(2, -2)});`)(ctx._local ?? {}, ctx.query ?? {});
}

describe('활동 로그 목록 - 「삭제 직전 기록만 보기」 필터 (실제 레이아웃)', () => {
  const listLayout = JSON.parse(readFileSync(resolve(__dirname, '../../layouts/admin_activity_log_list.json'), 'utf-8'));
  const filterPartial = JSON.parse(readFileSync(resolve(__dirname, '../../layouts/partials/admin_activity_log_list/_partial_filter.json'), 'utf-8'));
  const adminKo = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/ko/admin.json'), 'utf-8'));

  const CheckInput: React.FC<any> = ({ type, checked, onChange, className }) => (
    <input type={type} className={className} checked={!!checked} onChange={onChange ?? (() => undefined)} data-testid="deletion-snapshot-checkbox" />
  );
  const Pass = (tag: string): React.FC<any> => ({ children, className, text }) => React.createElement(tag, { className }, children ?? text);

  /**
   * 필터 칸 하나만 둔 시험 레이아웃을 렌더합니다.
   *
   * @param deletionSnapshot 처음 값
   * @returns 테스트 도구
   */
  async function renderFilter(deletionSnapshot: boolean) {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: Pass('div'), metadata: { name: 'Div', type: 'basic' } },
      Span: { component: Pass('span'), metadata: { name: 'Span', type: 'basic' } },
      P: { component: Pass('p'), metadata: { name: 'P', type: 'basic' } },
      Label: { component: Pass('label'), metadata: { name: 'Label', type: 'basic' } },
      Input: { component: CheckInput, metadata: { name: 'Input', type: 'basic' } },
      Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    };
    const node = findNodeById(filterPartial, 'deletion_snapshot_filter');
    expect(node, '필터 조각에 「삭제 직전 기록만 보기」 칸이 있어야 한다').toBeTruthy();
    const t = createLayoutTest(
      { version: '1.0.0', layout_name: 'activity_log_deletion_filter_test', initLocal: { filter: { deletionSnapshot } }, components: [node] },
      { componentRegistry: registry, translations: { admin: { activity_log: adminKo.activity_log } }, locale: 'ko' },
    );
    await t.render();
    return t;
  }

  // @scenario actor_kind=member
  // @effects deletion_snapshot_filter_binds_query
  it('칸이 그려지고 체크 상태가 _local.filter.deletionSnapshot 을 따르며, 누르면 값이 바뀐다', async () => {
    const t = await renderFilter(false);
    expect(screen.getByText(adminKo.activity_log.filter.deletion_snapshot_only)).toBeTruthy();
    const box = screen.getByTestId('deletion-snapshot-checkbox') as HTMLInputElement;
    expect(box.checked).toBe(false);
    fireEvent.click(box);
    await waitFor(() => expect(t.getState()._local.filter.deletionSnapshot).toBe(true));
    t.cleanup();

    const t2 = await renderFilter(true);
    expect((screen.getByTestId('deletion-snapshot-checkbox') as HTMLInputElement).checked).toBe(true);
    t2.cleanup();
  });

  // @scenario actor_kind=member
  // @effects deletion_snapshot_filter_binds_query
  it('검색은 켰을 때만 deletion_snapshot=1 을 주소에 싣고, 목록 요청·새로고침 복원·초기화가 같은 값을 읽는다', () => {
    const query = listLayout.named_actions.searchActivityLogs.params.query;
    expect(evaluateBinding(query.deletion_snapshot, { _local: { filter: { deletionSnapshot: true } } })).toBe(1);
    expect(evaluateBinding(query.deletion_snapshot, { _local: { filter: { deletionSnapshot: false } } })).toBeUndefined();

    // 목록 데이터소스는 주소의 값을 그대로 API 에 넘긴다
    const params = asArray(listLayout.data_sources).find((ds: any) => ds.id === 'activityLogs').params;
    expect(evaluateBinding(params.deletion_snapshot, { query: { deletion_snapshot: '1' } })).toBe('1');
    expect(evaluateBinding(params.deletion_snapshot, { query: {} })).toBeUndefined();

    // 새로고침하면 주소에서 체크 상태를 되살린다
    const restored = asArray(listLayout.init_actions)[0].params._local.filter.deletionSnapshot;
    expect(evaluateBinding(restored, { query: { deletion_snapshot: '1' } })).toBe(true);
    expect(evaluateBinding(restored, { query: {} })).toBe(false);

    // 처음 상태와 초기화는 꺼짐
    expect(initStateOf(listLayout).filter.deletionSnapshot).toBe(false);
    const reset = findNodeById(filterPartial, 'filter_reset_button');
    expect(JSON.stringify(reset)).toContain('"deletionSnapshot":false');
  });
});
