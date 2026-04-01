/**
 * @file admin-extension-list-abilities.test.tsx
 * @description 모듈/플러그인/템플릿 관리 페이지 abilities 기반 버튼 비활성화 렌더링 테스트
 *
 * 테스트 대상:
 * - collection-level abilities (can_install, can_activate, can_uninstall)
 * - per-row abilities 표현식 패턴 (row.abilities.can_install !== true)
 * - 페이지 레벨 버튼: 업데이트 확인, 수동 설치
 * - per-row 버튼: 설치, 업데이트, 활성화/비활성화 토글
 * - ActionMenu 항목: 레이아웃 갱신, 삭제 (status + abilities 복합 조건)
 *
 * 참고: 실제 레이아웃에서 `row`는 DataGrid iteration 컨텍스트로 제공되지만,
 * 테스트에서는 `_local.row`로 시뮬레이션하여 표현식 패턴을 검증합니다.
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
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

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

const TestPageHeader: React.FC<{
  title?: string;
  description?: string;
  children?: React.ReactNode;
}> = ({ title, description, children }) => (
  <div data-testid="page-header">
    {title && <h1>{title}</h1>}
    {description && <p>{description}</p>}
    {children}
  </div>
);

const TestToggle: React.FC<{
  disabled?: boolean;
  checked?: boolean;
  'data-testid'?: string;
}> = ({ disabled, checked, 'data-testid': testId }) => (
  <input
    type="checkbox"
    checked={checked}
    disabled={disabled}
    data-testid={testId}
    readOnly
  />
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    PageHeader: { component: TestPageHeader, metadata: { name: 'PageHeader', type: 'composite' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 페이지 레벨 버튼 테스트 레이아웃
 * - collection-level abilities: modules?.data?.abilities?.can_install
 * - isCheckingUpdates 상태와 OR 조건
 */
const pageLevelLayout = {
  version: '1.0.0',
  layout_name: 'test_page_level_abilities',
  data_sources: [
    {
      id: 'modules',
      type: 'api',
      endpoint: '/api/admin/modules',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      fallback: {
        data: {
          data: [],
          abilities: { can_install: false, can_activate: false, can_uninstall: false },
        },
      },
    },
  ],
  state: {
    isCheckingUpdates: false,
  },
  components: [
    {
      id: 'page_content',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'check_updates_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{_local.isCheckingUpdates || modules?.data?.abilities?.can_install !== true}}',
            'data-testid': 'check-updates-button',
          },
          text: '업데이트 확인',
        },
        {
          id: 'manual_install_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{modules?.data?.abilities?.can_install !== true}}',
            className: 'disabled:opacity-50 disabled:cursor-not-allowed',
            'data-testid': 'manual-install-button',
          },
          text: '수동 설치',
        },
      ],
    },
  ],
};

/**
 * per-row 버튼 테스트 레이아웃
 * 실제 레이아웃에서 `row`는 DataGrid iteration 컨텍스트이지만,
 * 테스트에서는 _local.row로 시뮬레이션하여 동일한 표현식 패턴 검증
 */
const rowLevelLayout = {
  version: '1.0.0',
  layout_name: 'test_row_level_abilities',
  data_sources: [],
  state: {
    row: {
      status: 'active',
      abilities: { can_install: false, can_activate: false, can_uninstall: false },
    },
  },
  components: [
    {
      id: 'row_content',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'row_install_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{_local.row.abilities?.can_install !== true}}',
            className: 'disabled:opacity-50 disabled:cursor-not-allowed',
            'data-testid': 'row-install-button',
          },
          text: '설치',
        },
        {
          id: 'row_update_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{_local.row.abilities?.can_install !== true}}',
            className: 'disabled:opacity-50 disabled:cursor-not-allowed',
            'data-testid': 'row-update-button',
          },
          text: '업데이트',
        },
        {
          id: 'row_toggle',
          type: 'basic',
          name: 'Toggle',
          props: {
            disabled: '{{_local.row.abilities?.can_activate !== true}}',
            'data-testid': 'row-toggle',
          },
        },
        {
          id: 'action_refresh_layouts',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: "{{_local.row.status != 'active' || _local.row.abilities?.can_activate !== true}}",
            'data-testid': 'action-refresh-layouts',
          },
          text: '레이아웃 갱신',
        },
        {
          id: 'action_uninstall',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: "{{_local.row.status == 'uninstalled' || _local.row.status == 'uninstalling' || _local.row.status == 'installing' || _local.row.abilities?.can_uninstall !== true}}",
            'data-testid': 'action-uninstall',
          },
          text: '삭제',
        },
      ],
    },
  ],
};

