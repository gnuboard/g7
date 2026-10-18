import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { SortableMenuList, MenuItem } from '../SortableMenuList';

const ROLES = [
  { id: 1, name: { ko: '관리자', en: 'Administrator' } },
  { id: 2, name: { ko: '편집자', en: 'Editor' } },
  { id: 3, name: { ko: '검수자', en: 'Reviewer' } },
  { id: 4, name: { ko: '상담원', en: 'Agent' } },
  { id: 5, name: { ko: '회계', en: 'Accounting' } },
];

const menus: MenuItem[] = [
  { id: 10, name: '대시보드', slug: 'dashboard', url: '/admin/dashboard', icon: 'home', order: 1, is_active: true, parent_id: null, module_id: null, children: [], role_ids: [1, 2] },
  {
    id: 20, name: '사용자 관리', slug: 'users', url: '/admin/users', icon: 'users', order: 2, is_active: true, parent_id: null, module_id: null, role_ids: [1, 2, 3, 4, 5],
    children: [
      { id: 21, name: '사용자 목록', slug: 'user-list', url: '/admin/users', icon: '', order: 1, is_active: true, parent_id: 20, module_id: null, children: [], role_ids: [1, 99] },
    ],
  },
  { id: 30, name: '빈 메뉴', slug: 'bare', url: '/admin/bare', icon: '', order: 3, is_active: true, parent_id: null, module_id: null, children: [], role_ids: [] },
];

/** 항목 이름이 든 행의 역할 배지 줄 */
const rolesRowOf = (menuName: string): HTMLElement => {
  const row = screen.getByText(menuName).closest('[data-testid="menu-item-roles"]')
    ?? screen.getByText(menuName).parentElement!.parentElement!.querySelector('[data-testid="menu-item-roles"]');

  return row as HTMLElement;
};

/**
 * 메뉴 관리 목록 — 메뉴마다 부여된 역할을 행에서 바로 보인다 (#708 추가 요구).
 * 목록 응답은 역할 ID 만 싣고, 이름은 화면이 가진 역할 목록(roleOptions)에서 찾는다.
 */
describe('SortableMenuList — 행마다 부여된 역할', () => {
  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects menu_list_shows_assigned_roles
  it('role_ids 를 역할 이름 배지로 보이고, 3개를 넘으면 「외 n개」 로 접고 전체는 title 에 둔다', () => {
    render(<SortableMenuList items={menus} roleOptions={ROLES} enableDrag={false} />);

    const dashboard = rolesRowOf('대시보드');
    expect(within(dashboard).getByText('관리자')).toBeTruthy();
    expect(within(dashboard).getByText('편집자')).toBeTruthy();

    const users = rolesRowOf('사용자 관리');
    expect(within(users).getByText('관리자')).toBeTruthy();
    expect(within(users).getByText('검수자')).toBeTruthy();
    expect(within(users).queryByText('상담원')).toBeNull();
    expect(within(users).getByText('admin.menus.list_roles_more')).toBeTruthy();
    expect(users.getAttribute('title')).toContain('상담원, 회계');
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects menu_list_shows_assigned_roles
  it('역할이 하나도 없는 메뉴는 「역할 없음」 으로 드러낸다', () => {
    render(<SortableMenuList items={menus} roleOptions={ROLES} enableDrag={false} />);

    expect(within(rolesRowOf('빈 메뉴')).getByText('admin.menus.list_roles_none')).toBeTruthy();
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
  // @effects menu_list_shows_assigned_roles
  it('하위 메뉴도 배지를 보이고, 역할 목록에 없는 ID(비활성 역할 등)는 건너뛴다', () => {
    render(<SortableMenuList items={menus} roleOptions={ROLES} selectionMode selectedIds={[]} />);

    const child = rolesRowOf('사용자 목록');
    expect(within(child).getByText('관리자')).toBeTruthy();
    expect(child.textContent).not.toContain('99');
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
  // @effects menu_list_shows_assigned_roles
  it('이름표 밖 역할(비활성 역할 등)도 개수에는 센다 — 「외 n개」 와 title 이 부여된 역할 수를 줄여 말하지 않는다 (실측 O-D6)', () => {
    // 비활성 역할도 접근(사이드바·권한)을 그대로 주므로, 이름을 몰라도 「몇 개 더 있다」 는 보여야 한다.
    const withUnknown: MenuItem[] = [{ ...menus[0], role_ids: [1, 2, 3, 98, 99] }];
    render(<SortableMenuList items={withUnknown} roleOptions={ROLES} enableDrag={false} />);

    const row = rolesRowOf('대시보드');
    // 이름 있는 배지 3개 + 「외 2개」(98·99) — 종전엔 이름 3개뿐이라 「외 n개」 가 없었다
    expect(within(row).getByText('관리자')).toBeTruthy();
    expect(within(row).getByText('검수자')).toBeTruthy();
    expect(within(row).getByText('admin.menus.list_roles_more')).toBeTruthy();
    // title 은 이름을 아는 역할 뒤에 이름 모르는 역할 수를 덧붙인다
    expect(row.getAttribute('title')).toContain('관리자, 편집자, 검수자 admin.menus.list_roles_more');
    expect(row.textContent).not.toContain('98');
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects menu_list_shows_assigned_roles
  it('역할 목록이 아직 안 왔으면 「역할 없음」 이라고 단정하지 않고 배지 줄을 비워 둔다 (실측: 로그인 직후 진입)', () => {
    const { container, rerender } = render(<SortableMenuList items={menus} roleOptions={[]} enableDrag={false} />);

    expect(container.querySelectorAll('[data-testid="menu-item-roles"]').length).toBe(0);
    expect(screen.queryByText('admin.menus.list_roles_none')).toBeNull();

    rerender(<SortableMenuList items={menus} roleOptions={ROLES} enableDrag={false} />);
    expect(within(rolesRowOf('대시보드')).getByText('관리자')).toBeTruthy();
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
  // @effects menu_list_shows_assigned_roles
  it('부여된 역할이 모두 목록 밖(비활성 등)이면 「역할 없음」 으로 오표기하지 않는다', () => {
    const onlyUnknown: MenuItem[] = [{ ...menus[0], role_ids: [98, 99] }];
    const { container } = render(<SortableMenuList items={onlyUnknown} roleOptions={ROLES} enableDrag={false} />);

    expect(container.querySelectorAll('[data-testid="menu-item-roles"]').length).toBe(0);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects menu_list_shows_assigned_roles
  it('roleOptions 를 주지 않으면 배지 줄을 그리지 않는다 (기존 사용처 무변경)', () => {
    const { container } = render(<SortableMenuList items={menus} enableDrag={false} />);

    expect(container.querySelectorAll('[data-testid="menu-item-roles"]').length).toBe(0);
  });
});
