/**
 * 눌러 쓰는 비단추 요소의 키보드 접근 (#869, 설계 확정 2026-11-08 「전부 #869 에서 고침」)
 *
 * 클릭만 받던 div·span 이 Tab 으로 닿지 않고 Enter·Space 로도 동작하지 않았다. 부품이 클릭을 받을 때 의미에 맞는 role,
 * tabIndex, Enter·Space 동작, 키보드 포커스 표시를 준다(공통 도우미 support/keyboardActivation).
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DndContext } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Flex } from '../layout/Flex';
import { Grid } from '../layout/Grid';
import { Card } from '../composite/Card';
import { CategoryTree } from '../composite/CategoryTree';
import { Dropdown } from '../composite/Dropdown';
import { DropdownMenuItem } from '../composite/DropdownMenuItem';
import { SortableMenuItem } from '../composite/SortableMenuItem';

const press = (el: HTMLElement, key: 'Enter' | ' ') => fireEvent.keyDown(el, { key });

describe('키보드 접근 — 클릭을 받는 비단추 요소', () => {
  // @scenario element=flex
  // @effects clickable_element_keyboard_operable
  it('Flex: 클릭을 받으면 role=button, tabIndex=0, Enter·Space 로 동작하고 포커스 표시를 단다', () => {
    const onClick = vi.fn();
    const { container, rerender } = render(<Flex onClick={onClick}>가</Flex>);
    const el = container.firstChild as HTMLElement;
    expect(el.getAttribute('role')).toBe('button');
    expect(el.tabIndex).toBe(0);
    expect(el.className).toContain('focus-visible:ring-2');
    press(el, 'Enter');
    press(el, ' ');
    expect(onClick).toHaveBeenCalledTimes(2);
    rerender(<Flex>가</Flex>);
    expect((container.firstChild as HTMLElement).getAttribute('role')).toBeNull();
    expect((container.firstChild as HTMLElement).hasAttribute('tabindex')).toBe(false);
  });

  // @scenario element=grid
  // @effects clickable_element_keyboard_operable
  it('Grid: 클릭을 받으면 키보드로도 동작한다', () => {
    const onClick = vi.fn();
    const { container } = render(<Grid onClick={onClick}>가</Grid>);
    const el = container.firstChild as HTMLElement;
    expect(el.getAttribute('role')).toBe('button');
    press(el, ' ');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=card
  // @effects clickable_element_keyboard_operable
  it('Card: 클릭을 받으면 키보드로도 동작한다', () => {
    const onClick = vi.fn();
    const { container } = render(<Card title="카드" onClick={onClick} />);
    const el = container.querySelector('.card-clickable') as HTMLElement;
    expect(el.getAttribute('role')).toBe('button');
    expect(el.tabIndex).toBe(0);
    press(el, 'Enter');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=category_toggle
  // @effects clickable_element_keyboard_operable
  it('CategoryTree: 펼치기 칸은 이름 있는 단추이고 Enter 로 펼친다', () => {
    const onToggle = vi.fn();
    render(
      <CategoryTree
        data={[{ id: 1, name: 'Electronics', localized_name: '전자기기', children: [{ id: 2, name: 'Phones', localized_name: '스마트폰', children: [] }] } as any]}
        onToggle={onToggle}
      />,
    );
    const toggle = screen.getByTestId('toggle-1');
    expect(toggle.getAttribute('role')).toBe('button');
    expect(toggle.tabIndex).toBe(0);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.getAttribute('aria-label')).toBeTruthy();
    press(toggle, 'Enter');
    press(toggle, ' ');
    // 펼침 상태는 호출처가 쥔다(expandedIds) — 키보드가 클릭과 같은 펼치기 요청을 낸다
    expect(onToggle).toHaveBeenCalledTimes(2);
    expect(onToggle).toHaveBeenNthCalledWith(1, [1]);
  });

  // @scenario element=dropdown_item
  // @effects clickable_element_keyboard_operable
  it('Dropdown: 메뉴 항목을 Tab 으로 잡고 Space·Enter 로 고르면 한 번만 고른다', () => {
    const onItemClick = vi.fn();
    render(<Dropdown label="선택" items={[{ label: '하나', value: 'a' }, { label: '둘', value: 'b' }]} onItemClick={onItemClick} />);
    fireEvent.click(screen.getByText('선택'));
    const item = screen.getByText('둘');
    item.focus();
    press(item, ' ');
    expect(onItemClick).toHaveBeenCalledTimes(1);
    expect(onItemClick).toHaveBeenCalledWith('b', expect.anything());
    fireEvent.click(screen.getByText('선택'));
    press(screen.getByText('하나'), 'Enter');
    expect(onItemClick).toHaveBeenCalledTimes(2);
  });

  // @scenario element=menu_item
  // @effects clickable_element_keyboard_operable
  it('DropdownMenuItem: menuitem 이고 키보드로 동작하며, 비활성이면 Tab 에서 빠지고 동작하지 않는다', () => {
    const onClick = vi.fn();
    const { rerender } = render(<DropdownMenuItem label="복사" onClick={onClick} />);
    const item = screen.getByRole('menuitem', { name: /복사/ });
    expect(item.tabIndex).toBe(0);
    press(item, 'Enter');
    expect(onClick).toHaveBeenCalledTimes(1);
    rerender(<DropdownMenuItem label="복사" onClick={onClick} disabled />);
    const disabled = screen.getByRole('menuitem', { name: /복사/ });
    expect(disabled.tabIndex).toBe(-1);
    expect(disabled.getAttribute('aria-disabled')).toBe('true');
    press(disabled, 'Enter');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=sortable_row
  // @effects clickable_element_keyboard_operable
  it('SortableMenuItem: 링크가 없는 행은 키보드로 고를 수 있다', () => {
    const onClick = vi.fn();
    render(
      <DndContext>
        <SortableContext items={[1]} strategy={verticalListSortingStrategy}>
          <SortableMenuItem item={{ id: 1, name: '대시보드', slug: 'dashboard', url: '/admin', icon: 'home', isActive: true, isModuleMenu: false } as any} onClick={onClick} />
        </SortableContext>
      </DndContext>,
    );
    const row = screen.getByText('대시보드').closest('[role="button"][tabindex="0"]') as HTMLElement;
    expect(row).not.toBeNull();
    press(row, 'Enter');
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
