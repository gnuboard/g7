/**
 * 메뉴 정렬 항목 — 마우스로 고를 수 있으면 키보드로도 고른다 (결정 「키보드 접근 전부」)
 *
 * `/admin/menus` 실측에서 항목이 「선택형이라 키보드 분기 밖」으로 보였다. 일반 모드의 항목은 덮는 링크(`<a href>`)라
 * 브라우저가 Tab 과 Enter 를 준다. 일괄 선택 모드의 항목은 체크박스가 받는데, 체크박스는 Space 로만 토글되고 Enter 로는
 * 고를 수 없었다. 체크박스가 Enter 도 받게 한다. 끌기 손잡이는 목록의 KeyboardSensor(Space 들기, 화살표 이동, Space 놓기)가 맡는다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DndContext } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { SortableMenuItem, MenuItemData } from '../SortableMenuItem';

const item: MenuItemData = { id: 1, name: '대시보드', slug: 'dashboard', url: '/admin/dashboard', icon: 'home', isActive: true, isModuleMenu: false };
const wrap = (ui: React.ReactElement) =>
  render(
    <DndContext>
      <SortableContext items={[1]} strategy={verticalListSortingStrategy}>
        {ui}
      </SortableContext>
    </DndContext>
  );

describe('메뉴 정렬 항목 키보드 선택', () => {
  // @scenario mode=link
  // @effects menu_item_selectable_by_keyboard
  it('일반 모드: 항목은 덮는 링크라 Tab 으로 닿고, 그 링크를 누르면 항목을 고른다', () => {
    const onClick = vi.fn();
    wrap(<SortableMenuItem item={{ ...item, href: '/admin/menus?menu=dashboard&mode=view' }} onClick={onClick} />);
    const link = screen.getByRole('link', { name: /대시보드/ });
    expect(link.getAttribute('href')).toContain('menu=dashboard');
    expect(link.getAttribute('tabindex')).not.toBe('-1');
    fireEvent.click(link);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario mode=selectable
  // @effects menu_item_selectable_by_keyboard
  it('일괄 선택 모드: 체크박스가 Tab 으로 닿고 Enter 로도 고른다', () => {
    const onClick = vi.fn();
    wrap(<SortableMenuItem item={item} selectable onClick={onClick} />);
    const box = screen.getByRole('checkbox', { name: '대시보드' });
    expect(box.getAttribute('tabindex')).not.toBe('-1');
    fireEvent.keyDown(box, { key: 'Enter' });
    expect(onClick, 'Enter 로 고를 수 없습니다').toHaveBeenCalledTimes(1);
  });
});
