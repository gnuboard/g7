/**
 * @file admin-template-update.test.tsx
 * @description 템플릿 업데이트 UI 레이아웃 테스트
 *
 * 테스트 대상:
 * - admin_template_list.json: updating 배지, 업데이트 확인 버튼, 행별 업데이트 버튼
 * - partials/admin_template_list/_modal_update.json: 업데이트 확인 모달
 *
 * 검증 항목:
 * - updating 상태 배지 렌더링
 * - update_available 배지 렌더링
 * - "업데이트 확인" 상단 버튼 존재 + 아이콘 전환
 * - 행별 업데이트 버튼 조건부 렌더링
 * - 모달 구조: 버전 정보, update_source, changelog 링크, 확인/취소 버튼
 * - 모달 버튼 disabled 상태 (업데이트 진행 중)
 * - 레이아웃 전략 선택 UI (overwrite/keep) — 템플릿 고유 기능
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

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <label className={className}>{children}</label>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  value?: string;
  checked?: boolean;
  className?: string;
  'data-testid'?: string;
}> = ({ type, name, value, checked, className, 'data-testid': testId }) => (
  <input type={type} name={name} value={value} checked={checked} className={className} data-testid={testId} readOnly />
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
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
  };

  return registry;
}

// ==============================
// 공통 번역 키
// ==============================

const translations = {
  admin: {
    templates: {
      update_available: '업데이트 필요',
      version_available: '(v{{version}} 사용 가능)',
      updating: '업데이트 중...',
      update_success: '템플릿이 성공적으로 업데이트되었습니다.',
      check_updates_success: '업데이트 확인 완료 ({{count}}개 업데이트 가능)',
      check_updates_no_updates: '모든 템플릿이 최신 상태입니다.',
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
        update_title: '템플릿 업데이트 확인',
        update_confirm: '{{name}} 템플릿을 업데이트하시겠습니까?',
        update_version_info: '{{from}} → {{to}}',
        update_source_github: 'GitHub에서 다운로드',
        update_source_bundled: '선탑재 패키지에서 설치',
        view_changelog: '변경사항 보기',
        field_version: '버전',
        field_vendor: '소스',
        layout_strategy_title: '레이아웃 업데이트 전략',
        modified_layouts_warning: '수정된 레이아웃 {{count}}개가 감지되었습니다.',
        no_modified_layouts: '수정된 레이아웃이 없습니다.',
        layout_strategy_overwrite: '모든 레이아웃 덮어쓰기',
        layout_strategy_overwrite_desc: '새 버전의 레이아웃으로 모두 교체합니다.',
        layout_strategy_keep: '수정된 레이아웃 유지',
        layout_strategy_keep_desc: '수정하지 않은 레이아웃만 업데이트합니다.',
      },
    },
  },
  common: {
    cancel: '취소',
  },
};

// ==============================
// 1. 상태 배지 테스트
// ==============================

describe('템플릿 업데이트 - 상태 배지', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  function createBadgeLayout(row: Record<string, any>) {
    return {
      version: '1.0.0',
      layout_name: 'template_update_badge_test',
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
              text: '$t:admin.templates.status.updating',
            },
            {
              type: 'basic',
              name: 'Span',
              if: "{{_global.row.update_available && _global.row.status != 'updating' && _global.row.status != 'uninstalled'}}",
              props: {
                className: 'px-2 py-0.5 text-xs bg-amber-100 dark:bg-amber-900 text-amber-600 dark:text-amber-400 rounded',
              },
              text: '$t:admin.templates.update_available',
            },
            {
              type: 'basic',
              name: 'Span',
              if: "{{_global.row.update_available && _global.row.latest_version && _global.row.status != 'updating' && _global.row.status != 'uninstalled'}}",
              props: {
                className: 'text-xs text-gray-500 dark:text-gray-400',
              },
              text: "$t:admin.templates.version_available|version={{_global.row.latest_version ?? ''}}",
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

  it('update_available이 false이면 버전 정보가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: false, latest_version: '2.0.0' }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText(/사용 가능/)).toBeNull();
  });
});

// ==============================
// 2. 업데이트 확인 버튼 테스트
// ==============================

describe('템플릿 업데이트 - 업데이트 확인 버튼', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  const checkUpdatesButtonLayout = {
    version: '1.0.0',
    layout_name: 'template_check_updates_button_test',
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
                text: '$t:admin.templates.actions.check_updates',
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

describe('템플릿 업데이트 - 행별 업데이트 버튼', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  function createRowButtonLayout(row: Record<string, any>) {
    return {
      version: '1.0.0',
      layout_name: 'template_row_update_button_test',
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
                  text: '$t:admin.templates.actions.update',
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

describe('템플릿 업데이트 - 업데이트 모달', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  const updateModalLayout = {
    version: '1.0.0',
    layout_name: 'template_update_modal_test',
    initGlobal: {
      selectedTemplate: {
        identifier: 'sirsoft-admin_basic',
        name: '관리자 기본 템플릿',
        version: '1.0.0',
        latest_version: '1.1.0',
        update_source: 'github',
        github_changelog_url: 'https://github.com/gnuboard/sirsoft-admin_basic/releases',
      },
      isTemplateUpdating: false,
      templateLayoutStrategy: 'overwrite',
      hasModifiedLayouts: false,
      modifiedLayoutsCount: 0,
    },
    modals: [
      {
        id: 'template_update_modal',
        type: 'composite',
        name: 'Modal',
        props: {
          title: '$t:admin.templates.modals.update_title',
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
                text: "$t:admin.templates.modals.update_confirm|name={{_global.selectedTemplate.name ?? ''}}",
              },
              {
                type: 'basic',
                name: 'Div',
                props: { className: 'mt-3 p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg space-y-2' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'flex items-center gap-2 text-sm' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-gray-500 dark:text-gray-400' },
                        text: '$t:admin.templates.modals.field_version',
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'font-medium text-gray-900 dark:text-white' },
                        text: "$t:admin.templates.modals.update_version_info|from={{_global.selectedTemplate.version ?? ''}}|to={{_global.selectedTemplate.latest_version ?? ''}}",
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
                        props: { className: 'text-gray-500 dark:text-gray-400' },
                        text: '$t:admin.templates.modals.field_vendor',
                      },
                      {
                        type: 'basic',
                        name: 'Span',
                        props: { className: 'text-gray-700 dark:text-gray-300' },
                        text: "{{_global.selectedTemplate.update_source === 'github' ? '$t:admin.templates.modals.update_source_github' : '$t:admin.templates.modals.update_source_bundled'}}",
                      },
                    ],
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                if: '{{_global.selectedTemplate.github_changelog_url}}',
                props: { className: 'mt-3' },
                children: [
                  {
                    type: 'basic',
                    name: 'A',
                    props: {
                      href: '{{_global.selectedTemplate.github_changelog_url}}',
                      target: '_blank',
                      className: 'inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:underline',
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
                        text: '$t:admin.templates.modals.view_changelog',
                      },
                    ],
                  },
                ],
              },
              {
                type: 'basic',
                name: 'Div',
                props: { className: 'mt-4 p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg' },
                children: [
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'text-sm font-medium text-gray-700 dark:text-gray-300 mb-2' },
                    text: '$t:admin.templates.modals.layout_strategy_title',
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    if: '{{_global.hasModifiedLayouts}}',
                    props: { className: 'mb-2 p-2 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 rounded text-xs text-amber-700 dark:text-amber-400' },
                    text: '$t:admin.templates.modals.modified_layouts_warning|count={{_global.modifiedLayoutsCount ?? 0}}',
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    if: '{{!_global.hasModifiedLayouts}}',
                    props: { className: 'mb-2 p-2 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 rounded text-xs text-green-700 dark:text-green-400' },
                    text: '$t:admin.templates.modals.no_modified_layouts',
                  },
                  {
                    type: 'basic',
                    name: 'Div',
                    props: { className: 'space-y-2' },
                    children: [
                      {
                        type: 'basic',
                        name: 'Label',
                        props: { className: 'flex items-start gap-2 p-2 rounded cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Input',
                            props: {
                              type: 'radio',
                              name: 'layout_strategy',
                              value: 'overwrite',
                              checked: "{{(_global.templateLayoutStrategy ?? 'overwrite') === 'overwrite'}}",
                              className: 'mt-0.5',
                            },
                          },
                          {
                            type: 'basic',
                            name: 'Div',
                            children: [
                              {
                                type: 'basic',
                                name: 'Span',
                                props: { className: 'text-sm font-medium text-gray-700 dark:text-gray-300' },
                                text: '$t:admin.templates.modals.layout_strategy_overwrite',
                              },
                              {
                                type: 'basic',
                                name: 'P',
                                props: { className: 'text-xs text-gray-500 dark:text-gray-400' },
                                text: '$t:admin.templates.modals.layout_strategy_overwrite_desc',
                              },
                            ],
                          },
                        ],
                      },
                      {
                        type: 'basic',
                        name: 'Label',
                        props: { className: 'flex items-start gap-2 p-2 rounded cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700' },
                        children: [
                          {
                            type: 'basic',
                            name: 'Input',
                            props: {
                              type: 'radio',
                              name: 'layout_strategy',
                              value: 'keep',
                              checked: "{{(_global.templateLayoutStrategy ?? 'overwrite') === 'keep'}}",
                              className: 'mt-0.5',
                            },
                          },
                          {
                            type: 'basic',
                            name: 'Div',
                            children: [
                              {
                                type: 'basic',
                                name: 'Span',
                                props: { className: 'text-sm font-medium text-gray-700 dark:text-gray-300' },
                                text: '$t:admin.templates.modals.layout_strategy_keep',
                              },
                              {
                                type: 'basic',
                                name: 'P',
                                props: { className: 'text-xs text-gray-500 dark:text-gray-400' },
                                text: '$t:admin.templates.modals.layout_strategy_keep_desc',
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
            type: 'basic',
            name: 'Div',
            props: { className: 'flex justify-end gap-3 mt-6' },
            children: [
              {
                type: 'basic',
                name: 'Button',
                props: {
                  className: 'px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg disabled:opacity-50',
                  disabled: '{{_global.isTemplateUpdating}}',
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
                  disabled: '{{_global.isTemplateUpdating}}',
                  'data-testid': 'confirm-update-btn',
                },
                children: [
                  {
                    type: 'basic',
                    name: 'Icon',
                    if: '{{_global.isTemplateUpdating}}',
                    props: { name: 'spinner', className: 'w-4 h-4 animate-spin' },
                  },
                  {
                    type: 'basic',
                    name: 'Span',
                    text: "{{_global.isTemplateUpdating ? '$t:admin.templates.updating' : '$t:admin.templates.actions.update'}}",
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
          { type: 'basic', name: 'Span', text: '템플릿 관리 페이지' },
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
      expect(info.name).toBe('template_update_modal_test');
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
      expect(state._global.selectedTemplate).toEqual({
        identifier: 'sirsoft-admin_basic',
        name: '관리자 기본 템플릿',
        version: '1.0.0',
        latest_version: '1.1.0',
        update_source: 'github',
        github_changelog_url: 'https://github.com/gnuboard/sirsoft-admin_basic/releases',
      });
      expect(state._global.isTemplateUpdating).toBe(false);
      expect(state._global.templateLayoutStrategy).toBe('overwrite');
      expect(state._global.hasModifiedLayouts).toBe(false);
      expect(state._global.modifiedLayoutsCount).toBe(0);
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

      testUtils.openModal('template_update_modal');
      expect(testUtils.getModalStack()).toContain('template_update_modal');

      testUtils.closeModal();
      expect(testUtils.getModalStack()).not.toContain('template_update_modal');
    });
  });

  describe('모달 콘텐츠 구조 검증', () => {
    it('모달 타이틀이 업데이트 확인으로 설정되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      expect(modal.props.title).toBe('$t:admin.templates.modals.update_title');
    });

    it('모달에 업데이트 확인 메시지가 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const confirmP = modal.children[0].children[0];
      expect(confirmP.text).toContain('$t:admin.templates.modals.update_confirm');
      expect(confirmP.text).toContain('_global.selectedTemplate.name');
    });

    it('모달에 버전 정보가 포함되어 있다 (from → to)', () => {
      const modal = updateModalLayout.modals[0];
      const versionRow = modal.children[0].children[1].children[0];
      const versionLabel = versionRow.children[0];
      const versionValue = versionRow.children[1];
      expect(versionLabel.text).toBe('$t:admin.templates.modals.field_version');
      expect(versionValue.text).toContain('$t:admin.templates.modals.update_version_info');
      expect(versionValue.text).toContain('_global.selectedTemplate.version');
      expect(versionValue.text).toContain('_global.selectedTemplate.latest_version');
    });

    it('모달에 update_source 표시가 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const vendorRow = modal.children[0].children[1].children[1];
      const vendorLabel = vendorRow.children[0];
      const vendorValue = vendorRow.children[1];
      expect(vendorLabel.text).toBe('$t:admin.templates.modals.field_vendor');
      expect(vendorValue.text).toContain('update_source_github');
    });

    it('모달에 changelog 링크가 조건부로 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const changelogDiv = modal.children[0].children[2];
      expect(changelogDiv.if).toBe('{{_global.selectedTemplate.github_changelog_url}}');

      const linkA = changelogDiv.children[0];
      expect(linkA.name).toBe('A');
      expect(linkA.props.target).toBe('_blank');
      expect(linkA.props.href).toContain('_global.selectedTemplate.github_changelog_url');
    });

    it('changelog URL이 없으면 조건에 의해 렌더링되지 않는다', () => {
      const noChangelogState = { ...updateModalLayout.initGlobal.selectedTemplate, github_changelog_url: null };
      expect(noChangelogState.github_changelog_url).toBeNull();

      const modal = updateModalLayout.modals[0];
      const changelogDiv = modal.children[0].children[2];
      expect(changelogDiv.if).toBe('{{_global.selectedTemplate.github_changelog_url}}');
    });
  });

  describe('레이아웃 전략 선택 UI', () => {
    it('레이아웃 전략 섹션이 모달에 포함되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const strategySection = modal.children[0].children[3];
      expect(strategySection.children[0].text).toBe('$t:admin.templates.modals.layout_strategy_title');
    });

    it('수정된 레이아웃이 없을 때 녹색 메시지가 표시된다 (조건: !hasModifiedLayouts)', () => {
      const modal = updateModalLayout.modals[0];
      const strategySection = modal.children[0].children[3];

      const warningDiv = strategySection.children[1];
      expect(warningDiv.if).toBe('{{_global.hasModifiedLayouts}}');
      expect(warningDiv.text).toContain('$t:admin.templates.modals.modified_layouts_warning');

      const noModifiedDiv = strategySection.children[2];
      expect(noModifiedDiv.if).toBe('{{!_global.hasModifiedLayouts}}');
      expect(noModifiedDiv.text).toBe('$t:admin.templates.modals.no_modified_layouts');
      expect(noModifiedDiv.props.className).toContain('bg-green-50');
    });

    it('수정된 레이아웃이 있을 때 경고 메시지가 표시된다 (조건: hasModifiedLayouts)', () => {
      const modal = updateModalLayout.modals[0];
      const strategySection = modal.children[0].children[3];

      const warningDiv = strategySection.children[1];
      expect(warningDiv.if).toBe('{{_global.hasModifiedLayouts}}');
      expect(warningDiv.text).toContain('$t:admin.templates.modals.modified_layouts_warning');
      expect(warningDiv.props.className).toContain('bg-amber-50');
    });

    it('overwrite 라디오 옵션이 올바르게 구성되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const strategySection = modal.children[0].children[3];
      const radioGroup = strategySection.children[3];
      const overwriteLabel = radioGroup.children[0];
      const overwriteInput = overwriteLabel.children[0];
      const overwriteText = overwriteLabel.children[1];

      expect(overwriteInput.props.type).toBe('radio');
      expect(overwriteInput.props.name).toBe('layout_strategy');
      expect(overwriteInput.props.value).toBe('overwrite');
      expect(overwriteInput.props.checked).toContain('overwrite');
      expect(overwriteText.children[0].text).toBe('$t:admin.templates.modals.layout_strategy_overwrite');
      expect(overwriteText.children[1].text).toBe('$t:admin.templates.modals.layout_strategy_overwrite_desc');
    });

    it('keep 라디오 옵션이 올바르게 구성되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const strategySection = modal.children[0].children[3];
      const radioGroup = strategySection.children[3];
      const keepLabel = radioGroup.children[1];
      const keepInput = keepLabel.children[0];
      const keepText = keepLabel.children[1];

      expect(keepInput.props.type).toBe('radio');
      expect(keepInput.props.name).toBe('layout_strategy');
      expect(keepInput.props.value).toBe('keep');
      expect(keepInput.props.checked).toContain('keep');
      expect(keepText.children[0].text).toBe('$t:admin.templates.modals.layout_strategy_keep');
      expect(keepText.children[1].text).toBe('$t:admin.templates.modals.layout_strategy_keep_desc');
    });

    it('templateLayoutStrategy 상태를 변경할 수 있다', async () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      await testUtils.render();

      let state = testUtils.getState();
      expect(state._global.templateLayoutStrategy).toBe('overwrite');

      testUtils.setState('templateLayoutStrategy', 'keep', 'global');
      state = testUtils.getState();
      expect(state._global.templateLayoutStrategy).toBe('keep');

      testUtils.setState('templateLayoutStrategy', 'overwrite', 'global');
      state = testUtils.getState();
      expect(state._global.templateLayoutStrategy).toBe('overwrite');
    });

    it('hasModifiedLayouts 상태에 따라 경고/정상 메시지가 전환된다', async () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      await testUtils.render();

      let state = testUtils.getState();
      expect(state._global.hasModifiedLayouts).toBe(false);

      testUtils.setState('hasModifiedLayouts', true, 'global');
      testUtils.setState('modifiedLayoutsCount', 3, 'global');
      state = testUtils.getState();
      expect(state._global.hasModifiedLayouts).toBe(true);
      expect(state._global.modifiedLayoutsCount).toBe(3);
    });
  });

  describe('버튼 구조 및 상태 검증', () => {
    it('취소/확인 버튼이 모달에 정의되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const buttonsDiv = modal.children[1];
      expect(buttonsDiv.children).toHaveLength(2);

      const cancelBtn = buttonsDiv.children[0];
      const confirmBtn = buttonsDiv.children[1];

      expect(cancelBtn.name).toBe('Button');
      expect(cancelBtn.props.disabled).toBe('{{_global.isTemplateUpdating}}');
      expect(cancelBtn.children[0].text).toBe('$t:common.cancel');

      expect(confirmBtn.name).toBe('Button');
      expect(confirmBtn.props.disabled).toBe('{{_global.isTemplateUpdating}}');
    });

    it('확인 버튼에 스피너 조건부 렌더링이 설정되어 있다', () => {
      const modal = updateModalLayout.modals[0];
      const confirmBtn = modal.children[1].children[1];

      const spinnerIcon = confirmBtn.children[0];
      expect(spinnerIcon.name).toBe('Icon');
      expect(spinnerIcon.if).toBe('{{_global.isTemplateUpdating}}');
      expect(spinnerIcon.props.name).toBe('spinner');

      const textSpan = confirmBtn.children[1];
      expect(textSpan.text).toContain('_global.isTemplateUpdating');
      expect(textSpan.text).toContain('$t:admin.templates.updating');
      expect(textSpan.text).toContain('$t:admin.templates.actions.update');
    });

    it('isTemplateUpdating 상태가 올바르게 변경될 수 있다', async () => {
      testUtils = createLayoutTest(updateModalLayout, {
        translations,
        locale: 'ko',
        componentRegistry: registry,
        includeModals: true,
      });
      await testUtils.render();

      let state = testUtils.getState();
      expect(state._global.isTemplateUpdating).toBe(false);

      testUtils.setState('isTemplateUpdating', true, 'global');
      state = testUtils.getState();
      expect(state._global.isTemplateUpdating).toBe(true);

      testUtils.setState('isTemplateUpdating', false, 'global');
      testUtils.setState('selectedTemplate', null, 'global');
      testUtils.setState('templateLayoutStrategy', 'overwrite', 'global');
      testUtils.setState('hasModifiedLayouts', false, 'global');
      testUtils.setState('modifiedLayoutsCount', 0, 'global');
      state = testUtils.getState();
      expect(state._global.isTemplateUpdating).toBe(false);
      expect(state._global.selectedTemplate).toBeNull();
      expect(state._global.templateLayoutStrategy).toBe('overwrite');
      expect(state._global.hasModifiedLayouts).toBe(false);
      expect(state._global.modifiedLayoutsCount).toBe(0);
    });
  });
});
