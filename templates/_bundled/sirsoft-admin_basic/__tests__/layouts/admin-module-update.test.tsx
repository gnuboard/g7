/**
 * @file admin-module-update.test.tsx
 * @description 모듈 업데이트 UI 레이아웃 테스트
 *
 * 테스트 대상:
 * - admin_module_list.json: updating 배지, 업데이트 확인 버튼, 행별 업데이트 버튼
 * - partials/admin_module_list/_modal_update.json: 업데이트 확인 모달
 *
 * 검증 항목:
 * - updating 상태 배지 렌더링
 * - update_available 배지 렌더링
 * - "업데이트 확인" 상단 버튼 존재 + 아이콘 전환
 * - 행별 업데이트 버튼 조건부 렌더링
 * - 모달 구조: 버전 정보, update_source, changelog 링크, 확인/취소 버튼
 * - 모달 버튼 disabled 상태 (업데이트 진행 중)
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
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-icon={name} data-size={size} />
);

const TestA: React.FC<{
  href?: string;
  target?: string;
  className?: string;
  children?: React.ReactNode;
}> = ({ href, target, className, children }) => (
  <a href={href} target={target} className={className} data-testid="link">{children}</a>
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
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
  };

  return registry;
}

// ==============================
// 공통 번역 키
// ==============================

const translations = {
  admin: {
    modules: {
      update_available: '업데이트 필요',
      version_available: '(v{{version}} 사용 가능)',
      updating: '업데이트 중...',
      update_success: '모듈이 성공적으로 업데이트되었습니다.',
      check_updates_success: '업데이트 확인 완료 ({{count}}개 업데이트 가능)',
      check_updates_no_updates: '모든 모듈이 최신 상태입니다.',
      status: {
        active: '활성화',
        inactive: '비활성화',
        not_installed: '미설치',
        installing: '설치 중',
        uninstalling: '제거 중',
        updating: '업데이트 중',
      },
      actions: {
        update: '업데이트',
        check_updates: '업데이트 확인',
      },
      modals: {
        update_title: '모듈 업데이트 확인',
        update_confirm: '{{name}} 모듈을 업데이트하시겠습니까?',
        update_version_info: '{{from}} → {{to}}',
        update_source_github: 'GitHub에서 다운로드',
        update_source_bundled: '선탑재 패키지에서 설치',
        view_changelog: '변경사항 보기',
        field_version: '버전',
        field_vendor: '개발자',
      },
    },
  },
  common: {
    cancel: '취소',
  },
};

// ==============================
// 0. 레이아웃 JSON auth_required 검증
// ==============================

describe('모듈 업데이트 - check-updates auth_required 검증', () => {
  it('check-updates apiCall에 auth_required: true가 설정되어 있다', async () => {
    const fs = await import('fs');
    const path = await import('path');
    const layoutPath = path.resolve(__dirname, '../../layouts/admin_module_list.json');
    const layoutJson = JSON.parse(fs.readFileSync(layoutPath, 'utf-8'));

    // sequence 내부의 apiCall 중 check-updates 찾기
    function findCheckUpdatesApiCall(actions: any[]): any {
      for (const action of actions) {
        if (action.handler === 'apiCall' && action.target?.includes('check-updates')) {
          return action;
        }
        if (action.handler === 'sequence' && action.actions) {
          const found = findCheckUpdatesApiCall(action.actions);
          if (found) return found;
        }
      }
      return null;
    }

    // 컴포넌트 트리에서 actions를 가진 요소 탐색
    function findActionsInComponents(components: any[]): any[] {
      const allActions: any[] = [];
      for (const comp of components) {
        if (comp.actions) {
          for (const actionGroup of comp.actions) {
            if (actionGroup.handler === 'sequence' && actionGroup.actions) {
              allActions.push(...actionGroup.actions);
            } else {
              allActions.push(actionGroup);
            }
          }
        }
        if (comp.children) {
          allActions.push(...findActionsInComponents(comp.children));
        }
      }
      return allActions;
    }

    // slots.content 또는 components에서 탐색
    const components = layoutJson.slots?.content ?? layoutJson.components ?? [];
    const allActions = findActionsInComponents(components);
    const checkUpdatesAction = findCheckUpdatesApiCall(allActions);

    expect(checkUpdatesAction).not.toBeNull();
    expect(checkUpdatesAction.auth_required).toBe(true);
  });
});

// ==============================
// 1. 상태 배지 테스트
// ==============================

describe('모듈 업데이트 - 상태 배지', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  function createBadgeLayout(row: Record<string, any>) {
    return {
      version: '1.0.0',
      layout_name: 'module_update_badge_test',
      initGlobal: { row },
      components: [
        {
          id: 'main-content',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Span',
              if: "{{_global.row.status == 'updating'}}",
              props: {
                className: 'px-2 py-0.5 text-xs bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-400 rounded',
              },
              text: '$t:admin.modules.status.updating',
            },
            {
              type: 'basic',
              name: 'Span',
              if: "{{_global.row.update_available && _global.row.status != 'updating' && _global.row.status != 'uninstalled'}}",
              props: {
                className: 'px-2 py-0.5 text-xs bg-amber-100 dark:bg-amber-900 text-amber-600 dark:text-amber-400 rounded',
              },
              text: '$t:admin.modules.update_available',
            },
            {
              type: 'basic',
              name: 'Span',
              if: "{{_global.row.update_available && _global.row.latest_version && _global.row.status != 'updating' && _global.row.status != 'uninstalled'}}",
              props: {
                className: 'text-xs text-gray-500 dark:text-gray-400',
              },
              text: "$t:admin.modules.version_available|version={{_global.row.latest_version ?? ''}}",
            },
          ],
        },
      ],
    };
  }

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('updating 상태일 때 보라색 배지가 표시된다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'updating', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    const badges = screen.getAllByText('업데이트 중');
    expect(badges.length).toBeGreaterThanOrEqual(1);
    const badge = badges[0];
    expect(badge.className).toContain('bg-purple-100');
    expect(badge.className).toContain('dark:bg-purple-900');
  });

  it('update_available일 때 (updating 아닌) 황색 배지가 표시된다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    const badges = screen.getAllByText('업데이트 필요');
    expect(badges.length).toBeGreaterThanOrEqual(1);
    const badge = badges[0];
    expect(badge.className).toContain('bg-amber-100');
  });

  it('update_available=false일 때 업데이트 배지가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: false }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText('업데이트 필요')).toBeNull();
    expect(screen.queryByText('업데이트 중')).toBeNull();
  });

  it('uninstalled 상태에서는 update_available이어도 배지가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'uninstalled', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText('업데이트 필요')).toBeNull();
  });

  it('update_available이고 latest_version이 있으면 버전 정보가 표시된다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: true, latest_version: '2.0.0' }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('(v2.0.0 사용 가능)')).toBeTruthy();
  });

  it('update_available이지만 latest_version이 null이면 버전 정보가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: true, latest_version: null }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText(/사용 가능/)).toBeNull();
  });
});

// ==============================
// 2. 업데이트 확인 버튼 테스트
// ==============================

describe('모듈 업데이트 - 업데이트 확인 버튼', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  const checkUpdatesButtonLayout = {
    version: '1.0.0',
    layout_name: 'module_check_updates_button_test',
    initGlobal: {
      isCheckingUpdates: false,
    },
    components: [
      {
        id: 'main-content',
        type: 'basic',
        name: 'Div',
        children: [
          {
            id: 'check_updates_button',
            type: 'basic',
            name: 'Button',
            props: {
              className: 'flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed',
              disabled: '{{_global.isCheckingUpdates}}',
              type: 'button',
              'data-testid': 'check-updates-btn',
            },
            children: [
              {
                type: 'basic',
                name: 'Icon',
                if: '{{!_global.isCheckingUpdates}}',
                props: { name: 'refresh', size: 'sm' },
              },
              {
                type: 'basic',
                name: 'Icon',
                if: '{{_global.isCheckingUpdates}}',
                props: { name: 'spinner', size: 'sm', className: 'animate-spin' },
              },
              {
                type: 'basic',
                name: 'Span',
                text: '$t:admin.modules.actions.check_updates',
              },
            ],
          },
        ],
      },
    ],
  };

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('업데이트 확인 버튼이 렌더링된다', async () => {
    testUtils = createLayoutTest(checkUpdatesButtonLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByText('업데이트 확인')).toBeTruthy();
  });

  it('로딩 중이 아닐 때 refresh 아이콘이 표시된다', async () => {
    testUtils = createLayoutTest(checkUpdatesButtonLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByTestId('icon-refresh')).toBeTruthy();
    expect(screen.queryByTestId('icon-spinner')).toBeNull();
  });

  it('isCheckingUpdates=true일 때 spinner 아이콘이 표시되고 버튼이 disabled된다', async () => {
    const loadingLayout = {
      ...checkUpdatesButtonLayout,
      initGlobal: { isCheckingUpdates: true },
    };
    testUtils = createLayoutTest(loadingLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByTestId('icon-spinner')).toBeTruthy();
    expect(screen.queryByTestId('icon-refresh')).toBeNull();
    const btn = screen.getByTestId('check-updates-btn');
    expect(btn).toHaveProperty('disabled', true);
  });
});

// ==============================
// 3. 행별 업데이트 버튼 테스트
// ==============================

describe('모듈 업데이트 - 행별 업데이트 버튼', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  function createRowButtonLayout(row: Record<string, any>) {
    return {
      version: '1.0.0',
      layout_name: 'module_row_update_button_test',
      initGlobal: { row },
      components: [
        {
          id: 'main-content',
          type: 'basic',
          name: 'Div',
          children: [
            {
              id: 'update_button',
              type: 'basic',
              name: 'Button',
              if: "{{_global.row.update_available && _global.row.status != 'updating' && _global.row.status != 'uninstalled'}}",
              props: {
                className: 'flex items-center gap-1.5 px-3 py-1.5 text-sm bg-amber-500 text-white rounded-lg',
                type: 'button',
                'data-testid': 'row-update-btn',
              },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  props: { name: 'arrow-up-circle', size: 'sm' },
                },
                {
                  type: 'basic',
                  name: 'Span',
                  text: '$t:admin.modules.actions.update',
                },
              ],
            },
          ],
        },
      ],
    };
  }

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('update_available && active 상태에서 업데이트 버튼이 표시된다', async () => {
    testUtils = createLayoutTest(
      createRowButtonLayout({ status: 'active', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByTestId('row-update-btn')).toBeTruthy();
    expect(screen.getByText('업데이트')).toBeTruthy();
    expect(screen.getByTestId('icon-arrow-up-circle')).toBeTruthy();
  });

  it('update_available=false일 때 업데이트 버튼이 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createRowButtonLayout({ status: 'active', update_available: false }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByTestId('row-update-btn')).toBeNull();
  });

  it('updating 상태에서는 업데이트 버튼이 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createRowButtonLayout({ status: 'updating', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByTestId('row-update-btn')).toBeNull();
  });

  it('uninstalled 상태에서는 업데이트 버튼이 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createRowButtonLayout({ status: 'uninstalled', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByTestId('row-update-btn')).toBeNull();
  });
});

// ==============================
// 4. 업데이트 모달 테스트
// ==============================

describe('모듈 업데이트 - 업데이트 모달', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  const updateModalLayout = {
    version: '1.0.0',
    layout_name: 'module_update_modal_test',
    initGlobal: {
      selectedModule: {
        identifier: 'sirsoft-board',
        name: '게시판',
        version: '1.0.0',
        latest_version: '1.1.0',
        update_source: 'github',
        github_changelog_url: 'https://github.com/gnuboard/sirsoft-board/releases',
      },
      isModuleUpdating: false,
    },
    modals: [
      {
        id: 'module_update_modal',
        type: 'composite',
        name: 'Modal',
        props: {
          title: '$t:admin.modules.modals.update_title',
          size: 'small',
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
                text: "$t:admin.modules.modals.update_confirm|name={{_global.selectedModule.name ?? ''}}",
              },
              {
                type: 'basic',
                name: 'Div',
                props: { className: 'mt-3 p-3 bg-gray-50 dark:bg-gray-800 border rounded-lg space-y-2' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'flex items-center gap-2 text-sm' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-gray-500' },
                        text: '$t:admin.modules.modals.field_version',
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'font-medium text-gray-900 dark:text-white' },
                        text: "$t:admin.modules.modals.update_version_info|from={{_global.selectedModule.version ?? ''}}|to={{_global.selectedModule.latest_version ?? ''}}",
                      },
                    ],
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'flex items-center gap-2 text-sm' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-gray-500' },
                        text: '$t:admin.modules.modals.field_vendor',
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-gray-700' },
                        text: "{{_global.selectedModule.update_source === 'github' ? '$t:admin.modules.modals.update_source_github' : '$t:admin.modules.modals.update_source_bundled'}}",
                      },
                    ],
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{_global.selectedModule.github_changelog_url}}',
                props: { className: 'mt-3' },
                children: [
                  {
                    type: 'basic',
                    name: 'A',
                    props: {
                      href: '{{_global.selectedModule.github_changelog_url}}',
                      target: '_blank',
                      className: 'inline-flex items-center gap-1.5 text-sm text-blue-600',
                    },
                    children: [
                      {
                        type: 'basic',
                        name: 'Icon',
                        props: { name: 'external-link', size: 'sm' },
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        text: '$t:admin.modules.modals.view_changelog',
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
                  className: 'px-4 py-2 bg-white dark:bg-gray-800 border rounded-lg disabled:opacity-50',
                  disabled: '{{_global.isModuleUpdating}}',
                  'data-testid': 'cancel-btn',
                },
                children: [
                  { type: 'basic', name: 'Span', text: '$t:common.cancel' },
                ],
              },
              {
                type: 'basic',
                name: 'Button',
                props: {
                  className: 'flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg disabled:opacity-50',
                  disabled: '{{_global.isModuleUpdating}}',
                  'data-testid': 'confirm-update-btn',
                },
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    if: '{{_global.isModuleUpdating}}',
                    props: { name: 'spinner', className: 'w-4 h-4 animate-spin' },
                  },
                  {
                    type: 'basic',
                    name: 'Span',
                    text: "{{_global.isModuleUpdating ? '$t:admin.modules.updating' : '$t:admin.modules.actions.update'}}",
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
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 정보가 올바르게 로드된다', () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('module_update_modal_test');
      expect(info.version).toBe('1.0.0');
    });

    it('초기 전역 상태가 올바르게 설정된다', () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      const state = testUtils.getState();
      expect(state._global.selectedModule).toEqual({
        identifier: 'sirsoft-board',
        name: '게시판',
        version: '1.0.0',
        latest_version: '1.1.0',
        update_source: 'github',
        github_changelog_url: 'https://github.com/gnuboard/sirsoft-board/releases',
      });
      expect(state._global.isModuleUpdating).toBe(false);
    });

    it('모달 크기가 small로 설정되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      expect(modal.props.size).toBe('small');
    });
  });

  describe('모달 열기/닫기', () => {
    it('업데이트 모달을 열고 닫을 수 있다', async () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      await testUtils.render();

      testUtils.openModal('module_update_modal');
      expect(testUtils.getModalStack()).toContain('module_update_modal');

      testUtils.closeModal();
      expect(testUtils.getModalStack()).not.toContain('module_update_modal');
    });
  });

  describe('모달 콘텐츠 구조 검증', () => {
    it('모달 타이틀이 업데이트 확인으로 설정되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      expect(modal.props.title).toBe('$t:admin.modules.modals.update_title');
    });

    it('모달에 업데이트 확인 메시지가 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      // children[0] = py-2 Div > children[0] = P (confirm message)
      const confirmP = modal.children[0].children[0];
      expect(confirmP.text).toContain('$t:admin.modules.modals.update_confirm');
      expect(confirmP.text).toContain('_global.selectedModule.name');
    });

    it('모달에 버전 정보가 포함되어 있다 (from → to)', () => {
      const modal = updateModalLayout.modals[0];
      // children[0] > children[1] = version info Div > children[0] = version row Div > children[1] = Span
      const versionRow = modal.children[0].children[1].children[0];
      const versionLabel = versionRow.children[0];
      const versionValue = versionRow.children[1];
      expect(versionLabel.text).toBe('$t:admin.modules.modals.field_version');
      expect(versionValue.text).toContain('$t:admin.modules.modals.update_version_info');
      expect(versionValue.text).toContain('_global.selectedModule.version');
      expect(versionValue.text).toContain('_global.selectedModule.latest_version');
    });

    it('모달에 update_source 표시가 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      // children[0] > children[1] = info Div > children[1] = vendor row
      const vendorRow = modal.children[0].children[1].children[1];
      const vendorLabel = vendorRow.children[0];
      const vendorValue = vendorRow.children[1];
      expect(vendorLabel.text).toBe('$t:admin.modules.modals.field_vendor');
      expect(vendorValue.text).toContain('update_source_github');
    });

    it('모달에 changelog 링크가 조건부로 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      // children[0] > children[2] = changelog Div (if 조건)
      const changelogDiv = modal.children[0].children[2];
      expect(changelogDiv.if).toBe('{{_global.selectedModule.github_changelog_url}}');

      const linkA = changelogDiv.children[0];
      expect(linkA.name).toBe('A');
      expect(linkA.props.target).toBe('_blank');
      expect(linkA.props.href).toContain('_global.selectedModule.github_changelog_url');
    });

    it('changelog URL이 없으면 조건에 의해 렌더링되지 않는다', () => {
      // github_changelog_url이 null이면 if 조건이 falsy
      const noChangelogState = { ...updateModalLayout.initGlobal.selectedModule, github_changelog_url: null };
      expect(noChangelogState.github_changelog_url).toBeNull();

      // if="{{_global.selectedModule.github_changelog_url}}" → null은 falsy
      const modal = updateModalLayout.modals[0];
      const changelogDiv = modal.children[0].children[2];
      expect(changelogDiv.if).toBe('{{_global.selectedModule.github_changelog_url}}');
    });
  });

  describe('버튼 구조 및 상태 검증', () => {
    it('취소/확인 버튼이 모달에 정의되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      // children[1] = 버튼 영역 Div > children = [cancel, confirm]
      const buttonsDiv = modal.children[1];
      expect(buttonsDiv.children).toHaveLength(2);

      const cancelBtn = buttonsDiv.children[0];
      const confirmBtn = buttonsDiv.children[1];

      // 취소 버튼
      expect(cancelBtn.name).toBe('Button');
      expect(cancelBtn.props.disabled).toBe('{{_global.isModuleUpdating}}');
      expect(cancelBtn.children[0].text).toBe('$t:common.cancel');

      // 확인 버튼
      expect(confirmBtn.name).toBe('Button');
      expect(confirmBtn.props.disabled).toBe('{{_global.isModuleUpdating}}');
    });

    it('확인 버튼에 스피너 조건부 렌더링이 설정되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const confirmBtn = modal.children[1].children[1];

      // spinner Icon (if: isModuleUpdating)
      const spinnerIcon = confirmBtn.children[0];
      expect(spinnerIcon.name).toBe('Icon');
      expect(spinnerIcon.if).toBe('{{_global.isModuleUpdating}}');
      expect(spinnerIcon.props.name).toBe('spinner');

      // 텍스트 (ternary: updating → '업데이트 중...' / '업데이트')
      const textSpan = confirmBtn.children[1];
      expect(textSpan.text).toContain('_global.isModuleUpdating');
      expect(textSpan.text).toContain('$t:admin.modules.updating');
      expect(textSpan.text).toContain('$t:admin.modules.actions.update');
    });

    it('isModuleUpdating 상태가 올바르게 변경될 수 있다', async () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      await testUtils.render();

      // 초기값 false
      let state = testUtils.getState();
      expect(state._global.isModuleUpdating).toBe(false);

      // true로 변경
      testUtils.setState('isModuleUpdating', true, 'global');
      state = testUtils.getState();
      expect(state._global.isModuleUpdating).toBe(true);

      // 완료 후 false로 복원
      testUtils.setState('isModuleUpdating', false, 'global');
      testUtils.setState('selectedModule', null, 'global');
      state = testUtils.getState();
      expect(state._global.isModuleUpdating).toBe(false);
      expect(state._global.selectedModule).toBeNull();
    });
  });
});
