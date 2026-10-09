/**
 * @file ActionMenu.a11y.test.tsx
 * @description ActionMenu 접근성 회귀 테스트 (7.1.0 영역 J D-J5, 관측 O-F11·⋯ 「ellipsis」)
 *
 * 결함:
 * - 아이콘만 있는 트리거(목록 행 메뉴·카드 더보기)가 글리프 이름 「ellipsis h」·「ellipsis v」 로 읽혔다.
 * - 메뉴 항목이 이름 없는 div 라 키보드로 닿을 수 없고 접근성 트리에 글자만 보였다.
 * - 잠긴 위험 항목(빨강)은 흐려지지 않아 눌릴 것처럼 보였다.
 * - 트리거 기본 글자가 언어와 무관하게 「작업」 이었다.
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ActionMenu, ActionMenuItem } from '../ActionMenu';
import { IconName } from '../../basic/IconTypes';

const DICT: Record<string, string> = {
  'common.more_actions': '더보기',
  'common.actions': '작업',
};

describe('ActionMenu 접근성', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  const items: ActionMenuItem[] = [
    { id: 'edit', label: '편집' },
    { id: 'delete', label: '삭제', variant: 'danger', disabled: true },
    { id: 'copy', label: '복제' },
  ];

  /**
   * @scenario control=action_menu
   * @effects action_menu_trigger_named_by_locale
   */
  it('글자 없는 트리거는 사람이 읽는 「더보기」 이름과 메뉴 팝업 속성을 갖는다', () => {
    render(<ActionMenu items={items} triggerLabel="" triggerIconName={IconName.EllipsisHorizontal} />);
    const trigger = screen.getByRole('button', { name: '더보기' });
    expect(trigger.getAttribute('aria-haspopup')).toBe('menu');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });

  /**
   * @scenario control=action_menu
   * @effects action_menu_trigger_named_by_locale
   */
  it('트리거 기본 글자는 번역 키(common.actions)를 따른다', () => {
    (window as any).G7Core = { t: (key: string) => (key === 'common.actions' ? 'Actions' : key) };
    render(<ActionMenu items={items} />);
    expect(screen.getByRole('button', { name: 'Actions' })).toBeTruthy();
  });

  /**
   * @scenario control=action_menu
   * @effects action_menu_items_are_keyboard_menuitems
   */
  it('열린 메뉴는 role=menu, 항목은 menuitem 단추이고 잠긴 항목은 disabled 다', () => {
    render(<ActionMenu items={items} triggerLabel="" />);
    fireEvent.click(screen.getByRole('button', { name: '더보기' }));

    expect(screen.getByRole('menu')).toBeTruthy();
    const menuItems = screen.getAllByRole('menuitem');
    expect(menuItems.map((m) => m.textContent?.trim())).toEqual(['편집', '삭제', '복제']);
    expect(menuItems.every((m) => m.tagName === 'BUTTON')).toBe(true);
    const locked = screen.getByRole('menuitem', { name: '삭제' });
    expect((locked as HTMLButtonElement).disabled).toBe(true);
    expect(locked.className).not.toContain('text-red-600');
    expect(screen.getByRole('button', { name: '더보기' }).getAttribute('aria-expanded')).toBe('true');
  });

  /**
   * @scenario control=action_menu
   * @effects action_menu_items_are_keyboard_menuitems
   */
  it('열면 첫 항목에 초점이 가고 화살표가 잠긴 항목을 건너뛰며, Esc 는 닫고 트리거로 돌아간다', () => {
    const onItemClick = vi.fn();
    render(<ActionMenu items={items} triggerLabel="" onItemClick={onItemClick} />);
    const trigger = screen.getByRole('button', { name: '더보기' });
    fireEvent.click(trigger);

    expect(document.activeElement?.textContent?.trim()).toBe('편집');
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'ArrowDown' });
    expect(document.activeElement?.textContent?.trim()).toBe('복제');
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'ArrowDown' });
    expect(document.activeElement?.textContent?.trim()).toBe('편집');

    fireEvent.click(document.activeElement as HTMLElement);
    expect(onItemClick).toHaveBeenCalledWith('edit', expect.objectContaining({ id: 'edit' }));

    fireEvent.click(trigger);
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });
});
