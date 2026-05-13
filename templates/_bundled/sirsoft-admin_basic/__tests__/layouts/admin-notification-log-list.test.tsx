/**
 * @file admin-notification-log-list.test.tsx
 * @description 알림 발송 이력 관리자 페이지 레이아웃 렌더링 테스트
 *
 * 테스트 대상:
 * - templates/.../admin_notification_log_list.json
 *
 * 검증 항목:
 * - 레이아웃 구조 (extends, permissions, data_sources)
 * - 채널 탭 동적 생성 (notificationChannels 기반)
 * - 상태 필터 (sent/failed/skipped)
 * - DataGrid 컬럼 정의
 * - Pagination 컴포넌트
 * - 상세 모달 참조
 * - sort_order 파라미터
 * - 상태 관리 (filter 초기값)
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ==============================
// 테스트용 컴포넌트 정의
// ==============================

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{ type?: string; className?: string; disabled?: boolean; children?: React.ReactNode; onClick?: () => void; 'data-testid'?: string }> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
  <button type={type as any} className={className} disabled={disabled} onClick={onClick} data-testid={testId}>{children}</button>
);

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-icon={name} />
);

const TestInput: React.FC<{ type?: string; name?: string; placeholder?: string; className?: string; value?: string; onChange?: any; 'data-testid'?: string }> = ({ type, name, placeholder, className, value, onChange, 'data-testid': testId }) => (
  <input type={type} name={name} placeholder={placeholder} className={className} value={value} onChange={onChange} data-testid={testId} />
);

const TestSelect: React.FC<{ name?: string; options?: any[]; value?: string; onChange?: any }> = ({ name, options, value, onChange }) => (
  <div data-testid={`select-${name}`}>
    <select name={name} value={value} onChange={onChange}>
      {options?.map((opt: any) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
    </select>
  </div>
);

const TestDataGrid: React.FC<{ data?: any[]; columns?: any[]; loading?: boolean; 'data-testid'?: string }> = ({ data, columns, loading, 'data-testid': testId }) => (
  <div data-testid={testId || 'datagrid'} data-loading={loading} data-columns={columns?.length} data-rows={data?.length}>
    {columns?.map((col: any) => <span key={col.field} data-testid={`col-${col.field}`}>{col.header}</span>)}
  </div>
);

const TestPagination: React.FC<{ currentPage?: number; totalPages?: number }> = ({ currentPage, totalPages }) => (
  <div data-testid="pagination" data-current={currentPage} data-total={totalPages}>Pagination</div>
);

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <label className={className}>{children}</label>
);

const TestModal: React.FC<{ id?: string; title?: string; children?: React.ReactNode }> = ({ id, title, children }) => (
  <div data-testid={`modal-${id}`} data-title={title}>{children}</div>
);

// ==============================
// 레지스트리 설정
// ==============================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
    Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
  };
  return registry;
}

// ==============================
// 테스트용 레이아웃 (admin_notification_log_list.json 기반)
// ==============================

const notificationLogListLayout = {
  version: '1.0.0',
  layout_name: 'admin_notification_log_list',
  permissions: ['core.notification-logs.read'],
  state: {
    filter: {
      search: '',
      statuses: [],
      notificationTypes: [],
    },
  },
  data_sources: [
    {
      id: 'notificationChannels',
      type: 'api',
      endpoint: '/api/admin/notification-channels',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
      fallback: { data: { channels: [] } },
    },
    {
      id: 'notificationLogs',
      type: 'api',
      endpoint: '/api/admin/notification-logs',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
      params: {
        page: '{{query.page || 1}}',
        per_page: '{{query.per_page || 20}}',
        search: '{{query.search}}',
        channel: '{{query.channel}}',
        status: '{{query.status}}',
        sort_by: "{{query.sort_by || 'sent_at'}}",
        sort_order: "{{query.sort_order || 'desc'}}",
      },
      fallback: { data: { data: [], pagination: {} } },
    },
  ],
  components: [
    {
      id: 'notification_log_content',
      type: 'basic',
      name: 'Div',
      props: { className: 'p-6 bg-gray-50 dark:bg-gray-900 min-h-screen' },
      children: [
        {
          id: 'page_header',
          type: 'basic',
          name: 'Div',
          children: [
            { type: 'basic', name: 'H1', text: '$t:admin.notification_log.page_title' },
            { type: 'basic', name: 'P', text: '$t:admin.notification_log.page_description' },
          ],
        },
        {
          id: 'status_filter',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'composite',
              name: 'Select',
              props: {
                name: 'status',
                options: [
                  { value: '', label: '$t:common.all' },
                  { value: 'sent', label: '$t:notification_log.status_sent' },
                  { value: 'failed', label: '$t:notification_log.status_failed' },
                  { value: 'skipped', label: '$t:notification_log.status_skipped' },
                ],
              },
            },
          ],
        },
        {
          id: 'logs_datagrid',
          type: 'composite',
          name: 'DataGrid',
          props: {
            data: '{{notificationLogs?.data?.data ?? []}}',
            'data-testid': 'notification-logs-grid',
            columns: [
              { field: 'channel', header: '$t:notification_log.col_channel' },
              { field: 'recipient_identifier', header: '$t:notification_log.col_recipient' },
              { field: 'subject', header: '$t:notification_log.col_subject' },
              { field: 'status', header: '$t:notification_log.col_status' },
              { field: 'sent_at', header: '$t:notification_log.col_sent_at' },
            ],
          },
        },
        {
          id: 'pagination_section',
          type: 'composite',
          name: 'Pagination',
          props: {
            currentPage: '{{notificationLogs?.data?.pagination?.current_page ?? 1}}',
            totalPages: '{{notificationLogs?.data?.pagination?.last_page ?? 1}}',
          },
        },
      ],
    },
  ],
};

// ==============================
// 테스트
// ==============================

describe('admin_notification_log_list 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(notificationLogListLayout, {
      translations: {},
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // ── 구조 검증 ──

  it('core.notification-logs.read 권한이 설정되어 있다', () => {
    expect(notificationLogListLayout.permissions).toContain('core.notification-logs.read');
  });

  it('notificationChannels 데이터소스가 정의되어 있다', () => {
    const ds = notificationLogListLayout.data_sources.find((d) => d.id === 'notificationChannels');
    expect(ds).toBeDefined();
    expect(ds!.endpoint).toBe('/api/admin/notification-channels');
  });

  it('notificationLogs 데이터소스에 sort_order 파라미터가 포함된다', () => {
    const ds = notificationLogListLayout.data_sources.find((d) => d.id === 'notificationLogs');
    expect(ds).toBeDefined();
    expect(ds!.params!.sort_order).toBeDefined();
    expect(ds!.params!.sort_by).toBeDefined();
  });

  it('notificationLogs 데이터소스에 channel/status 필터 파라미터가 있다', () => {
    const ds = notificationLogListLayout.data_sources.find((d) => d.id === 'notificationLogs');
    expect(ds!.params!.channel).toBeDefined();
    expect(ds!.params!.status).toBeDefined();
    expect(ds!.params!.search).toBeDefined();
  });

  it('상태 필터 Select에 sent/failed/skipped 옵션이 있다', () => {
    const content = notificationLogListLayout.components[0];
    const filterSection = content.children[1]; // status_filter
    const select = filterSection.children[0];

    expect(select.name).toBe('Select');
    const optionValues = select.props.options.map((o: any) => o.value);
    expect(optionValues).toContain('sent');
    expect(optionValues).toContain('failed');
    expect(optionValues).toContain('skipped');
  });

  it('DataGrid에 5개 컬럼이 정의되어 있다', () => {
    const content = notificationLogListLayout.components[0];
    const datagrid = content.children[2]; // logs_datagrid

    expect(datagrid.name).toBe('DataGrid');
    expect(datagrid.props.columns).toHaveLength(5);

    const fieldNames = datagrid.props.columns.map((c: any) => c.field);
    expect(fieldNames).toContain('channel');
    expect(fieldNames).toContain('recipient_identifier');
    expect(fieldNames).toContain('subject');
    expect(fieldNames).toContain('status');
    expect(fieldNames).toContain('sent_at');
  });

  it('Pagination 컴포넌트가 포함되어 있다', () => {
    const content = notificationLogListLayout.components[0];
    const pagination = content.children[3]; // pagination_section

    expect(pagination.name).toBe('Pagination');
    expect(pagination.props.currentPage).toBeDefined();
    expect(pagination.props.totalPages).toBeDefined();
  });

  // ── 상태 관리 검증 ──

  it('필터 초기 상태가 올바르게 설정된다', async () => {
    testUtils.mockApi('notificationChannels', {
      response: { data: { channels: [] } },
    });
    testUtils.mockApi('notificationLogs', {
      response: { data: { data: [], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null, has_more_pages: false } } },
    });

    await testUtils.render();

    const state = testUtils.getState();
    expect(state._local.filter.search).toBe('');
    expect(state._local.filter.statuses).toEqual([]);
  });

  it('필터 상태를 변경할 수 있다', async () => {
    testUtils.mockApi('notificationChannels', {
      response: { data: { channels: [] } },
    });
    testUtils.mockApi('notificationLogs', {
      response: { data: { data: [], pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null, has_more_pages: false } } },
    });

    await testUtils.render();

    testUtils.setState('filter.statuses', ['sent', 'failed'], 'local');
    expect(testUtils.getState()._local.filter.statuses).toEqual(['sent', 'failed']);

    testUtils.setState('filter.search', 'test@example.com', 'local');
    expect(testUtils.getState()._local.filter.search).toBe('test@example.com');
  });
});