// Mock API 응답 생성 헬퍼
function createModulesResponse(
  collectionAbilities: { can_install: boolean; can_activate: boolean; can_uninstall: boolean },
) {
  return {
    success: true,
    data: {
      data: [
        {
          id: 'sirsoft-ecommerce',
          name: { ko: '전자상거래', en: 'E-Commerce' },
          status: 'active',
          abilities: collectionAbilities,
        },
      ],
      abilities: collectionAbilities,
      current_page: 1,
      last_page: 1,
      per_page: 12,
      total: 1,
    },
  };
}

describe('모듈/플러그인/템플릿 관리 - abilities 기반 버튼 비활성화', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('페이지 레벨 버튼 - collection abilities', () => {
    it('모든 권한 있음 → 업데이트 확인, 수동 설치 버튼 활성화', async () => {
      const testUtils = createLayoutTest(pageLevelLayout);
      testUtils.mockApi('modules', {
        response: createModulesResponse(
          { can_install: true, can_activate: true, can_uninstall: true },
        ),
      });

      await testUtils.render();

      const checkUpdatesBtn = screen.getByTestId('check-updates-button');
      const manualInstallBtn = screen.getByTestId('manual-install-button');
      expect(checkUpdatesBtn).not.toBeDisabled();
      expect(manualInstallBtn).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('can_install=false → 업데이트 확인, 수동 설치 버튼 비활성화', async () => {
      const testUtils = createLayoutTest(pageLevelLayout);
      testUtils.mockApi('modules', {
        response: createModulesResponse(
          { can_install: false, can_activate: true, can_uninstall: true },
        ),
      });

      await testUtils.render();

      const checkUpdatesBtn = screen.getByTestId('check-updates-button');
      const manualInstallBtn = screen.getByTestId('manual-install-button');
      expect(checkUpdatesBtn).toBeDisabled();
      expect(manualInstallBtn).toBeDisabled();

      testUtils.cleanup();
    });

    it('isCheckingUpdates=true → 업데이트 확인 버튼 비활성화 (can_install=true여도)', async () => {
      // isCheckingUpdates를 true로 시작하는 별도 레이아웃
      const layoutWithChecking = {
        ...pageLevelLayout,
        layout_name: 'test_page_level_checking',
        state: { isCheckingUpdates: true },
      };

      const testUtils = createLayoutTest(layoutWithChecking);
      testUtils.mockApi('modules', {
        response: createModulesResponse(
          { can_install: true, can_activate: true, can_uninstall: true },
        ),
      });

      await testUtils.render();

      const checkUpdatesBtn = screen.getByTestId('check-updates-button');
      expect(checkUpdatesBtn).toBeDisabled();

      // 수동 설치는 isCheckingUpdates와 무관하게 can_install만 체크
      const manualInstallBtn = screen.getByTestId('manual-install-button');
      expect(manualInstallBtn).not.toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('per-row 버튼 - row abilities', () => {
    it('모든 권한 있음 → 설치, 업데이트, 토글 활성화', async () => {
      const layoutWithFullAbilities = {
        ...rowLevelLayout,
        layout_name: 'test_row_full_abilities',
        state: {
          row: {
            status: 'active',
            abilities: { can_install: true, can_activate: true, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layoutWithFullAbilities);
      await testUtils.render();

      expect(screen.getByTestId('row-install-button')).not.toBeDisabled();
      expect(screen.getByTestId('row-update-button')).not.toBeDisabled();
      expect(screen.getByTestId('row-toggle')).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('can_install=false → 설치, 업데이트 버튼 비활성화', async () => {
      const layoutNoInstall = {
        ...rowLevelLayout,
        layout_name: 'test_row_no_install',
        state: {
          row: {
            status: 'active',
            abilities: { can_install: false, can_activate: true, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layoutNoInstall);
      await testUtils.render();

      expect(screen.getByTestId('row-install-button')).toBeDisabled();
      expect(screen.getByTestId('row-update-button')).toBeDisabled();
      // 토글은 can_activate=true이므로 활성화
      expect(screen.getByTestId('row-toggle')).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('can_activate=false → 토글 비활성화', async () => {
      const layoutNoActivate = {
        ...rowLevelLayout,
        layout_name: 'test_row_no_activate',
        state: {
          row: {
            status: 'active',
            abilities: { can_install: true, can_activate: false, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layoutNoActivate);
      await testUtils.render();

      expect(screen.getByTestId('row-toggle')).toBeDisabled();
      // 설치 버튼은 can_install=true이므로 활성화
      expect(screen.getByTestId('row-install-button')).not.toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('ActionMenu 항목 - status + abilities 복합 조건', () => {
    it('active + 모든 권한 → 레이아웃 갱신 활성화', async () => {
      const layout = {
        ...rowLevelLayout,
        layout_name: 'test_action_active_full',
        state: {
          row: {
            status: 'active',
            abilities: { can_install: true, can_activate: true, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      expect(screen.getByTestId('action-refresh-layouts')).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('inactive + can_activate=false → 레이아웃 갱신 비활성화', async () => {
      const layout = {
        ...rowLevelLayout,
        layout_name: 'test_action_inactive_no_activate',
        state: {
          row: {
            status: 'inactive',
            abilities: { can_install: true, can_activate: false, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      // inactive != 'active' → true, OR 연산으로 이미 disabled
      expect(screen.getByTestId('action-refresh-layouts')).toBeDisabled();

      testUtils.cleanup();
    });

    it('active + can_activate=false → 레이아웃 갱신 비활성화 (status는 OK지만 권한 부족)', async () => {
      const layout = {
        ...rowLevelLayout,
        layout_name: 'test_action_active_no_activate',
        state: {
          row: {
            status: 'active',
            abilities: { can_install: true, can_activate: false, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      expect(screen.getByTestId('action-refresh-layouts')).toBeDisabled();

      testUtils.cleanup();
    });

    it('inactive + can_uninstall=false → 삭제 비활성화', async () => {
      const layout = {
        ...rowLevelLayout,
        layout_name: 'test_action_no_uninstall',
        state: {
          row: {
            status: 'inactive',
            abilities: { can_install: true, can_activate: true, can_uninstall: false },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      expect(screen.getByTestId('action-uninstall')).toBeDisabled();

      testUtils.cleanup();
    });

    it('inactive + can_uninstall=true → 삭제 활성화', async () => {
      const layout = {
        ...rowLevelLayout,
        layout_name: 'test_action_uninstall_ok',
        state: {
          row: {
            status: 'inactive',
            abilities: { can_install: true, can_activate: true, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      expect(screen.getByTestId('action-uninstall')).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('uninstalled 상태 → can_uninstall=true여도 삭제 비활성화', async () => {
      const layout = {
        ...rowLevelLayout,
        layout_name: 'test_action_uninstalled',
        state: {
          row: {
            status: 'uninstalled',
            abilities: { can_install: true, can_activate: true, can_uninstall: true },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      expect(screen.getByTestId('action-uninstall')).toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('모든 권한 없는 사용자 (읽기 전용)', () => {
    it('페이지 레벨 + per-row 모든 버튼 비활성화', async () => {
      const noAbilities = { can_install: false, can_activate: false, can_uninstall: false };

      // 페이지 레벨
      const pageLevelTest = createLayoutTest(pageLevelLayout);
      pageLevelTest.mockApi('modules', {
        response: createModulesResponse(noAbilities),
      });

      await pageLevelTest.render();
      expect(screen.getByTestId('check-updates-button')).toBeDisabled();
      expect(screen.getByTestId('manual-install-button')).toBeDisabled();
      pageLevelTest.cleanup();

      // per-row 레벨
      const rowLayout = {
        ...rowLevelLayout,
        layout_name: 'test_readonly_row',
        state: {
          row: { status: 'active', abilities: noAbilities },
        },
      };

      const rowLevelTest = createLayoutTest(rowLayout);
      await rowLevelTest.render();
      expect(screen.getByTestId('row-install-button')).toBeDisabled();
      expect(screen.getByTestId('row-update-button')).toBeDisabled();
      expect(screen.getByTestId('row-toggle')).toBeDisabled();
      expect(screen.getByTestId('action-refresh-layouts')).toBeDisabled();
      expect(screen.getByTestId('action-uninstall')).toBeDisabled();
      rowLevelTest.cleanup();
    });
  });
});
