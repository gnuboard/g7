/**
 * @file admin-template-update.test.tsx
 * @description 템플릿 업데이트 UI 레이아웃 테스트
 *
 * 테스트 대상:
 * - admin_template_list.g7.html: updating 배지, 업데이트 확인 버튼, 행별 업데이트 버튼
 * - partials/admin_template_list/_modal_update.g7.html: 업데이트 확인 모달
 *
 * 검증 항목:
 * - updating 상태 배지 렌더링
 * - update_available 배지 렌더링
 * - "업데이트 확인" 상단 버튼 존재 + 아이콘 전환
 * - 행별 업데이트 버튼 조건부 렌더링
 * - 모달 구조: 버전 정보, update_source, changelog 링크, 확인/취소 버튼
 * - 모달 버튼 disabled 상태 (업데이트 진행 중)
 * - 화면별 처리 미리보기(공통 조각)의 처리 방식 — 기본 수정 유지(#888)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

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
        layout_strategy_keep_desc: '고친 부분은 유지하고, 손대지 않은 부분은 새 버전으로 바꿉니다. 같은 곳을 양쪽이 고쳤으면 고친 내용을 남깁니다.',
        modified_layouts_list_title: '수정한 레이아웃 ({{count}}개)',
        modified_layouts_keep_notice: '위 레이아웃에서 고친 부분은 유지되고, 고치지 않은 부분에는 새 버전 변경이 들어갑니다. 결과는 업데이트 완료 알림과 활동 로그에서 확인할 수 있습니다.',
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

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
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

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
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

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
  it('update_available=false일 때 업데이트 배지가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: false }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText('업데이트 필요')).toBeNull();
    expect(screen.queryByText('업데이트 중')).toBeNull();
  });

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
  it('uninstalled 상태에서는 update_available이어도 배지가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'uninstalled', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText('업데이트 필요')).toBeNull();
  });

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
  it('update_available이고 latest_version이 있으면 버전 정보가 표시된다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: true, latest_version: '2.0.0' }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('(v2.0.0 사용 가능)')).toBeTruthy();
  });

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
  it('update_available이지만 latest_version이 null이면 버전 정보가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createBadgeLayout({ status: 'active', update_available: true, latest_version: null }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText(/사용 가능/)).toBeNull();
  });

  // @scenario kind=template, area=status_badge
  // @effects update_status_badge_matches_row_state
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

  // @scenario kind=template, area=check_button
  // @effects check_updates_button_calls_and_reloads
  it('업데이트 확인 버튼이 렌더링된다', async () => {
    testUtils = createLayoutTest(checkUpdatesButtonLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByText('업데이트 확인')).toBeTruthy();
  });

  // @scenario kind=template, area=check_button
  // @effects check_updates_button_calls_and_reloads
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

  // @scenario kind=template, area=check_button
  // @effects check_updates_button_calls_and_reloads
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

  // @scenario kind=template, area=row_button
  // @effects row_update_button_shows_only_when_available
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

  // @scenario kind=template, area=row_button
  // @effects row_update_button_shows_only_when_available
  it('update_available=false일 때 업데이트 버튼이 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createRowButtonLayout({ status: 'active', update_available: false }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByTestId('row-update-btn')).toBeNull();
  });

  // @scenario kind=template, area=row_button
  // @effects row_update_button_shows_only_when_available
  it('updating 상태에서는 업데이트 버튼이 표시되지 않는다', async () => {
    testUtils = createLayoutTest(
      createRowButtonLayout({ status: 'updating', update_available: true }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByTestId('row-update-btn')).toBeNull();
  });

  // @scenario kind=template, area=row_button
  // @effects row_update_button_shows_only_when_available
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

  // 실제 굽기 산출물을 읽는다 — 손으로 쓴 모달 사본은 실제 창과 어긋난 옛 구조를 단언하고 있었다(#888).
  const modal: any = JSON.parse(readFileSync(resolve(__dirname, '../../layouts/partials/admin_template_list/_modal_update.json'), 'utf-8'));
  const { meta: sharedMeta, ...sharedPreview } = JSON.parse(readFileSync(resolve(__dirname, '../../layouts/partials/_shared/_layout_sync_preview.json'), 'utf-8'));
  void sharedMeta; // 조각 표식(meta.is_partial)은 페이지에 들어가면 사라진다 — 본문만 쓴다.

  const selected = {
    identifier: 'sirsoft-admin_basic',
    name: '관리자 기본',
    version: '1.0.0',
    latest_version: '1.1.0',
    update_source: 'github',
    github_changelog_url: 'https://github.com/gnuboard/sirsoft-admin_basic/releases',
  };

  /** 모달 트리에서 조건에 맞는 노드를 모두 모은다. */
  function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
    if (Array.isArray(node)) {
      node.forEach((child) => collect(child, match, found));
      return found;
    }
    if (node && typeof node === 'object') {
      if (match(node)) found.push(node);
      Object.values(node).forEach((value) => {
        if (value && typeof value === 'object') collect(value, match, found);
      });
    }
    return found;
  }

  /** 공통 미리보기 조각을 펼쳐 넣는다 — 렌더 시험은 서버가 하는 조각 풀이를 대신한다. */
  function inlineSharedPreview(node: any): any {
    if (Array.isArray(node)) return node.map((child) => inlineSharedPreview(child));
    if (node && typeof node === 'object') {
      if (node.partial === 'partials/_shared/_layout_sync_preview.json') return sharedPreview;
      return Object.fromEntries(Object.entries(node).map(([key, v]) => [key, inlineSharedPreview(v)]));
    }
    return node;
  }

  /** 실제 업데이트 창을 띄운 레이아웃 — 창을 여는 쪽이 담는 값(대상·진행 여부·미리보기 로딩)을 시드한다. */
  function buildLayout() {
    return {
      version: '1.0.0',
      layout_name: 'template_update_modal_test',
      initGlobal: { selectedTemplate: selected, isTemplateUpdating: false, updateChangelog: [], layoutPreviewState: 'loading' },
      modals: [inlineSharedPreview(modal)],
      components: [{ id: 'main-content', type: 'basic', name: 'Div', children: [{ type: 'basic', name: 'Span', text: '템플릿 관리 페이지' }] }],
    };
  }

  /** 글자(text)에 needle 이 든 노드 */
  const textNodes = (needle: string) => collect(modal, (n) => typeof n.text === 'string' && n.text.includes(needle));
  const footer = (): any[] => modal.props.footer;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    // @scenario kind=template, area=layout_structure
    // @effects update_modal_layout_structure_is_declared
    it('레이아웃 정보가 올바르게 로드된다', () => {
      testUtils = createLayoutTest(buildLayout(), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('template_update_modal_test');
      expect(info.version).toBe('1.0.0');
    });

    // @scenario kind=template, area=layout_structure
    // @effects update_modal_layout_structure_is_declared
    it('초기 전역 상태가 올바르게 설정된다', () => {
      testUtils = createLayoutTest(buildLayout(), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
      const state = testUtils.getState();
      expect(state._global.selectedTemplate).toEqual(selected);
      expect(state._global.isTemplateUpdating).toBe(false);
      expect(state._global.layoutPreviewState).toBe('loading');
    });

    // @scenario kind=template, area=layout_structure
    // @effects update_modal_layout_structure_is_declared
    it('실제 창은 template_update_modal 이고 크기가 medium 이다', () => {
      expect(modal.name).toBe('Modal');
      expect(modal.id).toBe('template_update_modal');
      expect(modal.props.size).toBe('medium');
    });
  });

  describe('모달 열기/닫기', () => {
    // @scenario kind=template, area=modal_open_close
    // @effects update_modal_opens_and_closes
    it('업데이트 모달을 열고 닫을 수 있다', async () => {
      testUtils = createLayoutTest(buildLayout(), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
      await testUtils.render();

      testUtils.openModal('template_update_modal');
      expect(testUtils.getModalStack()).toContain('template_update_modal');

      testUtils.closeModal();
      expect(testUtils.getModalStack()).not.toContain('template_update_modal');
    });
  });

  describe('모달 콘텐츠 구조 검증', () => {
    // @scenario kind=template, area=modal_content
    // @effects update_modal_content_lists_versions
    it('모달 타이틀이 업데이트 확인으로 설정되어 있다', () => {
      expect(modal.props.title).toBe('$t:admin.templates.modals.update_title');
    });

    // @scenario kind=template, area=modal_content
    // @effects update_modal_content_lists_versions
    it('모달에 업데이트 확인 메시지가 포함되어 있다', () => {
      const confirm = textNodes('$t:admin.templates.modals.update_confirm');
      expect(confirm).toHaveLength(1);
      expect(confirm[0].text).toContain('_global.selectedTemplate.name');
    });

    // @scenario kind=template, area=modal_content
    // @effects update_modal_content_lists_versions
    it('모달에 버전 정보가 포함되어 있다 (from → to)', () => {
      expect(textNodes('$t:admin.templates.modals.field_version')).toHaveLength(1);
      const versions = textNodes('$t:admin.templates.modals.update_version_info');
      expect(versions).toHaveLength(1);
      expect(versions[0].text).toContain('_global.selectedTemplate.version');
      expect(versions[0].text).toContain('_global.selectedTemplate.latest_version');
    });

    // @scenario kind=template, area=modal_content
    // @effects update_modal_content_lists_versions
    it('모달에 update_source 표시가 포함되어 있다', () => {
      const source = textNodes('$t:admin.templates.modals.update_source_github');
      expect(source).toHaveLength(1);
      expect(source[0].text).toContain("_global.selectedTemplate.update_source === 'github'");
      expect(source[0].text).toContain('$t:admin.templates.modals.update_source_bundled');
    });

    // @scenario kind=template, area=modal_content
    // @effects update_modal_content_lists_versions
    it('모달에 changelog 링크가 조건부로 포함되어 있다', () => {
      const changelogDivs = collect(modal, (n) => n.if === '{{_global.selectedTemplate.github_changelog_url}}');
      expect(changelogDivs).toHaveLength(1);
      const link = collect(changelogDivs[0], (n) => n.name === 'A')[0];
      expect(link.props.target).toBe('_blank');
      expect(link.props.href).toContain('_global.selectedTemplate.github_changelog_url');
      expect(JSON.stringify(link)).toContain('$t:admin.templates.modals.view_changelog');
    });

    // @scenario kind=template, area=modal_content
    // @effects update_modal_content_lists_versions
    it('changelog URL이 없으면 조건에 의해 렌더링되지 않는다', () => {
      const changelogDiv = collect(modal, (n) => n.if === '{{_global.selectedTemplate.github_changelog_url}}')[0];
      const expr = changelogDiv.if.slice(2, -2);
      // eslint-disable-next-line no-new-func
      const evaluate = new Function('_global', `return (${expr});`);
      expect(evaluate({ selectedTemplate: { ...selected, github_changelog_url: null } })).toBeFalsy();
      expect(evaluate({ selectedTemplate: selected })).toBeTruthy();
    });
  });

  describe('버튼 구조 및 상태 검증', () => {
    // @scenario kind=template, area=button_state
    // @effects update_modal_buttons_reflect_progress
    it('취소/확인 버튼이 모달 하단에 정의되어 있다', () => {
      const buttons = footer().filter((n) => n.name === 'Button');
      expect(buttons).toHaveLength(2);
      const [cancelBtn, confirmBtn] = buttons;

      expect(cancelBtn.props.disabled).toBe('{{_global.isTemplateUpdating}}');
      expect(JSON.stringify(cancelBtn.children)).toContain('$t:common.cancel');
      expect(JSON.stringify(cancelBtn.actions)).toContain('closeModal');

      // 실행 버튼은 진행 중이거나 화면별 처리 미리보기가 준비되기 전에는 잠긴다(#888)
      expect(confirmBtn.props.disabled).toContain('_global.isTemplateUpdating');
      expect(confirmBtn.props.disabled).toContain("_global.layoutPreviewState !== 'ready'");
    });

    // @scenario kind=template, area=button_state
    // @effects update_modal_buttons_reflect_progress
    it('확인 버튼에 스피너 조건부 렌더링이 설정되어 있다', () => {
      const confirmBtn = footer().filter((n) => n.name === 'Button')[1];
      const spinner = confirmBtn.children.find((n: any) => n.name === 'Icon');
      expect(spinner.if).toBe('{{_global.isTemplateUpdating}}');
      expect(spinner.props.name).toBe('spinner');

      const label = confirmBtn.children.find((n: any) => n.name === 'Span');
      expect(label.text).toContain('_global.isTemplateUpdating');
      expect(label.text).toContain('$t:admin.templates.updating');
      expect(label.text).toContain('$t:admin.templates.actions.update');
    });

    // @scenario kind=template, area=button_state
    // @effects update_modal_buttons_reflect_progress
    it('isTemplateUpdating 상태가 올바르게 변경될 수 있다', async () => {
      testUtils = createLayoutTest(buildLayout(), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
      await testUtils.render();

      let state = testUtils.getState();
      expect(state._global.isTemplateUpdating).toBe(false);

      testUtils.setState('isTemplateUpdating', true, 'global');
      state = testUtils.getState();
      expect(state._global.isTemplateUpdating).toBe(true);

      testUtils.setState('isTemplateUpdating', false, 'global');
      testUtils.setState('selectedTemplate', null, 'global');
      state = testUtils.getState();
      expect(state._global.isTemplateUpdating).toBe(false);
      expect(state._global.selectedTemplate).toBeNull();
    });
  });
});

