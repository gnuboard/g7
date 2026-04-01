/**
 * @file admin-menu-list-abilities.test.tsx
 * @description 메뉴 관리 페이지 abilities 기반 UI 비활성화 렌더링 테스트
 *
 * 테스트 대상:
 * - abilities.can_create=false → 메뉴 추가 버튼 disabled
 * - abilities.can_update=false → SortableMenuList enableDrag=false, toggleDisabled=true
 * - abilities.can_delete=false → 삭제 버튼 disabled
 * - abilities.can_update=false → 편집 버튼 disabled
 * - 모든 abilities=true → 모든 UI 활성 상태
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

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
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

const TestH3: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h3 className={className}>{children || text}</h3>
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

const TestSortableMenuList: React.FC<{
  items?: any[];
  selectedId?: number | null;
  enableDrag?: boolean;
  toggleDisabled?: boolean;
  className?: string;
  'data-testid'?: string;
}> = ({ items, enableDrag, toggleDisabled, 'data-testid': testId }) => (
  <div
    data-testid={testId || 'sortable-menu-list'}
    data-enable-drag={String(enableDrag ?? true)}
    data-toggle-disabled={String(toggleDisabled ?? false)}
    data-items-count={String((items ?? []).length)}
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
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    PageHeader: { component: TestPageHeader, metadata: { name: 'PageHeader', type: 'composite' } },
    SortableMenuList: { component: TestSortableMenuList, metadata: { name: 'SortableMenuList', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 메뉴 추가 버튼 + SortableMenuList가 포함된 간략화 레이아웃
const menuListLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_menu_list',
  data_sources: [
    {
      id: 'menus',
      type: 'api',
      endpoint: '/api/admin/menus',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      params: { with_children: true, hierarchical: true },
      fallback: { data: [] },
    },
  ],
  state: {
    selectedMenuId: null,
    selectedMenu: null,
    panelMode: 'view',
  },
  components: [
    {
      id: 'menu_list_content',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'page_header',
          type: 'composite',
          name: 'PageHeader',
          props: {
            title: '메뉴 관리',
          },
          children: [
            {
              id: 'add_menu_button',
              type: 'basic',
              name: 'Button',
              props: {
                disabled: '{{!(menus?.data?.abilities?.can_create)}}',
                className: 'flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed',
                'data-testid': 'add-menu-button',
              },
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  text: '메뉴 추가',
                },
              ],
            },
          ],
        },
        {
          id: 'main_grid',
          type: 'basic',
          name: 'Div',
          children: [
            {
              id: 'sortable_menu_list',
              type: 'composite',
              name: 'SortableMenuList',
              props: {
                items: '{{menus?.data?.data ?? []}}',
                selectedId: '{{_global.selectedMenuId}}',
                enableDrag: '{{menus?.data?.abilities?.can_update ?? false}}',
                toggleDisabled: '{{!(menus?.data?.abilities?.can_update ?? false)}}',
                'data-testid': 'sortable-menu-list',
              },
            },
          ],
        },
      ],
    },
  ],
};

// 편집/삭제 버튼이 포함된 뷰 패널 레이아웃
const viewPanelLayout = {
  version: '1.0.0',
  layout_name: 'test_admin_menu_view_panel',
  data_sources: [
    {
      id: 'menus',
      type: 'api',
      endpoint: '/api/admin/menus',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      fallback: { data: [] },
    },
  ],
  state: {
    selectedMenuId: 1,
    selectedMenu: { id: 1, name: '테스트', extension_type: null },
    panelMode: 'view',
  },
  components: [
    {
      id: 'view_panel',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'edit_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{!(menus?.data?.abilities?.can_update)}}',
            className: 'border disabled:opacity-50 disabled:cursor-not-allowed',
            'data-testid': 'edit-button',
          },
          text: '편집',
        },
        {
          id: 'delete_button',
          type: 'basic',
          name: 'Button',
          if: "{{_global.selectedMenu?.extension_type !== 'core'}}",
          props: {
            disabled: '{{!(menus?.data?.abilities?.can_delete)}}',
            className: 'text-red-600 disabled:opacity-50 disabled:cursor-not-allowed',
            'data-testid': 'delete-button',
          },
          text: '삭제',
        },
      ],
    },
  ],
};

// Mock API 응답 생성 헬퍼
function createMenusResponse(abilities: { can_create: boolean; can_update: boolean; can_delete: boolean }) {
  return {
    success: true,
    data: {
      data: [
        { id: 1, name: '대시보드', slug: 'dashboard', url: '/admin', icon: 'home', is_active: true },
      ],
      abilities,
    },
  };
}

describe('메뉴 관리 페이지 - abilities 기반 UI 비활성화', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('모든 권한이 있는 사용자 (abilities 모두 true)', () => {
    it('메뉴 추가 버튼이 활성화됨', async () => {
      const testUtils = createLayoutTest(menuListLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: true, can_update: true, can_delete: true }),
      });

      await testUtils.render();

      const addButton = screen.getByTestId('add-menu-button');
      expect(addButton).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('SortableMenuList에 enableDrag=true, toggleDisabled=false 전달', async () => {
      const testUtils = createLayoutTest(menuListLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: true, can_update: true, can_delete: true }),
      });

      await testUtils.render();

      const menuList = screen.getByTestId('sortable-menu-list');
      expect(menuList.getAttribute('data-enable-drag')).toBe('true');
      expect(menuList.getAttribute('data-toggle-disabled')).toBe('false');

      testUtils.cleanup();
    });

    it('편집/삭제 버튼이 활성화됨', async () => {
      const testUtils = createLayoutTest(viewPanelLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: true, can_update: true, can_delete: true }),
      });

      await testUtils.render();

      const editButton = screen.getByTestId('edit-button');
      const deleteButton = screen.getByTestId('delete-button');
      expect(editButton).not.toBeDisabled();
      expect(deleteButton).not.toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('읽기 전용 사용자 (abilities 모두 false)', () => {
    it('메뉴 추가 버튼이 비활성화됨', async () => {
      const testUtils = createLayoutTest(menuListLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: false, can_update: false, can_delete: false }),
      });

      await testUtils.render();

      const addButton = screen.getByTestId('add-menu-button');
      expect(addButton).toBeDisabled();

      testUtils.cleanup();
    });

    it('SortableMenuList에 enableDrag=false, toggleDisabled=true 전달', async () => {
      const testUtils = createLayoutTest(menuListLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: false, can_update: false, can_delete: false }),
      });

      await testUtils.render();

      const menuList = screen.getByTestId('sortable-menu-list');
      expect(menuList.getAttribute('data-enable-drag')).toBe('false');
      expect(menuList.getAttribute('data-toggle-disabled')).toBe('true');

      testUtils.cleanup();
    });

    it('편집 버튼이 비활성화됨', async () => {
      const testUtils = createLayoutTest(viewPanelLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: false, can_update: false, can_delete: false }),
      });

      await testUtils.render();

      const editButton = screen.getByTestId('edit-button');
      expect(editButton).toBeDisabled();

      testUtils.cleanup();
    });

    it('삭제 버튼이 비활성화됨', async () => {
      const testUtils = createLayoutTest(viewPanelLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: false, can_update: false, can_delete: false }),
      });

      await testUtils.render();

      const deleteButton = screen.getByTestId('delete-button');
      expect(deleteButton).toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('부분 권한 사용자 (can_create만 true)', () => {
    it('메뉴 추가 버튼은 활성화, 편집/삭제는 비활성화', async () => {
      const testUtils = createLayoutTest(viewPanelLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: true, can_update: false, can_delete: false }),
      });

      await testUtils.render();

      const editButton = screen.getByTestId('edit-button');
      const deleteButton = screen.getByTestId('delete-button');
      expect(editButton).toBeDisabled();
      expect(deleteButton).toBeDisabled();

      testUtils.cleanup();
    });

    it('드래그 비활성화, 토글 비활성화', async () => {
      const testUtils = createLayoutTest(menuListLayout);
      testUtils.mockApi('menus', {
        response: createMenusResponse({ can_create: true, can_update: false, can_delete: false }),
      });

      await testUtils.render();

      const addButton = screen.getByTestId('add-menu-button');
      expect(addButton).not.toBeDisabled();

      const menuList = screen.getByTestId('sortable-menu-list');
      expect(menuList.getAttribute('data-enable-drag')).toBe('false');
      expect(menuList.getAttribute('data-toggle-disabled')).toBe('true');

      testUtils.cleanup();
    });
  });
});
