import React, { useState } from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MenuAccessTree, MenuAccessNode } from '../MenuAccessTree';

/**
 * MenuAccessTree — 역할 폼 「접근 가능 메뉴」 트리 (#708)
 *
 * 부모·자식 규칙(자식 체크 → 부모 자동 체크, 부모 해제 → 자식 해제), indeterminate 표기,
 * 관리자 역할 잠금(lockedAll), 권한 없음(disabled) 시각, 트리 밖 ID 보존, 빈 데이터 편집기 패리티를 잠근다.
 *
 * 시나리오 SSoT: tests/scenarios/role-menu-bulk-assignment.yaml
 */

const tree: MenuAccessNode[] = [
  { id: 1, name: '대시보드', is_active: true, children: [] },
  {
    id: 2,
    name: { ko: '회원 관리', en: 'Users' },
    is_active: true,
    children: [
      { id: 3, name: '회원 목록', is_active: true },
      { id: 4, name: '역할 관리', is_active: true },
    ],
  },
  { id: 5, name: '개인정보', is_active: false, extension_type: 'plugin', extension_identifier: 'sirsoft-gdpr', children: [] },
];

/** 값 변화를 바깥에서 관찰하는 제어 래퍼 */
function Harness({ initial = [], onValue, ...rest }: { initial?: number[]; onValue?: (v: number[]) => void } & Record<string, unknown>) {
  const [value, setValue] = useState<number[]>(initial);

  return (
    <MenuAccessTree
      data={tree}
      value={value}
      onChange={(e) => {
        setValue(e.target.value);
        onValue?.(e.target.value);
      }}
      {...rest}
    />
  );
}

/** 메뉴 이름으로 체크박스를 찾는다 */
const box = (label: string) => screen.getByRole('checkbox', { name: label }) as HTMLInputElement;

const sorted = (ids: number[]) => [...ids].sort((a, b) => a - b);

describe('MenuAccessTree', () => {
  // @scenario entry=role_form_update, actor=super_admin, menu_shape=child_only
  // @effects parent_auto_included
  it('자식을 체크하면 부모도 체크되고 부모는 indeterminate 로 보인다', () => {
    const onValue = vi.fn();
    render(<Harness onValue={onValue} />);

    fireEvent.click(screen.getByRole('button', { name: 'common.expand' }));
    fireEvent.click(box('회원 목록'));

    expect(sorted(onValue.mock.calls.at(-1)[0])).toEqual([2, 3]);
    expect(box('회원 관리').checked).toBe(true);
    expect(box('회원 관리').indeterminate).toBe(true);
    expect(screen.getByTestId('menu-access-count').textContent).toBe('2/5');
  });

  // @scenario entry=role_form_update, actor=super_admin, menu_shape=parent_with_children
  // @effects children_auto_removed
  it('부모 체크는 자식 전체를, 부모 해제는 자식 전체를 함께 토글한다', () => {
    const onValue = vi.fn();
    render(<Harness onValue={onValue} />);

    fireEvent.click(box('회원 관리'));
    expect(sorted(onValue.mock.calls.at(-1)[0])).toEqual([2, 3, 4]);
    expect(box('회원 관리').indeterminate).toBe(false);

    fireEvent.click(box('회원 관리'));
    expect(onValue.mock.calls.at(-1)[0]).toEqual([]);
  });

  // @scenario entry=role_form_update, actor=super_admin, menu_shape=inactive_extension
  // @effects invisible_assignment_preserved
  it('전체 선택·전체 해제는 트리 밖 ID(관리할 수 없는 메뉴)를 건드리지 않는다', () => {
    const onValue = vi.fn();
    render(<Harness initial={[99]} onValue={onValue} />);

    fireEvent.click(screen.getByText('admin.roles.form.menus_select_all'));
    expect(sorted(onValue.mock.calls.at(-1)[0])).toEqual([1, 2, 3, 4, 5, 99]);

    fireEvent.click(screen.getByText('admin.roles.form.menus_clear_all'));
    expect(onValue.mock.calls.at(-1)[0]).toEqual([99]);
  });

  // @scenario entry=role_form_update, actor=super_admin, menu_shape=inactive_extension
  // @effects admin_role_locked
  it('lockedAll 이면 전부 체크된 읽기 전용이고 클릭해도 값이 바뀌지 않는다', () => {
    const onValue = vi.fn();
    render(<Harness lockedAll onValue={onValue} />);

    const boxes = screen.getAllByRole('checkbox') as HTMLInputElement[];
    expect(boxes.every((b) => b.checked && b.disabled)).toBe(true);
    fireEvent.click(box('대시보드'));
    expect(onValue).not.toHaveBeenCalled();
    expect(screen.getByTestId('menu-access-count').textContent).toBe('5/5');
  });

  // @scenario entry=role_form_update, actor=no_menu_permission, menu_shape=top_level
  // @effects menu_tree_readonly_without_permission
  it('disabled 면 체크박스·버튼이 비활성이고 행이 흐리게(opacity-50·cursor-not-allowed) 보인다', () => {
    const { container } = render(<Harness disabled initial={[1]} />);

    expect(box('대시보드').disabled).toBe(true);
    expect((screen.getByText('admin.roles.form.menus_select_all') as HTMLButtonElement).disabled).toBe(true);
    const row = container.querySelector('[data-menu-id="1"] > div') as HTMLElement;
    expect(row.className).toContain('opacity-50');
    expect(row.className).toContain('cursor-not-allowed');
  });

  // @scenario entry=role_form_update, actor=super_admin, menu_shape=top_level
  // @effects menu_tree_readonly_without_permission
  it('비활성 메뉴는 흐린 글씨와 비활성 배지로 표시한다', () => {
    render(<Harness />);

    expect(screen.getByText('개인정보').className).toContain('text-gray-400');
    expect(screen.getByText('admin.roles.form.menus_inactive_badge')).toBeTruthy();
  });

  // @scenario entry=role_form_create, actor=super_admin, menu_shape=top_level
  // @effects menu_tree_readonly_without_permission
  it('빈 데이터에서도 루트에 id/editorAttrs 를 붙이고 안내 문구를 보인다', () => {
    const { container } = render(
      <MenuAccessTree data={[]} value={[]} id="mat-empty" editorAttrs={{ 'data-editor-name': 'MenuAccessTree' } as never} />
    );
    const root = container.querySelector('[data-editor-name="MenuAccessTree"]');
    expect(root).toHaveAttribute('id', 'mat-empty');
    expect((root?.textContent ?? '').trim().length).toBeGreaterThan(0);
  });
});
