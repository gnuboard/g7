/**
 * @file admin-menu-list-child-roles.test.tsx
 * @description 메뉴 관리 — 하위 메뉴 선택 시 역할 보강 회귀 테스트
 *
 * 회귀 배경 (#518 / 공개 #76):
 * 목록 응답에서 `children[].roles` 를 프루닝한 뒤, 화면이 목록 행을 그대로 `_global.selectedMenu`
 * 로 쓰면 하위 메뉴의 역할 배지가 "모든 역할" 로 표시되고, 편집 폼이 빈 역할 배열을 저장에 실어
 * 보내 그 메뉴의 역할 제한이 전부 해제된다.
 *
 * 이 테스트는 **실제 파셜 JSON** 을 읽어 onSelect 액션 정의를 그대로 실행한다.
 *
 * @scenario resource=menu,endpoint=list,observation=consumer_screen
 * @scenario resource=menu,endpoint=detail,observation=consumer_screen
 * @effects menu_selection_hydrates_roles_from_detail
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const PARTIAL_PATH = path.resolve(
  __dirname,
  '../../layouts/partials/admin_menu_list/_panel_menu_list.json',
);

/** 목록 응답 — 하위 메뉴에는 roles 키가 없다 (#76 프루닝) */
const MENU_ROWS = [
  {
    id: 10,
    name: { ko: '부모 메뉴' },
    slug: 'parent-menu',
    url: '/parent',
    icon: 'fa-folder',
    order: 1,
    is_active: true,
    parent_id: null,
    roles: [{ id: 1, name: { ko: '관리자' }, permission_type: 'read' }],
    children: [
      {
        id: 11,
        name: { ko: '자식 메뉴' },
        slug: 'child-menu',
        url: '/parent/child',
        icon: 'fa-file',
        order: 1,
        is_active: true,
        parent_id: 10,
      },
    ],
  },
];

const CHILD_DETAIL = {
  id: 11,
  name: { ko: '자식 메뉴' },
  slug: 'child-menu',
  url: '/parent/child',
  icon: 'fa-file',
  order: 1,
  is_active: true,
  parent_id: 10,
  roles: [
    { id: 3, name: { ko: '편집자' }, permission_type: 'read' },
    { id: 5, name: { ko: '검수자' }, permission_type: 'read' },
  ],
};

/** 트리를 평탄화해 각 행마다 onSelect 를 쏘는 버튼을 만든다 (실제 목록의 선택 가능 행과 동일). */
const TestSortableMenuList: React.FC<{
  items?: any[];
  onSelect?: (row: any) => void;
}> = ({ items, onSelect }) => {
  const flat: any[] = [];
  const walk = (rows: any[]) => {
    for (const row of rows ?? []) {
      flat.push(row);
      if (row.children?.length) {
        walk(row.children);
      }
    }
  };
  walk(items ?? []);

  return (
    <div data-testid="sortable-menu-list">
      {flat.map((row) => (
        <button key={row.id} data-testid={`select-${row.slug}`} onClick={() => onSelect?.(row)}>
          {row.slug}
        </button>
      ))}
    </div>
  );
};

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <div className={className}>{children}</div>
);

const TestButton: React.FC<{ children?: React.ReactNode; text?: string; onClick?: () => void }> = ({
  children,
  text,
  onClick,
}) => <button onClick={onClick}>{children || text}</button>;

const TestSpan: React.FC<{ children?: React.ReactNode; text?: string }> = ({ children, text }) => (
  <span>{children || text}</span>
);

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

