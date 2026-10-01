import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SortableMenuList, MenuItem } from '../SortableMenuList';

const sampleMenus: MenuItem[] = [
  { id: 1, name: '대시보드', slug: 'dashboard', url: '/admin/dashboard', icon: 'home', order: 1, is_active: true, parent_id: null, module_id: null, children: [] },
  {
    id: 2, name: '사용자 관리', slug: 'users', url: '/admin/users', icon: 'users', order: 2, is_active: true, parent_id: null, module_id: null,
    children: [
      { id: 3, name: '사용자 목록', slug: 'user-list', url: '/admin/users', icon: '', order: 1, is_active: true, parent_id: 2, module_id: null, children: [] },
    ],
  },
  { id: 4, name: '설정', slug: 'settings', url: '/admin/settings', icon: 'cog', order: 3, is_active: false, parent_id: null, module_id: null, children: [] },
];

/**
 * 여러 개 선택 모드 (#708) — 메뉴 관리 화면 「여러 개 선택」.
 * 체크박스 표시·끌어 옮기기 끔·상태 토글 숨김·항목 클릭이 체크 토글·부모는 자손 전체 토글·일부 선택 indeterminate.
 * 기본값(false)에서는 기존 동작(상세 선택 onSelect)이 그대로다.
 */
describe('SortableMenuList — 선택 모드', () => {
  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
  // @effects menu_list_selection_mode_disables_drag
  it('선택 모드에서는 체크박스가 보이고 드래그 핸들·상태 토글이 숨는다', () => {
    const { container } = render(<SortableMenuList items={sampleMenus} selectionMode selectedIds={[]} />);

    // 자식까지 모두 펼쳐진다 (4개 항목)
    expect(screen.getAllByRole('checkbox', { name: /대시보드|사용자 관리|사용자 목록|설정/ })).toHaveLength(4);
    expect(container.querySelector('[aria-roledescription="sortable"]')).toBeNull();
    expect(container.querySelectorAll('[role="switch"]').length).toBe(0);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=parent_with_children
  // @effects menu_list_selection_mode_disables_drag
  it('항목 클릭은 onSelect 대신 체크를 토글하고, 부모는 자손까지 함께 고른다', () => {
    const onSelect = vi.fn();
    const onSelectionChange = vi.fn();
    render(
      <SortableMenuList items={sampleMenus} selectionMode selectedIds={[]} onSelect={onSelect} onSelectionChange={onSelectionChange} />
    );

    fireEvent.click(screen.getByText('사용자 관리'));

    expect(onSelect).not.toHaveBeenCalled();
    expect([...onSelectionChange.mock.calls[0][0]].sort()).toEqual([2, 3]);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=child_only
  // @effects parent_auto_included
  it('자손 일부만 골라졌으면 부모가 indeterminate 로 보이고, 다시 누르면 전체가 골라진다', () => {
    const onSelectionChange = vi.fn();
    render(<SortableMenuList items={sampleMenus} selectionMode selectedIds={[3]} onSelectionChange={onSelectionChange} />);

    const parent = screen.getByRole('checkbox', { name: '사용자 관리' }) as HTMLInputElement;
    expect(parent.checked).toBe(false);
    expect(parent.indeterminate).toBe(true);

    fireEvent.click(parent);
    expect([...onSelectionChange.mock.calls[0][0]].sort()).toEqual([2, 3]);
  });

  // @scenario entry=menu_bulk_remove, actor=super_admin, menu_shape=parent_with_children
  // @effects children_auto_removed
  it('모두 골라진 부모를 누르면 부모와 자손이 함께 빠진다', () => {
    const onSelectionChange = vi.fn();
    render(<SortableMenuList items={sampleMenus} selectionMode selectedIds={[1, 2, 3]} onSelectionChange={onSelectionChange} />);

    fireEvent.click(screen.getByText('사용자 관리'));

    expect(onSelectionChange.mock.calls[0][0]).toEqual([1]);
  });

  // @scenario entry=menu_bulk_add, actor=super_admin, menu_shape=top_level
  // @effects menu_list_selection_mode_disables_drag
  it('기본값(선택 모드 아님)에서는 체크박스 없이 항목 클릭이 onSelect 로 간다', () => {
    const onSelect = vi.fn();
    render(<SortableMenuList items={sampleMenus} onSelect={onSelect} />);

    expect(screen.queryByRole('checkbox', { name: '대시보드' })).toBeNull();
    fireEvent.click(screen.getByText('대시보드'));
    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });
});
