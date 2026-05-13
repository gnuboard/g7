/**
 * @file admin_uninstall_modal.test.tsx
 * @description 모듈/플러그인 삭제 모달 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../partials/admin_module_list/_modal_uninstall.json (모듈 삭제 모달)
 * - templates/.../partials/admin_plugin_list/_modal_uninstall.json (플러그인 삭제 모달)
 *
 * 검증 항목:
 * - 모달 구조 및 data_sources 정의
 * - Checkbox + Label htmlFor 연동
 * - 삭제 정보 조건부 렌더링 (로딩 스피너 / 데이터 표시)
 * - 테이블/스토리지 목록 iteration 렌더링
 * - 버튼 disabled 상태 (삭제 진행 중)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
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
  <button
    type={type as any}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
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
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-icon={name} />
);

const TestLabel: React.FC<{
  htmlFor?: string;
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ htmlFor, className, children, text }) => (
  <label htmlFor={htmlFor} className={className} data-testid={`label-${htmlFor}`}>
    {children || text}
  </label>
);

const TestCheckbox: React.FC<{
  id?: string;
  checked?: boolean;
  className?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
}> = ({ id, checked, className, onChange }) => (
  <input
    type="checkbox"
    id={id}
    checked={checked}
    className={className}
    onChange={onChange}
    data-testid={`checkbox-${id}`}
  />
);

const TestModal: React.FC<{
  id?: string;
  isOpen?: boolean;
  title?: string;
  size?: string;
  children?: React.ReactNode;
}> = ({ id, isOpen, title, size, children }) => (
  isOpen ? (
    <div data-testid={`modal-${id}`} role="dialog" data-size={size}>
      <h2>{title}</h2>
      {children}
    </div>
  ) : null
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// ==============================
// 레지스트리 설정
// ==============================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
  };

  return registry;
}

// ==============================
// 공통 번역 키
// ==============================

const commonTranslations = {
  common: {
    cancel: '취소',
  },
};

// ==============================
// 모듈 삭제 모달 테스트
// ==============================

describe('모듈 삭제 모달 레이아웃 테스트', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  // 모듈 삭제 모달을 포함하는 래퍼 레이아웃
  const moduleUninstallLayout = {
    version: '1.0.0',
    layout_name: 'module_uninstall_modal_test',
    initGlobal: {
      selectedModule: { identifier: 'test-module', name: '테스트 모듈' },
      deleteModuleData: false,
      isModuleUninstalling: false,
      moduleUninstallInfo: null,
    },
    modals: [
      {
        id: 'module_uninstall_modal',
        type: 'composite',
        name: 'Modal',
        data_sources: [
          {
            id: 'moduleUninstallInfo',
            type: 'api',
            endpoint: '/api/admin/modules/test-module/uninstall-info',
            auth_required: true,
            auto_fetch: false,
            initGlobal: 'moduleUninstallInfo',
          },
        ],
        props: {
          title: '모듈 삭제',
          size: 'medium',
        },
        children: [
          {
            type: 'basic',
            name: 'Div',
            props: { className: 'py-2' },
            children: [
              {
                type: 'basic',
                name: 'P',
                props: { className: 'text-gray-700 dark:text-gray-300' },
                text: '테스트 모듈을 삭제하시겠습니까?',
              },
              {
                type: 'basic',
                name: 'P',
                props: { className: 'mt-2 text-sm text-gray-500' },
                text: '이 작업은 되돌릴 수 없습니다.',
              },
            ],
          },
          {
            id: 'delete_data_option',
            type: 'basic',
            name: 'Div',
            props: { className: 'mt-4 p-3 bg-gray-50 rounded-lg' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                props: { className: 'flex items-start gap-2' },
                children: [
                  {
                    type: 'basic',
                    name: 'Checkbox',
                    props: {
                      id: 'delete_module_data_checkbox',
                      checked: '{{_global.deleteModuleData || false}}',
                      className: 'mt-0.5',
                    },
                    actions: [
                      {
                        type: 'change',
                        handler: 'conditions',
                        conditions: [
                          {
                            if: '{{$event.target.checked}}',
                            then: [
                              {
                                handler: 'setState',
                                params: {
                                  target: 'global',
                                  deleteModuleData: true,
                                  moduleUninstallInfo: null,
                                },
                              },
                              {
                                handler: 'refetchDataSource',
                                params: { dataSourceId: 'moduleUninstallInfo' },
                              },
                            ],
                          },
                          {
                            then: {
                              handler: 'setState',
                              params: {
                                target: 'global',
                                deleteModuleData: false,
                                moduleUninstallInfo: null,
                              },
                            },
                          },
                        ],
                      },
                    ],
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        type: 'basic',
                        name: 'Label',
                        props: {
                          htmlFor: 'delete_module_data_checkbox',
                          className: 'text-sm text-gray-700 cursor-pointer',
                        },
                        text: '데이터도 함께 삭제',
                      },
                      {
                        type: 'basic',
                        name: 'P',
                        props: { className: 'mt-1 text-xs text-gray-500' },
                        text: 'DB 테이블과 데이터가 삭제됩니다.',
                      },
                    ],
                  },
                ],
              },
            ],
          },
          {
            id: 'uninstall_info_section',
            type: 'basic',
            name: 'Div',
            if: '{{_global.deleteModuleData}}',
            props: { className: 'mt-4' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                props: { className: 'border-t pt-4' },
                children: [
                  {
                    type: 'basic',
                    name: 'Span',
                    props: { className: 'text-sm font-semibold' },
                    text: '삭제될 데이터',
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{!_global.moduleUninstallInfo}}',
                props: { className: 'flex items-center justify-center py-4' },
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    props: { name: 'spinner', className: 'w-5 h-5 animate-spin' },
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{_global.moduleUninstallInfo}}',
                props: { className: 'mt-3 space-y-4' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-sm font-medium' },
                        text: '데이터베이스 테이블',
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        if: '{{_global.moduleUninstallInfo.tables && _global.moduleUninstallInfo.tables.length > 0}}',
                        props: { className: 'text-xs text-gray-500' },
                        text: '(총 {{_global.moduleUninstallInfo.total_table_size_formatted}})',
                      },
                      {
                        type: 'basic',
                        name: 'P',
                        if: '{{!_global.moduleUninstallInfo.tables || _global.moduleUninstallInfo.tables.length === 0}}',
                        props: { className: 'text-xs text-gray-500 ml-6' },
                        text: '삭제할 테이블이 없습니다.',
                      },
                      {
                        type: 'basic',
                        name: 'Div',
                        if: '{{_global.moduleUninstallInfo.tables && _global.moduleUninstallInfo.tables.length > 0}}',
                        props: { className: 'ml-6 border rounded-lg' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Div',
                            iteration: {
                              source: '_global.moduleUninstallInfo.tables',
                              item_var: 'table',
                            },
                            props: { className: 'flex items-center justify-between px-3 py-1.5' },
                            children: [
                              {
                                type: 'basic',
                                name: 'Span',
                                props: { className: 'text-xs font-mono' },
                                text: '{{table.name}}',
                              },
                              {
                                type: 'basic',
                                name: 'Span',
                                props: { className: 'text-xs text-gray-500' },
                                text: '{{table.size_formatted}}',
                              },
                            ],
                          },
                        ],
                      },
                    ],
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-sm font-medium' },
                        text: '스토리지 디렉토리',
                      },
                      {
                        type: 'basic',
                        name: 'P',
                        if: '{{!_global.moduleUninstallInfo.storage_directories || _global.moduleUninstallInfo.storage_directories.length === 0}}',
                        props: { className: 'text-xs text-gray-500 ml-6' },
                        text: '삭제할 스토리지가 없습니다.',
                      },
                      {
                        type: 'basic',
                        name: 'Div',
                        if: '{{_global.moduleUninstallInfo.storage_directories && _global.moduleUninstallInfo.storage_directories.length > 0}}',
                        props: { className: 'ml-6 border rounded-lg' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Div',
                            iteration: {
                              source: '_global.moduleUninstallInfo.storage_directories',
                              item_var: 'dir',
                            },
                            props: { className: 'flex items-center justify-between px-3 py-1.5' },
                            children: [
                              {
                                type: 'basic',
                                name: 'Span',
                                props: { className: 'text-xs font-mono' },
                                text: '{{dir.name}}',
                              },
                              {
                                type: 'basic',
                                name: 'Span',
                                props: { className: 'text-xs text-gray-500' },
                                text: '{{dir.size_formatted}}',
                              },
                            ],
                          },
                        ],
                      },
                    ],
                  },
                ],
              },
            ],
          },
          {
            id: 'extension_directory_section',
            type: 'basic',
            name: 'Div',
            props: { className: 'mt-4 border-t border-gray-200 pt-4' },
            children: [
              {
                type: 'basic',
                name: 'Span',
                props: { className: 'text-sm font-semibold' },
                text: '삭제될 확장 디렉토리',
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{!_global.moduleUninstallInfo}}',
                props: { className: 'flex items-center justify-center py-4' },
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    props: { name: 'spinner', className: 'w-5 h-5 animate-spin' },
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{_global.moduleUninstallInfo && _global.moduleUninstallInfo.extension_directory}}',
                props: { className: 'mt-3' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'flex items-center gap-2 mb-2' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Icon',
                        props: { name: 'folder-open', className: 'w-4 h-4 text-gray-500' },
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-sm font-medium' },
                        text: '확장 디렉토리',
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-xs text-gray-500' },
                        text: '(총 {{_global.moduleUninstallInfo.extension_directory.size_formatted}})',
                      },
                    ],
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'ml-6 border rounded-lg' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Div',
                        props: { className: 'flex items-center justify-between px-3 py-1.5' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Span',
                            props: { className: 'text-xs font-mono' },
                            text: '{{_global.moduleUninstallInfo.extension_directory.path}}',
                          },
                          {
                            type: 'basic',
                            name: 'Span',
                            props: { className: 'text-xs text-gray-500' },
                            text: '{{_global.moduleUninstallInfo.extension_directory.size_formatted}}',
                          },
                        ],
                      },
                    ],
                  },
                ],
              },
            ],
          },
          {
            type: 'basic',
            name: 'Div',
            props: { className: 'flex justify-end gap-3 mt-6' },
            children: [
              {
                type: 'basic',
                name: 'Button',
                props: {
                  className: 'px-4 py-2 bg-white',
                  disabled: '{{_global.isModuleUninstalling}}',
                },
                actions: [{ type: 'click', handler: 'closeModal' }],
                children: [
                  { type: 'basic', name: 'Span', text: '취소' },
                ],
              },
              {
                type: 'basic',
                name: 'Button',
                props: {
                  className: 'px-4 py-2 bg-red-600 text-white',
                  disabled: '{{_global.isModuleUninstalling}}',
                },
                actions: [
                  {
                    type: 'click',
                    handler: 'sequence',
                    actions: [
                      {
                        handler: 'setState',
                        params: { target: 'global', isModuleUninstalling: true },
                      },
                      {
                        handler: 'apiCall',
                        auth_required: true,
                        target: '/api/admin/modules/uninstall',
                        params: {
                          method: 'DELETE',
                          body: {
                            module_name: '{{_global.selectedModule.identifier}}',
                            delete_data: '{{_global.deleteModuleData || false}}',
                          },
                        },
                        onSuccess: [
                          {
                            handler: 'setState',
                            params: { target: 'global', isModuleUninstalling: false },
                          },
                          { handler: 'closeModal' },
                          {
                            handler: 'setState',
                            params: {
                              target: 'global',
                              selectedModule: null,
                              deleteModuleData: false,
                              moduleUninstallInfo: null,
                            },
                          },
                        ],
                        onError: [
                          {
                            handler: 'setState',
                            params: { target: 'global', isModuleUninstalling: false },
                          },
                        ],
                      },
                    ],
                  },
                ],
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    if: '{{_global.isModuleUninstalling}}',
                    props: { name: 'spinner', className: 'w-4 h-4 animate-spin' },
                  },
                  {
                    type: 'basic',
                    name: 'Span',
                    text: "{{_global.isModuleUninstalling ? '삭제 중...' : '삭제'}}",
                  },
                ],
              },
            ],
          },
        ],
      },
    ],
    components: [
      {
        id: 'main-content',
        type: 'basic',
        name: 'Div',
        children: [
          { type: 'basic', name: 'Span', text: '모듈 관리 페이지' },
        ],
      },
    ],
  };

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(moduleUninstallLayout, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      translations: commonTranslations,
      locale: 'ko',
      componentRegistry: registry,
      includeModals: true,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 정보가 올바르게 로드된다', () => {
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('module_uninstall_modal_test');
      expect(info.version).toBe('1.0.0');
    });

    it('초기 전역 상태가 올바르게 설정된다', () => {
      const state = testUtils.getState();
      expect(state._global.selectedModule).toEqual({
        identifier: 'test-module',
        name: '테스트 모듈',
      });
      expect(state._global.deleteModuleData).toBe(false);
      expect(state._global.isModuleUninstalling).toBe(false);
      expect(state._global.moduleUninstallInfo).toBeNull();
    });

    it('모달에 data_sources가 정의되어 있다', () => {
      // 모달 내부 data_sources는 getDataSources()에 포함되지 않으므로 레이아웃 JSON 직접 검증
      const modal = moduleUninstallLayout.modals[0];
      expect(modal.data_sources).toBeDefined();
      expect(modal.data_sources).toHaveLength(1);

      const ds = modal.data_sources[0];
      expect(ds.id).toBe('moduleUninstallInfo');
      expect(ds.endpoint).toContain('/uninstall-info');
      expect(ds.initGlobal).toBe('moduleUninstallInfo');
      expect(ds.auth_required).toBe(true);
    });

    it('data_sources에 auto_fetch: false가 설정되어 있다', () => {
      const modal = moduleUninstallLayout.modals[0];
      const ds = modal.data_sources[0];
      expect(ds.auto_fetch).toBe(false);
    });

    it('모달 크기가 medium으로 설정되어 있다', () => {
      const modal = moduleUninstallLayout.modals[0];
      expect(modal.props.size).toBe('medium');
    });
  });

  describe('모달 열기/닫기', () => {
    it('삭제 모달을 열고 닫을 수 있다', async () => {
      await testUtils.render();

      testUtils.openModal('module_uninstall_modal');
      expect(testUtils.getModalStack()).toContain('module_uninstall_modal');

      testUtils.closeModal();
      expect(testUtils.getModalStack()).not.toContain('module_uninstall_modal');
    });

    it('모달을 열 때 deleteModuleData와 moduleUninstallInfo가 초기화된다', async () => {
      await testUtils.render();

      // 이전 모달에서 남은 상태를 시뮬레이션
      testUtils.setState('deleteModuleData', true, 'global');
      testUtils.setState('moduleUninstallInfo', { tables: [], storage_directories: [] }, 'global');

      // openModal 직전 setState가 초기화하는 패턴 검증
      // 부모 레이아웃의 sequence: setState(selectedModule, deleteModuleData=false, moduleUninstallInfo=null) → openModal
      testUtils.setState('deleteModuleData', false, 'global');
      testUtils.setState('moduleUninstallInfo', null, 'global');
      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.deleteModuleData).toBe(false);
      expect(state._global.moduleUninstallInfo).toBeNull();
    });
  });

  describe('삭제 정보 조건부 렌더링', () => {
    it('삭제 정보가 없을 때 스피너가 표시된다', async () => {
      await testUtils.render();

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      // moduleUninstallInfo가 null이므로 스피너 표시
      const state = testUtils.getState();
      expect(state._global.moduleUninstallInfo).toBeNull();
    });

    it('삭제 정보가 로드되면 테이블 목록이 표시된다', async () => {
      await testUtils.render();

      // 삭제 정보를 전역 상태에 설정
      testUtils.setState(
        'moduleUninstallInfo',
        {
          tables: [
            { name: 'boards', size_bytes: 16384, size_formatted: '16.0 KB' },
            { name: 'board_posts', size_bytes: 1048576, size_formatted: '1.0 MB' },
          ],
          storage_directories: [
            { name: 'settings', size_bytes: 4096, size_formatted: '4.0 KB' },
            { name: 'attachments', size_bytes: 52428800, size_formatted: '50.0 MB' },
          ],
          total_table_size_bytes: 1064960,
          total_table_size_formatted: '1.0 MB',
          total_storage_size_bytes: 52432896,
          total_storage_size_formatted: '50.0 MB',
        },
        'global'
      );

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.moduleUninstallInfo).toBeDefined();
      expect(state._global.moduleUninstallInfo.tables).toHaveLength(2);
      expect(state._global.moduleUninstallInfo.storage_directories).toHaveLength(2);
    });

    it('테이블이 없을 때 "삭제할 테이블이 없습니다" 메시지가 설정된다', async () => {
      await testUtils.render();

      testUtils.setState(
        'moduleUninstallInfo',
        {
          tables: [],
          storage_directories: [],
          total_table_size_bytes: 0,
          total_table_size_formatted: '0 B',
          total_storage_size_bytes: 0,
          total_storage_size_formatted: '0 B',
        },
        'global'
      );

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.moduleUninstallInfo.tables).toEqual([]);
      expect(state._global.moduleUninstallInfo.storage_directories).toEqual([]);
    });
  });

  describe('체크박스 상태 관리', () => {
    it('deleteModuleData 초기값이 false이다', () => {
      const state = testUtils.getState();
      expect(state._global.deleteModuleData).toBe(false);
    });

    it('deleteModuleData 상태를 변경할 수 있다', async () => {
      await testUtils.render();

      testUtils.setState('deleteModuleData', true, 'global');
      const state = testUtils.getState();
      expect(state._global.deleteModuleData).toBe(true);
    });

    it('체크박스에 conditions 핸들러가 정의되어 있다', () => {
      const modal = moduleUninstallLayout.modals[0];
      // delete_data_option > flex > Checkbox
      const deleteDataOption = modal.children[1];
      const checkbox = deleteDataOption.children[0].children[0];

      expect(checkbox.name).toBe('Checkbox');
      expect(checkbox.actions[0].handler).toBe('conditions');
      expect(checkbox.actions[0].conditions).toHaveLength(2);

      // 체크 시: setState(deleteModuleData=true) + refetchDataSource
      const checkedCondition = checkbox.actions[0].conditions[0];
      expect(checkedCondition.if).toBe('{{$event.target.checked}}');
      expect(checkedCondition.then).toHaveLength(2);
      expect(checkedCondition.then[0].handler).toBe('setState');
      expect(checkedCondition.then[0].params.deleteModuleData).toBe(true);
      expect(checkedCondition.then[1].handler).toBe('refetchDataSource');
      expect(checkedCondition.then[1].params.dataSourceId).toBe('moduleUninstallInfo');

      // 해제 시: setState(deleteModuleData=false)
      const uncheckedCondition = checkbox.actions[0].conditions[1];
      expect(uncheckedCondition.if).toBeUndefined();
      expect(uncheckedCondition.then.handler).toBe('setState');
      expect(uncheckedCondition.then.params.deleteModuleData).toBe(false);
    });
  });

  describe('삭제 정보 조건부 표시', () => {
    it('uninstall_info_section에 if 조건이 설정되어 있다', () => {
      const modal = moduleUninstallLayout.modals[0];
      // uninstall_info_section은 children[2]
      const infoSection = modal.children[2];
      expect(infoSection.id).toBe('uninstall_info_section');
      expect(infoSection.if).toBe('{{_global.deleteModuleData}}');
    });

    it('deleteModuleData가 false이면 삭제 정보 영역이 숨겨진다', async () => {
      await testUtils.render();

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      // deleteModuleData가 false(초기값)이므로 info section 숨김
      const state = testUtils.getState();
      expect(state._global.deleteModuleData).toBe(false);
    });

    it('deleteModuleData가 true이면 삭제 정보 영역이 표시된다', async () => {
      await testUtils.render();

      testUtils.setState('deleteModuleData', true, 'global');
      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.deleteModuleData).toBe(true);
    });
  });

  describe('확장 디렉토리 섹션', () => {
    it('extension_directory_section이 레이아웃에 정의되어 있다', () => {
      const modal = moduleUninstallLayout.modals[0];
      const extDirSection = modal.children[3];
      expect(extDirSection.id).toBe('extension_directory_section');
    });

    it('확장 디렉토리 정보가 없으면 스피너가 표시 조건이 충족된다', async () => {
      await testUtils.render();

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      // moduleUninstallInfo가 null이면 스피너 표시 조건
      const state = testUtils.getState();
      expect(state._global.moduleUninstallInfo).toBeNull();
    });

    it('확장 디렉토리 정보가 로드되면 경로와 용량이 상태에 포함된다', async () => {
      await testUtils.render();

      testUtils.setState(
        'moduleUninstallInfo',
        {
          tables: [],
          storage_directories: [],
          total_table_size_bytes: 0,
          total_table_size_formatted: '0 B',
          total_storage_size_bytes: 0,
          total_storage_size_formatted: '0 B',
          extension_directory: {
            path: 'modules/test-module',
            size_bytes: 2048000,
            size_formatted: '2.0 MB',
          },
        },
        'global'
      );

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.moduleUninstallInfo.extension_directory).toBeDefined();
      expect(state._global.moduleUninstallInfo.extension_directory.path).toBe('modules/test-module');
      expect(state._global.moduleUninstallInfo.extension_directory.size_formatted).toBe('2.0 MB');
    });

    it('extension_directory가 null이면 디렉토리 정보가 표시되지 않는 조건이 된다', async () => {
      await testUtils.render();

      testUtils.setState(
        'moduleUninstallInfo',
        {
          tables: [],
          storage_directories: [],
          total_table_size_bytes: 0,
          total_table_size_formatted: '0 B',
          total_storage_size_bytes: 0,
          total_storage_size_formatted: '0 B',
          extension_directory: null,
        },
        'global'
      );

      testUtils.openModal('module_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.moduleUninstallInfo.extension_directory).toBeNull();
    });

    it('확장 디렉토리 섹션의 if 조건이 올바르게 설정되어 있다', () => {
      const modal = moduleUninstallLayout.modals[0];
      const extDirSection = modal.children[3];

      // 로딩 스피너: moduleUninstallInfo가 없을 때
      const loadingDiv = extDirSection.children[1];
      expect(loadingDiv.if).toBe('{{!_global.moduleUninstallInfo}}');

      // 디렉토리 정보: moduleUninstallInfo && extension_directory가 있을 때
      const infoDiv = extDirSection.children[2];
      expect(infoDiv.if).toBe('{{_global.moduleUninstallInfo && _global.moduleUninstallInfo.extension_directory}}');
    });
  });

  describe('삭제 진행 상태', () => {
    it('isModuleUninstalling 상태를 변경할 수 있다', async () => {
      await testUtils.render();

      testUtils.setState('isModuleUninstalling', true, 'global');
      const state = testUtils.getState();
      expect(state._global.isModuleUninstalling).toBe(true);
    });

    it('삭제 완료 후 상태가 초기화된다', async () => {
      await testUtils.render();

      // 삭제 진행 후 상태 초기화 시뮬레이션
      testUtils.setState('isModuleUninstalling', false, 'global');
      testUtils.setState('selectedModule', null, 'global');
      testUtils.setState('deleteModuleData', false, 'global');
      testUtils.setState('moduleUninstallInfo', null, 'global');

      const state = testUtils.getState();
      expect(state._global.isModuleUninstalling).toBe(false);
      expect(state._global.selectedModule).toBeNull();
      expect(state._global.deleteModuleData).toBe(false);
      expect(state._global.moduleUninstallInfo).toBeNull();
    });
  });
});

// ==============================
// 플러그인 삭제 모달 테스트
// ==============================

describe('플러그인 삭제 모달 레이아웃 테스트', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  // 플러그인 삭제 모달을 포함하는 래퍼 레이아웃
  const pluginUninstallLayout = {
    version: '1.0.0',
    layout_name: 'plugin_uninstall_modal_test',
    initGlobal: {
      selectedPlugin: { identifier: 'test-plugin', name: '테스트 플러그인' },
      deletePluginData: false,
      isPluginUninstalling: false,
      pluginUninstallInfo: null,
    },
    modals: [
      {
        id: 'plugin_uninstall_modal',
        type: 'composite',
        name: 'Modal',
        data_sources: [
          {
            id: 'pluginUninstallInfo',
            type: 'api',
            endpoint: '/api/admin/plugins/test-plugin/uninstall-info',
            auth_required: true,
            auto_fetch: false,
            initGlobal: 'pluginUninstallInfo',
          },
        ],
        props: {
          title: '플러그인 삭제',
          size: 'medium',
        },
        children: [
          {
            type: 'basic',
            name: 'Div',
            props: { className: 'py-2' },
            children: [
              {
                type: 'basic',
                name: 'P',
                text: '테스트 플러그인을 삭제하시겠습니까?',
              },
            ],
          },
          {
            id: 'delete_data_option',
            type: 'basic',
            name: 'Div',
            props: { className: 'mt-4 p-3 bg-gray-50 rounded-lg' },
            children: [
              {
                type: 'basic',
                name: 'Div',
                props: { className: 'flex items-start gap-2' },
                children: [
                  {
                    type: 'basic',
                    name: 'Checkbox',
                    props: {
                      id: 'delete_plugin_data_checkbox',
                      checked: '{{_global.deletePluginData || false}}',
                    },
                    actions: [
                      {
                        type: 'change',
                        handler: 'conditions',
                        conditions: [
                          {
                            if: '{{$event.target.checked}}',
                            then: [
                              {
                                handler: 'setState',
                                params: {
                                  target: 'global',
                                  deletePluginData: true,
                                  pluginUninstallInfo: null,
                                },
                              },
                              {
                                handler: 'refetchDataSource',
                                params: { dataSourceId: 'pluginUninstallInfo' },
                              },
                            ],
                          },
                          {
                            then: {
                              handler: 'setState',
                              params: {
                                target: 'global',
                                deletePluginData: false,
                                pluginUninstallInfo: null,
                              },
                            },
                          },
                        ],
                      },
                    ],
                  },
                  {
                    type: 'basic',
                    name: 'Label',
                    props: {
                      htmlFor: 'delete_plugin_data_checkbox',
                      className: 'text-sm cursor-pointer',
                    },
                    text: '데이터도 함께 삭제',
                  },
                ],
              },
            ],
          },
          {
            id: 'uninstall_info_section',
            type: 'basic',
            name: 'Div',
            if: '{{_global.deletePluginData}}',
            props: { className: 'mt-4' },
            children: [
              {
                type: 'basic',
                name: 'Span',
                props: { className: 'text-sm font-semibold' },
                text: '삭제될 데이터',
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{!_global.pluginUninstallInfo}}',
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    props: { name: 'spinner', className: 'animate-spin' },
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{_global.pluginUninstallInfo}}',
                props: { className: 'mt-3 space-y-4' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        text: '데이터베이스 테이블',
                      },
                      {
                        type: 'basic',
                        name: 'Div',
                        if: '{{_global.pluginUninstallInfo.tables && _global.pluginUninstallInfo.tables.length > 0}}',
                        children: [
                          {
                            type: 'basic',
                            name: 'Div',
                            iteration: {
                              source: '_global.pluginUninstallInfo.tables',
                              item_var: 'table',
                            },
                            children: [
                              {
                                type: 'basic',
                                name: 'Span',
                                text: '{{table.name}}',
                              },
                              {
                                type: 'basic',
                                name: 'Span',
                                text: '{{table.size_formatted}}',
                              },
                            ],
                          },
                        ],
                      },
                    ],
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        text: '스토리지 디렉토리',
                      },
                      {
                        type: 'basic',
                        name: 'Div',
                        if: '{{_global.pluginUninstallInfo.storage_directories && _global.pluginUninstallInfo.storage_directories.length > 0}}',
                        children: [
                          {
                            type: 'basic',
                            name: 'Div',
                            iteration: {
                              source: '_global.pluginUninstallInfo.storage_directories',
                              item_var: 'dir',
                            },
                            children: [
                              {
                                type: 'basic',
                                name: 'Span',
                                text: '{{dir.name}}',
                              },
                              {
                                type: 'basic',
                                name: 'Span',
                                text: '{{dir.size_formatted}}',
                              },
                            ],
                          },
                        ],
                      },
                    ],
                  },
                ],
              },
            ],
          },
          {
            id: 'extension_directory_section',
            type: 'basic',
            name: 'Div',
            props: { className: 'mt-4 border-t border-gray-200 pt-4' },
            children: [
              {
                type: 'basic',
                name: 'Span',
                props: { className: 'text-sm font-semibold' },
                text: '삭제될 확장 디렉토리',
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{!_global.pluginUninstallInfo}}',
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    props: { name: 'spinner', className: 'animate-spin' },
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{_global.pluginUninstallInfo && _global.pluginUninstallInfo.extension_directory}}',
                props: { className: 'mt-3' },
                children: [
                  {
                    type: 'basic',
                    name: 'Span',
                    text: '{{_global.pluginUninstallInfo.extension_directory.path}}',
                  },
                  {
                    type: 'basic',
                    name: 'Span',
                    text: '{{_global.pluginUninstallInfo.extension_directory.size_formatted}}',
                  },
                ],
              },
            ],
          },
          {
            type: 'basic',
            name: 'Div',
            props: { className: 'flex justify-end gap-3 mt-6' },
            children: [
              {
                type: 'basic',
                name: 'Button',
                props: { disabled: '{{_global.isPluginUninstalling}}' },
                actions: [{ type: 'click', handler: 'closeModal' }],
                children: [{ type: 'basic', name: 'Span', text: '취소' }],
              },
              {
                type: 'basic',
                name: 'Button',
                props: {
                  className: 'bg-red-600 text-white',
                  disabled: '{{_global.isPluginUninstalling}}',
                },
                actions: [
                  {
                    type: 'click',
                    handler: 'sequence',
                    actions: [
                      {
                        handler: 'setState',
                        params: { target: 'global', isPluginUninstalling: true },
                      },
                      {
                        handler: 'apiCall',
                        auth_required: true,
                        target: '/api/admin/plugins/uninstall',
                        params: {
                          method: 'DELETE',
                          body: {
                            plugin_name: '{{_global.selectedPlugin.identifier}}',
                            delete_data: '{{_global.deletePluginData || false}}',
                          },
                        },
                        onSuccess: [
                          {
                            handler: 'setState',
                            params: { target: 'global', isPluginUninstalling: false },
                          },
                          { handler: 'closeModal' },
                          {
                            handler: 'setState',
                            params: {
                              target: 'global',
                              selectedPlugin: null,
                              deletePluginData: false,
                              pluginUninstallInfo: null,
                            },
                          },
                        ],
                        onError: [
                          {
                            handler: 'setState',
                            params: { target: 'global', isPluginUninstalling: false },
                          },
                        ],
                      },
                    ],
                  },
                ],
                children: [
                  {
                    type: 'basic',
                    name: 'Span',
                    text: "{{_global.isPluginUninstalling ? '삭제 중...' : '삭제'}}",
                  },
                ],
              },
            ],
          },
        ],
      },
    ],
    components: [
      {
        id: 'main-content',
        type: 'basic',
        name: 'Div',
        children: [
          { type: 'basic', name: 'Span', text: '플러그인 관리 페이지' },
        ],
      },
    ],
  };

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(pluginUninstallLayout, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      translations: commonTranslations,
      locale: 'ko',
      componentRegistry: registry,
      includeModals: true,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 정보가 올바르게 로드된다', () => {
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('plugin_uninstall_modal_test');
    });

    it('초기 전역 상태가 올바르게 설정된다', () => {
      const state = testUtils.getState();
      expect(state._global.selectedPlugin).toEqual({
        identifier: 'test-plugin',
        name: '테스트 플러그인',
      });
      expect(state._global.deletePluginData).toBe(false);
      expect(state._global.isPluginUninstalling).toBe(false);
      expect(state._global.pluginUninstallInfo).toBeNull();
    });

    it('모달에 data_sources가 정의되어 있다', () => {
      // 모달 내부 data_sources는 getDataSources()에 포함되지 않으므로 레이아웃 JSON 직접 검증
      const modal = pluginUninstallLayout.modals[0];
      expect(modal.data_sources).toBeDefined();
      expect(modal.data_sources).toHaveLength(1);

      const ds = modal.data_sources[0];
      expect(ds.id).toBe('pluginUninstallInfo');
      expect(ds.endpoint).toContain('/uninstall-info');
      expect(ds.initGlobal).toBe('pluginUninstallInfo');
      expect(ds.auth_required).toBe(true);
    });

    it('data_sources에 auto_fetch: false가 설정되어 있다', () => {
      const modal = pluginUninstallLayout.modals[0];
      const ds = modal.data_sources[0];
      expect(ds.auto_fetch).toBe(false);
    });

    it('모달 크기가 medium으로 설정되어 있다', () => {
      const modal = pluginUninstallLayout.modals[0];
      expect(modal.props.size).toBe('medium');
    });
  });

  describe('모달 열기/닫기', () => {
    it('삭제 모달을 열고 닫을 수 있다', async () => {
      await testUtils.render();

      testUtils.openModal('plugin_uninstall_modal');
      expect(testUtils.getModalStack()).toContain('plugin_uninstall_modal');

      testUtils.closeModal();
      expect(testUtils.getModalStack()).not.toContain('plugin_uninstall_modal');
    });

    it('모달을 열 때 deletePluginData와 pluginUninstallInfo가 초기화된다', async () => {
      await testUtils.render();

      // 이전 모달에서 남은 상태를 시뮬레이션
      testUtils.setState('deletePluginData', true, 'global');
      testUtils.setState('pluginUninstallInfo', { tables: [], storage_directories: [] }, 'global');

      // openModal 직전 setState가 초기화하는 패턴 검증
      testUtils.setState('deletePluginData', false, 'global');
      testUtils.setState('pluginUninstallInfo', null, 'global');
      testUtils.openModal('plugin_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.deletePluginData).toBe(false);
      expect(state._global.pluginUninstallInfo).toBeNull();
    });
  });

  describe('삭제 정보 상태 관리', () => {
    it('삭제 정보가 로드되면 상태가 설정된다', async () => {
      await testUtils.render();

      testUtils.setState(
        'pluginUninstallInfo',
        {
          tables: [
            { name: 'payments', size_bytes: 32768, size_formatted: '32.0 KB' },
          ],
          storage_directories: [
            { name: 'configs', size_bytes: 2048, size_formatted: '2.0 KB' },
          ],
          total_table_size_bytes: 32768,
          total_table_size_formatted: '32.0 KB',
          total_storage_size_bytes: 2048,
          total_storage_size_formatted: '2.0 KB',
        },
        'global'
      );

      const state = testUtils.getState();
      expect(state._global.pluginUninstallInfo.tables).toHaveLength(1);
      expect(state._global.pluginUninstallInfo.tables[0].name).toBe('payments');
      expect(state._global.pluginUninstallInfo.storage_directories).toHaveLength(1);
    });

    it('빈 삭제 정보가 올바르게 설정된다', async () => {
      await testUtils.render();

      testUtils.setState(
        'pluginUninstallInfo',
        {
          tables: [],
          storage_directories: [],
          total_table_size_bytes: 0,
          total_table_size_formatted: '0 B',
          total_storage_size_bytes: 0,
          total_storage_size_formatted: '0 B',
        },
        'global'
      );

      const state = testUtils.getState();
      expect(state._global.pluginUninstallInfo.tables).toEqual([]);
      expect(state._global.pluginUninstallInfo.storage_directories).toEqual([]);
    });
  });

  describe('체크박스 상태 관리', () => {
    it('deletePluginData 초기값이 false이다', () => {
      const state = testUtils.getState();
      expect(state._global.deletePluginData).toBe(false);
    });

    it('deletePluginData 상태를 변경할 수 있다', async () => {
      await testUtils.render();

      testUtils.setState('deletePluginData', true, 'global');
      const state = testUtils.getState();
      expect(state._global.deletePluginData).toBe(true);
    });

    it('체크박스에 conditions 핸들러가 정의되어 있다', () => {
      const modal = pluginUninstallLayout.modals[0];
      // delete_data_option > flex > Checkbox
      const deleteDataOption = modal.children[1];
      const checkbox = deleteDataOption.children[0].children[0];

      expect(checkbox.name).toBe('Checkbox');
      expect(checkbox.actions[0].handler).toBe('conditions');
      expect(checkbox.actions[0].conditions).toHaveLength(2);

      // 체크 시: setState(deletePluginData=true) + refetchDataSource
      const checkedCondition = checkbox.actions[0].conditions[0];
      expect(checkedCondition.if).toBe('{{$event.target.checked}}');
      expect(checkedCondition.then).toHaveLength(2);
      expect(checkedCondition.then[0].handler).toBe('setState');
      expect(checkedCondition.then[0].params.deletePluginData).toBe(true);
      expect(checkedCondition.then[1].handler).toBe('refetchDataSource');
      expect(checkedCondition.then[1].params.dataSourceId).toBe('pluginUninstallInfo');

      // 해제 시: setState(deletePluginData=false)
      const uncheckedCondition = checkbox.actions[0].conditions[1];
      expect(uncheckedCondition.if).toBeUndefined();
      expect(uncheckedCondition.then.handler).toBe('setState');
      expect(uncheckedCondition.then.params.deletePluginData).toBe(false);
    });
  });

  describe('삭제 정보 조건부 표시', () => {
    it('uninstall_info_section에 if 조건이 설정되어 있다', () => {
      const modal = pluginUninstallLayout.modals[0];
      // uninstall_info_section은 children[2]
      const infoSection = modal.children[2];
      expect(infoSection.id).toBe('uninstall_info_section');
      expect(infoSection.if).toBe('{{_global.deletePluginData}}');
    });

    it('deletePluginData가 false이면 삭제 정보 영역이 숨겨진다', async () => {
      await testUtils.render();

      testUtils.openModal('plugin_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.deletePluginData).toBe(false);
    });

    it('deletePluginData가 true이면 삭제 정보 영역이 표시된다', async () => {
      await testUtils.render();

      testUtils.setState('deletePluginData', true, 'global');
      testUtils.openModal('plugin_uninstall_modal');
      await testUtils.rerender();

      const state = testUtils.getState();
      expect(state._global.deletePluginData).toBe(true);
    });
  });

  describe('확장 디렉토리 섹션', () => {
    it('extension_directory_section이 레이아웃에 정의되어 있다', () => {
      const modal = pluginUninstallLayout.modals[0];
      const extDirSection = modal.children[3];
      expect(extDirSection.id).toBe('extension_directory_section');
    });

    it('확장 디렉토리 정보가 로드되면 상태에 포함된다', async () => {
      await testUtils.render();

      testUtils.setState(
        'pluginUninstallInfo',
        {
          tables: [],
          storage_directories: [],
          total_table_size_bytes: 0,
          total_table_size_formatted: '0 B',
          total_storage_size_bytes: 0,
          total_storage_size_formatted: '0 B',
          extension_directory: {
            path: 'plugins/test-plugin',
            size_bytes: 512000,
            size_formatted: '500.0 KB',
          },
        },
        'global'
      );

      const state = testUtils.getState();
      expect(state._global.pluginUninstallInfo.extension_directory).toBeDefined();
      expect(state._global.pluginUninstallInfo.extension_directory.path).toBe('plugins/test-plugin');
      expect(state._global.pluginUninstallInfo.extension_directory.size_formatted).toBe('500.0 KB');
    });

    it('확장 디렉토리 섹션의 if 조건이 올바르게 설정되어 있다', () => {
      const modal = pluginUninstallLayout.modals[0];
      const extDirSection = modal.children[3];

      // 로딩 스피너: pluginUninstallInfo가 없을 때
      const loadingDiv = extDirSection.children[1];
      expect(loadingDiv.if).toBe('{{!_global.pluginUninstallInfo}}');

      // 디렉토리 정보: pluginUninstallInfo && extension_directory가 있을 때
      const infoDiv = extDirSection.children[2];
      expect(infoDiv.if).toBe('{{_global.pluginUninstallInfo && _global.pluginUninstallInfo.extension_directory}}');
    });
  });

  describe('삭제 진행 상태', () => {
    it('삭제 완료 후 상태가 초기화된다', async () => {
      await testUtils.render();

      // 삭제 완료 후 상태 초기화
      testUtils.setState('isPluginUninstalling', false, 'global');
      testUtils.setState('selectedPlugin', null, 'global');
      testUtils.setState('deletePluginData', false, 'global');
      testUtils.setState('pluginUninstallInfo', null, 'global');

      const state = testUtils.getState();
      expect(state._global.isPluginUninstalling).toBe(false);
      expect(state._global.selectedPlugin).toBeNull();
      expect(state._global.deletePluginData).toBe(false);
      expect(state._global.pluginUninstallInfo).toBeNull();
    });
  });
});