function setupTestRegistry(): void {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H3: { component: TestSpan, metadata: { name: 'H3', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    SortableMenuList: {
      component: TestSortableMenuList,
      metadata: { name: 'SortableMenuList', type: 'composite' },
    },
    // 렌더 루트가 Fragment 다 — 빠뜨리면 아무것도 그려지지 않는다.
    Fragment: {
      component: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
      metadata: { name: 'Fragment', type: 'layout' },
    },
  };
}

/** 실제 파셜 JSON 을 읽어 렌더 가능한 레이아웃으로 감싼다. */
function buildLayoutFromPartial(): any {
  const partial = JSON.parse(fs.readFileSync(PARTIAL_PATH, 'utf-8'));

  // 파셜 노드의 메타 키(layout_name)와 템플릿 전용 lifecycle 핸들러(initMenuFromUrl)는
  // 단독 렌더에서 해석 대상이 아니므로 걷어낸다. 검증 대상인 actions 는 그대로 둔다.
  delete partial.layout_name;
  delete partial.lifecycle;

  return {
    version: '1.0.0',
    layout_name: 'test_admin_menu_list_child_roles',
    data_sources: [
      {
        id: 'menus',
        type: 'api',
        endpoint: '/api/admin/menus',
        method: 'GET',
        auth_required: true,
        params: { with_children: true, hierarchical: true },
        fallback: { data: [] },
      },
    ],
    state: { selectedMenuId: null, selectedMenu: null, panelMode: 'view' },
    // 파셜은 단일 컴포넌트 노드다 (components 배열이 아니다).
    components: [partial],
  };
}

describe('메뉴 관리 — 하위 메뉴 선택 시 역할 보강 (#76)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  it('실제 파셜의 onSelect 가 단건 조회를 호출한다', async () => {
    testUtils = createLayoutTest(buildLayoutFromPartial());
    testUtils.mockApi('menus', {
      response: { success: true, data: { data: MENU_ROWS, abilities: { can_update: true } } },
    });
    testUtils.mockApi('/api/admin/menus/11', { response: { success: true, data: CHILD_DETAIL } });

    await testUtils.render();

    // 존재를 먼저 확정한 뒤 동작을 단언한다.
    const childButton = await screen.findByTestId('select-child-menu');
    expect(childButton).toBeInTheDocument();

    fireEvent.click(childButton);

    await waitFor(() => {
      const calls = (globalThis.fetch as any).mock.calls.map((call: any[]) => String(call[0]));
      expect(calls.some((url: string) => url.includes('/api/admin/menus/11'))).toBe(true);
    });
  });

  it('상위 메뉴 선택도 같은 경로로 단건을 조회한다 (경로가 하위 전용이면 URL 직접 진입에서 다시 갈린다)', async () => {
    testUtils = createLayoutTest(buildLayoutFromPartial());
    testUtils.mockApi('menus', {
      response: { success: true, data: { data: MENU_ROWS, abilities: { can_update: true } } },
    });
    testUtils.mockApi('/api/admin/menus/10', { response: { success: true, data: MENU_ROWS[0] } });

    await testUtils.render();

    const parentButton = await screen.findByTestId('select-parent-menu');
    expect(parentButton).toBeInTheDocument();

    fireEvent.click(parentButton);

    await waitFor(() => {
      const calls = (globalThis.fetch as any).mock.calls.map((call: any[]) => String(call[0]));
      expect(calls.some((url: string) => url.includes('/api/admin/menus/10'))).toBe(true);
    });
  });

  it('단건 조회 응답이 selectedMenu 로 반영되도록 onSuccess 가 배선되어 있다', () => {
    const partial = JSON.parse(fs.readFileSync(PARTIAL_PATH, 'utf-8'));
    const menuList = partial.children.find((child: any) => child.id === 'sortable_menu_list');
    const onSelect = menuList.actions.find((action: any) => action.event === 'onSelect');
    const detailCall = onSelect.actions.find(
      (action: any) => action.handler === 'apiCall' && String(action.target).includes('/api/admin/menus/'),
    );

    // 존재를 먼저 확정한 뒤 배선을 단언한다.
    expect(detailCall).toBeDefined();
    expect(detailCall.onSuccess).toBeDefined();

    const write = detailCall.onSuccess.find((action: any) => action.handler === 'setState');
    expect(write).toBeDefined();
    expect(write.params.target).toBe('global');
    expect(write.params.selectedMenu).toBe('{{response.data}}');
  });

  it('목록 응답의 하위 메뉴 행에는 roles 키가 없다 (프루닝 전제 고정)', () => {
    const child = MENU_ROWS[0].children[0];

    expect(child).not.toHaveProperty('roles');
  });
});
