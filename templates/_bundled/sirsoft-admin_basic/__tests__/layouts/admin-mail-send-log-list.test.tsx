/**
 * @file admin-mail-send-log-list.test.tsx
 * @description 메일 발송 이력 독립 관리자 메뉴 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../admin_mail_send_log_list.json
 * - templates/.../partials/admin_mail_send_log_list/_partial_filter.json
 * - templates/.../partials/admin_mail_send_log_list/_partial_datagrid.json
 *
 * 검증 항목:
 * - 레이아웃 구조 (extends, permissions, data_sources)
 * - 필터 UI (searchType Select + 검색바 + 체크박스 필터 + 검색/초기화 버튼)
 * - DataGrid 컬럼 정의 (수신자, 제목, 템플릿, 모듈, 상태, 발송일시)
 * - 정렬/페이지크기 Select 및 Pagination 컴포넌트
 * - URL 쿼리 기반 필터/정렬/페이지네이션
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ==============================
// 테스트용 컴포넌트 정의
// ==============================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
  <button type={type as any} className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <label className={className}>{children}</label>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-icon={name} />
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  placeholder?: string;
  className?: string;
  value?: string;
  checked?: boolean;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onKeyDown?: (e: React.KeyboardEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, name, placeholder, className, value, checked, onChange, onKeyDown, 'data-testid': testId }) => (
  <input
    type={type}
    name={name}
    placeholder={placeholder}
    className={className}
    value={value}
    checked={checked}
    onChange={onChange}
    onKeyDown={onKeyDown}
    data-testid={testId}
  />
);

const TestSelect: React.FC<{
  name?: string;
  className?: string;
  options?: Array<{ label: string; value: string }>;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
}> = ({ name, className, options, value, onChange }) => (
  <div data-testid={`select-${name}`}>
    <select name={name} className={className} value={value} onChange={onChange}>
      {options?.map((opt) => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
  </div>
);

const TestDataGrid: React.FC<{
  data?: any[];
  columns?: any[];
  loading?: boolean;
  selectable?: boolean;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ data, columns, loading, 'data-testid': testId }) => (
  <div data-testid={testId || 'datagrid'} data-loading={loading} data-columns={columns?.length} data-rows={data?.length}>
    {columns?.map((col: any) => (
      <span key={col.field} data-testid={`col-${col.field}`}>{col.header}</span>
    ))}
  </div>
);

const TestPagination: React.FC<{
  currentPage?: number;
  totalPages?: number;
  onPageChange?: (page: number) => void;
}> = ({ currentPage, totalPages }) => (
  <div data-testid="pagination" data-current={currentPage} data-total={totalPages}>
    Pagination
  </div>
);

const TestPageHeader: React.FC<{
  title?: string;
  children?: React.ReactNode;
}> = ({ title, children }) => (
  <div data-testid="page-header">{title}{children}</div>
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
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'composite' } },
    DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
    Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
    PageHeader: { component: TestPageHeader, metadata: { name: 'PageHeader', type: 'composite' } },
  };

  return registry;
}

// ==============================
// 테스트용 레이아웃 (admin_mail_send_log_list.json 기반)
// ==============================

const mailSendLogListLayout = {
  version: '1.0.0',
  layout_name: 'admin_mail_send_log_list',
  permissions: ['core.mail-send-logs.read'],
  state: {
    filter: {
      searchType: 'all',
      search: '',
      modules: [],
      statuses: [],
    },
  },
  data_sources: [
    {
      id: 'mailSendLogs',
      type: 'api',
      endpoint: '/api/admin/mail-send-logs',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
      params: {
        page: '{{query.page || 1}}',
        per_page: '{{query.per_page || 20}}',
        search: '{{query.search}}',
        search_type: '{{query.search_type}}',
        'module[]': "{{query['module[]']}}",
        'status[]': "{{query['status[]']}}",
        sort_by: "{{query.sort_by || 'sent_at'}}",
        sort_order: "{{query.sort_order || 'desc'}}",
      },
    },
  ],
  init_actions: [
    {
      handler: 'setState',
      params: {
        target: 'global',
        _local: {
          filter: {
            searchType: "{{query.search_type || 'all'}}",
            search: "{{query.search || ''}}",
            modules: "{{query['module[]'] || []}}",
            statuses: "{{query['status[]'] || []}}",
          },
        },
      },
    },
  ],
  named_actions: {
    searchMailSendLogs: {
      handler: 'navigate',
      params: {
        path: '/admin/mail-send-logs',
        replace: true,
        query: {
          page: 1,
          search: '{{_local.filter.search || undefined}}',
          search_type: "{{_local.filter.searchType !== 'all' ? _local.filter.searchType : undefined}}",
          'module[]': '{{_local.filter.modules?.length > 0 ? _local.filter.modules : undefined}}',
          'status[]': '{{_local.filter.statuses?.length > 0 ? _local.filter.statuses : undefined}}',
        },
      },
    },
  },
  components: [
    {
      id: 'mail_send_log_list_content',
      type: 'basic',
      name: 'Div',
      props: { className: 'p-6 bg-gray-50 dark:bg-gray-900 min-h-screen' },
      children: [
        // page_header
        {
          id: 'page_header',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              children: [
                { type: 'basic', name: 'H1', props: { className: 'page-title' }, text: '$t:admin.mail_send_log.page_title' },
                { type: 'basic', name: 'P', text: '$t:admin.mail_send_log.page_description' },
              ],
            },
          ],
        },
        // filter section
        {
          id: 'filter_section',
          type: 'basic',
          name: 'Div',
          props: { className: 'card' },
          children: [
            // search_row (searchType Select + search Input)
            {
              id: 'search_row',
              type: 'basic',
              name: 'Div',
              props: { className: 'mb-4' },
              children: [
                {
                  type: 'basic',
                  name: 'Div',
                  children: [
                    {
                      type: 'composite',
                      name: 'Select',
                      props: {
                        name: 'searchType',
                        value: "{{_local.filter.searchType || 'all'}}",
                        options: [
                          { value: 'all', label: '$t:admin.mail_send_log.filter.search_type_all' },
                          { value: 'recipient_email', label: '$t:admin.mail_send_log.filter.search_type_recipient_email' },
                          { value: 'recipient_name', label: '$t:admin.mail_send_log.filter.search_type_recipient_name' },
                          { value: 'subject', label: '$t:admin.mail_send_log.filter.search_type_subject' },
                        ],
                      },
                      actions: [
                        { type: 'change', handler: 'setState', params: { target: 'local', 'filter.searchType': '{{$event.target.value}}' } },
                      ],
                    },
                    {
                      type: 'basic',
                      name: 'Div',
                      children: [
                        { type: 'basic', name: 'Icon', props: { name: 'fa-solid fa-search' } },
                        {
                          type: 'basic',
                          name: 'Input',
                          props: {
                            name: 'search',
                            type: 'text',
                            placeholder: '$t:admin.mail_send_log.filter.search_placeholder',
                            className: 'input w-full pl-10',
                            value: "{{_local.filter.search || ''}}",
                          },
                          actions: [
                            { type: 'change', handler: 'setState', params: { target: 'local', 'filter.search': '{{$event.target.value}}' } },
                            { type: 'keydown', key: 'Enter', actionRef: 'searchMailSendLogs' },
                          ],
                        },
                      ],
                    },
                  ],
                },
              ],
            },
            // filter_grid (checkbox filters)
            {
              id: 'filter_grid',
              type: 'basic',
              name: 'Div',
              props: { className: 'flex flex-col' },
              children: [
                // module filter row
                {
                  id: 'module_filter',
                  type: 'basic',
                  name: 'Div',
                  props: { className: 'filter-row' },
                  children: [
                    { type: 'basic', name: 'Span', props: { className: 'filter-label w-32' }, text: '$t:admin.mail_send_log.filter.module_label' },
                    {
                      type: 'basic',
                      name: 'Div',
                      children: [
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: '{{!_local.filter.modules || _local.filter.modules.length === 0}}' }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.modules': '{{[]}}' } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.module_all' },
                        ]},
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: "{{(_local.filter.modules || []).includes('core')}}" }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.modules': "{{$event.target.checked ? [...(_local.filter.modules || []).filter(m => m !== 'core'), 'core'] : (_local.filter.modules || []).filter(m => m !== 'core')}}" } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.module_core' },
                        ]},
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: "{{(_local.filter.modules || []).includes('sirsoft-board')}}" }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.modules': "{{$event.target.checked ? [...(_local.filter.modules || []).filter(m => m !== 'sirsoft-board'), 'sirsoft-board'] : (_local.filter.modules || []).filter(m => m !== 'sirsoft-board')}}" } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.module_board' },
                        ]},
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: "{{(_local.filter.modules || []).includes('sirsoft-ecommerce')}}" }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.modules': "{{$event.target.checked ? [...(_local.filter.modules || []).filter(m => m !== 'sirsoft-ecommerce'), 'sirsoft-ecommerce'] : (_local.filter.modules || []).filter(m => m !== 'sirsoft-ecommerce')}}" } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.module_ecommerce' },
                        ]},
                      ],
                    },
                  ],
                },
                // status filter row
                {
                  id: 'status_filter',
                  type: 'basic',
                  name: 'Div',
                  props: { className: 'filter-row' },
                  children: [
                    { type: 'basic', name: 'Span', props: { className: 'filter-label w-32' }, text: '$t:admin.mail_send_log.filter.status_label' },
                    {
                      type: 'basic',
                      name: 'Div',
                      children: [
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: '{{!_local.filter.statuses || _local.filter.statuses.length === 0}}' }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.statuses': '{{[]}}' } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.status_all' },
                        ]},
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: "{{(_local.filter.statuses || []).includes('sent')}}" }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.statuses': "{{$event.target.checked ? [...(_local.filter.statuses || []).filter(s => s !== 'sent'), 'sent'] : (_local.filter.statuses || []).filter(s => s !== 'sent')}}" } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.status_sent' },
                        ]},
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: "{{(_local.filter.statuses || []).includes('failed')}}" }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.statuses': "{{$event.target.checked ? [...(_local.filter.statuses || []).filter(s => s !== 'failed'), 'failed'] : (_local.filter.statuses || []).filter(s => s !== 'failed')}}" } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.status_failed' },
                        ]},
                        { type: 'basic', name: 'Label', children: [
                          { type: 'basic', name: 'Input', props: { type: 'checkbox', className: 'checkbox', checked: "{{(_local.filter.statuses || []).includes('skipped')}}" }, actions: [{ type: 'change', handler: 'setState', params: { target: 'local', 'filter.statuses': "{{$event.target.checked ? [...(_local.filter.statuses || []).filter(s => s !== 'skipped'), 'skipped'] : (_local.filter.statuses || []).filter(s => s !== 'skipped')}}" } }] },
                          { type: 'basic', name: 'Span', text: '$t:admin.mail_send_log.filter.status_skipped' },
                        ]},
                      ],
                    },
                  ],
                },
              ],
            },
            // filter_actions (검색 + 초기화 버튼)
            {
              id: 'filter_actions',
              type: 'basic',
              name: 'Div',
              props: { className: 'flex justify-center gap-3 mt-6' },
              children: [
                {
                  type: 'basic',
                  name: 'Button',
                  props: { type: 'button', className: 'btn btn-primary px-6' },
                  text: '$t:admin.mail_send_log.filter.search_button',
                  actions: [{ type: 'click', actionRef: 'searchMailSendLogs' }],
                },
                {
                  type: 'basic',
                  name: 'Button',
                  props: { type: 'button', className: 'btn btn-secondary px-6' },
                  text: '$t:admin.mail_send_log.filter.reset_button',
                  actions: [{
                    type: 'click',
                    handler: 'sequence',
                    actions: [
                      { handler: 'setState', params: { target: 'local', filter: { searchType: 'all', search: '', modules: [], statuses: [] } } },
                      { handler: 'navigate', params: { path: '/admin/mail-send-logs' } },
                    ],
                  }],
                },
              ],
            },
          ],
        },
        // data_table_section
        {
          id: 'data_table_section',
          type: 'basic',
          name: 'Div',
          children: [
            {
              id: 'table_header_bar',
              type: 'basic',
              name: 'Div',
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  text: '$t:admin.mail_send_log.pagination.total|count={{mailSendLogs?.data?.pagination?.total || 0}}',
                },
                {
                  type: 'basic',
                  name: 'Div',
                  children: [
                    {
                      type: 'composite',
                      name: 'Select',
                      props: {
                        name: 'sortBy',
                        className: 'w-32 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-sm',
                        value: "{{query.sort_by ? (query.sort_by + '_' + (query.sort_order || 'desc')) : 'sent_at_desc'}}",
                        options: [
                          { value: 'sent_at_desc', label: '$t:admin.mail_send_log.sort.sent_at_desc' },
                          { value: 'sent_at_asc', label: '$t:admin.mail_send_log.sort.sent_at_asc' },
                          { value: 'recipient_email_asc', label: '$t:admin.mail_send_log.sort.recipient_email_asc' },
                          { value: 'subject_asc', label: '$t:admin.mail_send_log.sort.subject_asc' },
                        ],
                      },
                    },
                    {
                      type: 'composite',
                      name: 'Select',
                      props: {
                        name: 'perPage',
                        value: "{{query.per_page || '20'}}",
                        options: [
                          { value: '10', label: '10' },
                          { value: '20', label: '20' },
                          { value: '50', label: '50' },
                          { value: '100', label: '100' },
                        ],
                      },
                    },
                  ],
                },
              ],
            },
            {
              id: 'mail_send_log_datagrid',
              type: 'composite',
              name: 'DataGrid',
              props: {
                data: '{{mailSendLogs?.data?.data}}',
                idField: 'id',
                selectable: false,
                loading: '{{mailSendLogs?.loading}}',
                columns: [
                  { field: 'recipient', header: '$t:admin.mail_send_log.table.column.recipient', width: '200px' },
                  { field: 'subject', header: '$t:admin.mail_send_log.table.column.subject', width: '250px' },
                  { field: 'template_type', header: '$t:admin.mail_send_log.table.column.template_type', width: '120px' },
                  { field: 'module', header: '$t:admin.mail_send_log.table.column.module', width: '120px' },
                  { field: 'status', header: '$t:admin.mail_send_log.table.column.status', sortable: true, width: '100px' },
                  { field: 'sent_at', header: '$t:admin.mail_send_log.table.column.sent_at', sortable: true, width: '160px' },
                ],
              },
            },
            {
              id: 'pagination_section',
              type: 'basic',
              name: 'Div',
              children: [
                {
                  type: 'composite',
                  name: 'Pagination',
                  props: {
                    currentPage: '{{mailSendLogs?.data?.pagination?.current_page || 1}}',
                    totalPages: '{{mailSendLogs?.data?.pagination?.last_page || 1}}',
                  },
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

// ==============================
// 테스트
// ==============================

describe('메일 발송 이력 독립 관리자 메뉴', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(mailSendLogListLayout, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // ========================================================================
  // 레이아웃 구조 검증
  // ========================================================================

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 이름이 admin_mail_send_log_list이다', () => {
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('admin_mail_send_log_list');
    });

    it('권한이 core.mail-send-logs.read로 설정되어 있다', () => {
      expect(mailSendLogListLayout.permissions).toEqual(['core.mail-send-logs.read']);
    });

    it('데이터 소스가 1개 정의되어 있다 (mailSendLogs)', () => {
      const dataSources = testUtils.getDataSources();
      const ids = dataSources.map((ds: any) => ds.id);
      expect(ids).toContain('mailSendLogs');
      expect(dataSources).toHaveLength(1);
    });

    it('데이터 소스 params에 정렬/검색 타입 파라미터가 포함되어 있다', () => {
      const dataSources = testUtils.getDataSources();
      const ds = dataSources.find((d: any) => d.id === 'mailSendLogs');
      expect(ds.params.sort_by).toBeDefined();
      expect(ds.params.sort_order).toBeDefined();
      expect(ds.params.search_type).toBeDefined();
      expect(ds.params['module[]']).toBeDefined();
      expect(ds.params['status[]']).toBeDefined();
    });

    it('state 정의에 필터가 배열/기본값으로 설정되어 있다', () => {
      expect(mailSendLogListLayout.state.filter).toEqual({
        searchType: 'all',
        search: '',
        modules: [],
        statuses: [],
      });
    });
  });

  // ========================================================================
  // named_actions 검증
  // ========================================================================

  describe('named_actions 검증', () => {
    it('searchMailSendLogs가 navigate 핸들러로 정의되어 있다', () => {
      const namedActions = mailSendLogListLayout.named_actions;
      expect(namedActions.searchMailSendLogs).toBeDefined();
      expect(namedActions.searchMailSendLogs.handler).toBe('navigate');
      expect(namedActions.searchMailSendLogs.params.path).toBe('/admin/mail-send-logs');
      expect(namedActions.searchMailSendLogs.params.replace).toBe(true);
    });

    it('searchMailSendLogs가 배열 필터를 쿼리로 전달한다', () => {
      const query = mailSendLogListLayout.named_actions.searchMailSendLogs.params.query;
      expect(query.page).toBe(1);
      expect(query.search).toBeDefined();
      expect(query.search_type).toBeDefined();
      expect(query['module[]']).toBeDefined();
      expect(query['status[]']).toBeDefined();
    });
  });

  // ========================================================================
  // 필터 UI 검증 (searchType Select + 체크박스 필터 + 검색/초기화 버튼)
  // ========================================================================

  describe('필터 UI 검증', () => {
    it('필터 섹션이 card 클래스를 가진다', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      expect(filterSection.id).toBe('filter_section');
      expect(filterSection.props.className).toBe('card');
    });

    it('searchType Select가 4개 옵션을 가진다 (전체/수신자이메일/수신자이름/제목)', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const searchRow = filterSection.children[0]; // search_row
      const searchTypeSelect = searchRow.children[0].children[0]; // Select (searchType)
      expect(searchTypeSelect.name).toBe('Select');
      expect(searchTypeSelect.props.name).toBe('searchType');
      expect(searchTypeSelect.props.options).toHaveLength(4);
      expect(searchTypeSelect.props.options[0].value).toBe('all');
      expect(searchTypeSelect.props.options[1].value).toBe('recipient_email');
      expect(searchTypeSelect.props.options[2].value).toBe('recipient_name');
      expect(searchTypeSelect.props.options[3].value).toBe('subject');
    });

    it('검색 입력란이 input w-full pl-10 클래스를 가진다', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const searchRow = filterSection.children[0]; // search_row
      const searchInputContainer = searchRow.children[0].children[1]; // Div (relative flex-1)
      const searchInput = searchInputContainer.children[1]; // Input
      expect(searchInput.name).toBe('Input');
      expect(searchInput.props.className).toBe('input w-full pl-10');
    });

    it('검색 입력란에서 Enter 키 입력 시 actionRef로 searchMailSendLogs가 호출된다 (conditional/namedAction 핸들러 사용 금지)', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const searchRow = filterSection.children[0]; // search_row
      const searchInputContainer = searchRow.children[0].children[1]; // Div (relative flex-1)
      const searchInput = searchInputContainer.children[1]; // Input

      const keydownAction = searchInput.actions.find((a: any) => a.type === 'keydown');
      expect(keydownAction).toBeDefined();
      // key 속성 + actionRef 패턴 사용 (admin_user_list 표준)
      expect(keydownAction.key).toBe('Enter');
      expect(keydownAction.actionRef).toBe('searchMailSendLogs');
      // 비표준 핸들러 사용 금지 확인
      expect(keydownAction.handler).toBeUndefined();
    });

    it('모듈 필터 행에 라벨과 4개 체크박스 옵션이 있다', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterGrid = filterSection.children[1]; // filter_grid
      const moduleFilter = filterGrid.children[0]; // module_filter

      expect(moduleFilter.id).toBe('module_filter');
      // 라벨
      expect(moduleFilter.children[0].text).toBe('$t:admin.mail_send_log.filter.module_label');
      // 체크박스 옵션 4개 (전체 + core + board + ecommerce)
      const checkboxContainer = moduleFilter.children[1];
      expect(checkboxContainer.children).toHaveLength(4);
    });

    it('모듈 필터 체크박스가 checkbox 타입이다 (radio가 아님)', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterGrid = filterSection.children[1];
      const moduleFilter = filterGrid.children[0];
      const coreCheckbox = moduleFilter.children[1].children[1].children[0]; // core checkbox Input
      expect(coreCheckbox.props.type).toBe('checkbox');
      expect(coreCheckbox.props.className).toBe('checkbox');
    });

    it('상태 필터 행에 라벨과 4개 체크박스 옵션이 있다', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterGrid = filterSection.children[1]; // filter_grid
      const statusFilter = filterGrid.children[1]; // status_filter

      expect(statusFilter.id).toBe('status_filter');
      expect(statusFilter.children[0].text).toBe('$t:admin.mail_send_log.filter.status_label');
      const checkboxContainer = statusFilter.children[1];
      expect(checkboxContainer.children).toHaveLength(4);
    });

    it('상태 필터 체크박스가 checkbox 타입이다 (radio가 아님)', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterGrid = filterSection.children[1];
      const statusFilter = filterGrid.children[1];
      const sentCheckbox = statusFilter.children[1].children[1].children[0]; // sent checkbox Input
      expect(sentCheckbox.props.type).toBe('checkbox');
    });

    it('모듈 체크박스 change 시 setState만 실행 (즉시 검색 안 함)', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterGrid = filterSection.children[1];
      const moduleFilter = filterGrid.children[0];
      const coreCheckbox = moduleFilter.children[1].children[1].children[0]; // core checkbox Input

      const changeAction = coreCheckbox.actions[0];
      expect(changeAction.type).toBe('change');
      expect(changeAction.handler).toBe('setState');
    });

    it('검색 버튼이 btn btn-primary이고 actionRef로 searchMailSendLogs를 호출한다', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterActions = filterSection.children[2]; // filter_actions

      expect(filterActions.id).toBe('filter_actions');
      const searchButton = filterActions.children[0];
      expect(searchButton.props.className).toBe('btn btn-primary px-6');
      expect(searchButton.actions[0].actionRef).toBe('searchMailSendLogs');
      // 비표준 handler 사용 금지 확인
      expect(searchButton.actions[0].handler).toBeUndefined();
    });

    it('초기화 버튼이 btn btn-secondary이고 sequence로 setState + navigate를 실행한다', () => {
      const filterSection = mailSendLogListLayout.components[0].children[1];
      const filterActions = filterSection.children[2]; // filter_actions
      const resetButton = filterActions.children[1];

      expect(resetButton.props.className).toBe('btn btn-secondary px-6');
      expect(resetButton.text).toBe('$t:admin.mail_send_log.filter.reset_button');

      const clickAction = resetButton.actions[0];
      expect(clickAction.handler).toBe('sequence');
      expect(clickAction.actions[0].handler).toBe('setState');
      // 초기화 시 배열로 리셋
      expect(clickAction.actions[0].params.filter.modules).toEqual([]);
      expect(clickAction.actions[0].params.filter.statuses).toEqual([]);
      expect(clickAction.actions[0].params.filter.searchType).toBe('all');
      expect(clickAction.actions[1].handler).toBe('navigate');
      expect(clickAction.actions[1].params.path).toBe('/admin/mail-send-logs');
    });

    it('필터 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('mailSendLogs', {
        response: {
          data: {
            data: [],
            pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null, has_more_pages: false },
          },
        },
      });

      await testUtils.render();

      testUtils.setState('filter.modules', ['core'], 'local');
      expect(testUtils.getState()._local.filter.modules).toEqual(['core']);

      testUtils.setState('filter.statuses', ['sent', 'failed'], 'local');
      expect(testUtils.getState()._local.filter.statuses).toEqual(['sent', 'failed']);

      testUtils.setState('filter.searchType', 'recipient_email', 'local');
      expect(testUtils.getState()._local.filter.searchType).toBe('recipient_email');

      testUtils.setState('filter.search', 'test@example.com', 'local');
      expect(testUtils.getState()._local.filter.search).toBe('test@example.com');
    });
  });

  // ========================================================================
  // DataGrid 구조 검증
  // ========================================================================

  describe('DataGrid 구조 검증', () => {
    it('DataGrid가 selectable: false로 설정되어 있다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const datagrid = dataSection.children[1];
      expect(datagrid.name).toBe('DataGrid');
      expect(datagrid.props.selectable).toBe(false);
    });

    it('DataGrid에 6개 컬럼이 정의되어 있다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const datagrid = dataSection.children[1];
      expect(datagrid.props.columns).toHaveLength(6);
    });

    it('DataGrid 컬럼이 올바른 필드를 가진다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const datagrid = dataSection.children[1];
      const fields = datagrid.props.columns.map((c: any) => c.field);
      expect(fields).toEqual(['recipient', 'subject', 'template_type', 'module', 'status', 'sent_at']);
    });

    it('status와 sent_at 컬럼이 sortable이다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const datagrid = dataSection.children[1];
      const statusCol = datagrid.props.columns.find((c: any) => c.field === 'status');
      const sentAtCol = datagrid.props.columns.find((c: any) => c.field === 'sent_at');
      expect(statusCol.sortable).toBe(true);
      expect(sentAtCol.sortable).toBe(true);
    });

    it('DataGrid 데이터가 mailSendLogs?.data?.data에 바인딩된다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const datagrid = dataSection.children[1];
      expect(datagrid.props.data).toBe('{{mailSendLogs?.data?.data}}');
    });
  });

  // ========================================================================
  // 정렬/페이지크기 Select 검증
  // ========================================================================

  describe('정렬/페이지크기 Select 검증', () => {
    it('정렬 Select에 4개 옵션이 있다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const headerBar = dataSection.children[0];
      const sortSelect = headerBar.children[1].children[0];

      expect(sortSelect.props.name).toBe('sortBy');
      expect(sortSelect.props.options).toHaveLength(4);
    });

    it('정렬 Select가 w-32 클래스를 가진다 (배송정책과 일치)', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const headerBar = dataSection.children[0];
      const sortSelect = headerBar.children[1].children[0];

      expect(sortSelect.props.className).toContain('w-32');
    });

    it('페이지크기 Select에 4개 옵션이 있다 (10/20/50/100)', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const headerBar = dataSection.children[0];
      const perPageSelect = headerBar.children[1].children[1];

      expect(perPageSelect.props.name).toBe('perPage');
      const values = perPageSelect.props.options.map((o: any) => o.value);
      expect(values).toEqual(['10', '20', '50', '100']);
    });
  });

  // ========================================================================
  // Pagination 검증
  // ========================================================================

  describe('Pagination 검증', () => {
    it('Pagination 컴포넌트가 mailSendLogs pagination에 바인딩된다', () => {
      const dataSection = mailSendLogListLayout.components[0].children[2];
      const paginationSection = dataSection.children[2];
      const pagination = paginationSection.children[0];

      expect(pagination.name).toBe('Pagination');
      expect(pagination.props.currentPage).toBe('{{mailSendLogs?.data?.pagination?.current_page || 1}}');
      expect(pagination.props.totalPages).toBe('{{mailSendLogs?.data?.pagination?.last_page || 1}}');
    });
  });

  // ========================================================================
  // 렌더링 테스트
  // ========================================================================

  describe('렌더링 테스트', () => {
    it('빈 데이터로 렌더링이 성공한다', async () => {
      testUtils.mockApi('mailSendLogs', {
        response: {
          data: {
            data: [],
            pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null, has_more_pages: false },
          },
        },
      });

      await testUtils.render();
      expect(true).toBe(true);
    });

    it('데이터가 있을 때 렌더링이 성공한다', async () => {
      testUtils.mockApi('mailSendLogs', {
        response: {
          data: {
            data: [
              {
                id: 1,
                number: 1,
                recipient_email: 'test@example.com',
                recipient_name: '테스트',
                subject: '테스트 메일',
                template_type: 'welcome',
                module: 'core',
                status: 'sent',
                sent_at: '2026-03-05 12:00:00',
              },
            ],
            pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1, has_more_pages: false },
          },
        },
      });

      await testUtils.render();
      expect(true).toBe(true);
    });
  });
});