// ==============================
// 레이아웃 업데이트 전략 (실제 부분 레이아웃 렌더) — #727
// ==============================

describe('템플릿 업데이트 - 레이아웃 전략 선택 (실제 부분 레이아웃)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let modalPartial: any;
  let adminKo: any;
  let adminEn: any;

  const StrategyLabel: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
    <label className={className}>{children}</label>
  );
  const StrategyInput: React.FC<{ type?: string; name?: string; value?: string; checked?: boolean; className?: string }> = ({ type, name, value, checked, className }) => (
    <input type={type} name={name} value={value} checked={checked} className={className} readOnly />
  );
  // 레이아웃 테스트 유틸은 openModal 스택을 Modal 의 isOpen 에 잇지 않으므로, 이 describe 는 본문을 늘 그리는 Modal 로
  // 실제 부분 레이아웃의 전략 영역을 렌더해 본다.
  const StrategyModal: React.FC<{ id?: string; children?: React.ReactNode }> = ({ id, children }) => (
    <div data-testid={`modal-${id}`} role="dialog">{children}</div>
  );
  const StrategyImg: React.FC<{ src?: string; alt?: string; className?: string }> = ({ src, alt, className }) => (
    <img src={src} alt={alt} className={className} />
  );

  /**
   * 부분 레이아웃 트리에서 조건에 맞는 노드를 모두 모은다.
   */
  function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
    if (Array.isArray(node)) {
      node.forEach((child) => collect(child, match, found));
      return found;
    }
    if (node && typeof node === 'object') {
      if (match(node)) found.push(node);
      Object.values(node).forEach((value) => {
        if (value && typeof value === 'object') collect(value, match, found);
      });
    }
    return found;
  }

  /**
   * 실제 업데이트 모달을 띄운 레이아웃을 만든다.
   */
  function buildLayout(strategy: 'overwrite' | 'keep') {
    return {
      version: '1.0.0',
      layout_name: 'template_update_strategy_test',
      initGlobal: {
        selectedTemplate: {
          identifier: 'sirsoft-sample',
          name: '샘플',
          version: '1.0.0',
          latest_version: '1.1.0',
          update_source: 'bundled',
        },
        isTemplateUpdating: false,
        layoutSyncStrategy: strategy,
      },
      modals: [modalPartial],
      components: [{ id: 'main-content', type: 'basic', name: 'Div', children: [] }],
    };
  }

  beforeEach(async () => {
    const fs = await import('fs');
    const path = await import('path');
    const read = (relative: string) => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));
    modalPartial = read('../../layouts/partials/admin_template_list/_modal_update.json');
    adminKo = read('../../lang/partial/ko/admin.json');
    adminEn = read('../../lang/partial/en/admin.json');

    registry = setupTestRegistry();
    Object.assign((registry as any).registry, {
      Label: { component: StrategyLabel, metadata: { name: 'Label', type: 'basic' } },
      Input: { component: StrategyInput, metadata: { name: 'Input', type: 'basic' } },
      Img: { component: StrategyImg, metadata: { name: 'Img', type: 'basic' } },
      Modal: { component: StrategyModal, metadata: { name: 'Modal', type: 'composite' } },
    });
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  /**
   * 업데이트 창의 공통 미리보기 조각을 펼쳐 넣는다 — 렌더 시험은 서버가 하는 조각 풀이를 대신한다.
   */
  function inlineSharedPreview(node: any, shared: any): any {
    if (Array.isArray(node)) return node.map((child) => inlineSharedPreview(child, shared));
    if (node && typeof node === 'object') {
      if (node.partial === 'partials/_shared/_layout_sync_preview.json') return shared;
      return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, inlineSharedPreview(v, shared)]));
    }
    return node;
  }

  /**
   * 미리보기 결과를 실은 업데이트 창 레이아웃을 만든다 (#888).
   */
  function buildPreviewLayout(strategy: 'overwrite' | 'keep') {
    const fs = require('fs');
    const path = require('path');
    const { meta, ...shared } = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../layouts/partials/_shared/_layout_sync_preview.json'), 'utf-8'));
    void meta;
    const layout: any = buildLayout(strategy);
    layout.modals = [inlineSharedPreview(modalPartial, shared)];
    Object.assign(layout.initGlobal, {
      layoutSyncStrategy: strategy,
      layoutDecisions: {},
      layoutPreviewState: 'ready',
      layoutPreview: { entries: [{ key: 'layout:tpl:sample_list', kind: 'layout', template: 'tpl', name: 'sample_list', verdict: 'merged', conflicts: [], will_delete: false }], conflict_screens: [] },
      layoutUpdatePreview: { pending_token: null, declarative_deletions: [] },
    });

    return layout;
  }

  // @scenario kind=template, area=layout_strategy
  // @effects layout_strategy_choice_is_offered
  it('처리 방식은 공통 미리보기의 같은 라디오 그룹이고 기본값은 수정 유지다 (#888)', () => {
    const fs = require('fs');
    const path = require('path');
    const shared = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../layouts/partials/_shared/_layout_sync_preview.json'), 'utf-8'));
    const radios = collect(shared, (n) => n.name === 'Input' && n.props?.name === 'layout_sync_strategy');

    expect(collect(modalPartial, (n) => n.partial === 'partials/_shared/_layout_sync_preview.json')).toHaveLength(1);
    expect(radios.map((r) => r.props.value)).toEqual(['keep', 'overwrite']);
    expect(radios[0].props.checked).toBe("{{(_global.layoutSyncStrategy ?? 'keep') === 'keep'}}");
    expect(radios[1].props.checked).toBe("{{_global.layoutSyncStrategy === 'overwrite'}}");
  });

  // @scenario kind=template, area=layout_strategy
  // @effects layout_strategy_choice_is_offered
  it('업데이트 요청 본문에 고른 처리 방식·화면별 결정·내려받은 새 판 토큰이 실린다', () => {
    const calls = collect(modalPartial, (n) => n.handler === 'apiCall' && String(n.target ?? '').endsWith('/update'));

    expect(calls).toHaveLength(1);
    expect(calls[0].params.body.layout_strategy).toBe("{{_global.layoutSyncStrategy ?? 'keep'}}");
    expect(calls[0].params.body.decisions).toBe('{{_global.layoutDecisions ?? {} }}');
    expect(calls[0].params.body.pending_token).toBe('{{_global.layoutUpdatePreview?.pending_token ?? null}}');
  });

  // @scenario kind=template, area=layout_strategy
  // @effects layout_strategy_keep_notice_explains_merge
  it('수정 유지면 미리보기 표의 병합 화면에 화면별 결정 선택지가 보인다', async () => {
    testUtils = createLayoutTest(buildPreviewLayout('keep'), {
      translations: { admin: adminKo, common: { cancel: '취소' } },
      locale: 'ko',
      componentRegistry: registry,
      includeModals: true,
    });
    await testUtils.render();

    expect(await screen.findByText('sample_list')).toBeTruthy();
    expect(screen.getByText(adminKo.layout_sync_preview.decision_take_theirs)).toBeTruthy();
    expect(screen.getByText(adminKo.layout_sync_preview.strategy_keep_desc)).toBeTruthy();
  });

  // @scenario kind=template, area=layout_strategy
  // @effects layout_strategy_keep_notice_explains_merge
  it('모두 교체면 화면별 결정이 숨고 덮어쓰기 경고가 보인다', async () => {
    testUtils = createLayoutTest(buildPreviewLayout('overwrite'), {
      translations: { admin: adminKo, common: { cancel: '취소' } },
      locale: 'ko',
      componentRegistry: registry,
      includeModals: true,
    });
    await testUtils.render();

    expect(await screen.findByText(adminKo.layout_sync_preview.overwrite_warning)).toBeTruthy();
    expect(screen.queryByText(adminKo.layout_sync_preview.decision_take_theirs)).toBeNull();
  });

  // @scenario kind=template, area=layout_strategy
  // @effects layout_strategy_keep_notice_explains_merge
  it('유지 안내 문구가 "통째로 유지" 가 아니라 부분 병합을 설명한다 (ko·en)', () => {
    // 업데이트 창이 실제로 그리는 안내는 공통 미리보기 조각의 처리 방식 설명이다(#888) — 옛 창 전용 키는 더 그리지 않는다.
    const shared = readFileSync(resolve(__dirname, '../../layouts/partials/_shared/_layout_sync_preview.json'), 'utf-8');
    expect(shared).toContain('$t:admin.layout_sync_preview.strategy_keep_desc');

    expect(adminKo.layout_sync_preview.strategy_keep_desc).toContain('손대지 않은 부분만 새 판');
    expect(adminEn.layout_sync_preview.strategy_keep_desc).toContain('only untouched parts take the new version');
  });
});
