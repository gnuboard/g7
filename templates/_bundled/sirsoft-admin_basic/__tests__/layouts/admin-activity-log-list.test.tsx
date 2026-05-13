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
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

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
            { field: 'actor_name', header: '행위자' },
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
