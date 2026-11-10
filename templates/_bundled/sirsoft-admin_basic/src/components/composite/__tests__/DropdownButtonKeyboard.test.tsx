/**
 * DropdownButton — 포털 메뉴를 키보드로 열고 고른다 (#869 8차 8부, 관리자 쪽 같은 꼴)
 *
 * 메뉴는 포털로 문서 끝에 붙는다. 종전에는 Enter 로 열어도 포커스가 단추에 남고 화살표, Tab 처리가 없어 키보드로는
 * 항목까지 가기 어려웠다. ActionMenu 와 같은 규칙(연 순간 첫 항목, 화살표 순환, Home, End, Esc, Tab)을 쓴다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { DropdownButton } from '../DropdownButton';
import { DropdownMenuItem } from '../DropdownMenuItem';

async function openWith(key: 'Enter' | 'ArrowUp') {
  render(
    <DropdownButton label="작업">
      <DropdownMenuItem label="수정" onClick={() => {}} />
      <DropdownMenuItem label="복사" onClick={() => {}} />
      <DropdownMenuItem label="삭제" onClick={() => {}} />
    </DropdownButton>
  );
  const trigger = screen.getByRole('button', { name: /작업/ });
  trigger.focus();
  await act(async () => {
    fireEvent.keyDown(trigger, { key });
    if (key === 'Enter') fireEvent.click(trigger, { detail: 0 });
  });
  return trigger;
}

const items = () => screen.getAllByRole('menuitem');

describe('DropdownButton 키보드', () => {
  // @scenario case=open_enter_first
  // @effects portal_menu_keyboard_operable
  it('Enter 로 열면 role=menu 가 단추와 이어지고 첫 항목으로 포커스가 가며 화살표로 돈다', async () => {
    const trigger = await openWith('Enter');
    const menu = screen.getByRole('menu');
    expect(trigger.getAttribute('aria-controls')).toBe(menu.id);
    const all = items();
    expect(document.activeElement).toBe(all[0]);
    fireEvent.keyDown(all[0], { key: 'ArrowUp' });
    expect(document.activeElement).toBe(all[all.length - 1]);
    fireEvent.keyDown(all[all.length - 1], { key: 'ArrowDown' });
    expect(document.activeElement).toBe(all[0]);
  });

  // @scenario case=open_arrowup_last
  // @effects portal_menu_keyboard_operable
  it('닫힌 단추에서 ArrowUp 으로 열면 마지막 항목으로 간다', async () => {
    await openWith('ArrowUp');
    const all = items();
    expect(document.activeElement).toBe(all[all.length - 1]);
  });

  // @scenario case=tab_returns
  // @effects portal_menu_keyboard_operable
  it('Tab 은 메뉴를 닫고 포커스를 단추에 둔다', async () => {
    const trigger = await openWith('Enter');
    await act(async () => {
      fireEvent.keyDown(items()[0], { key: 'Tab' });
    });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  // @scenario case=escape_returns
  // @effects portal_menu_keyboard_operable
  it('Esc 는 메뉴를 닫고 단추로 돌아간다', async () => {
    const trigger = await openWith('Enter');
    await act(async () => {
      fireEvent.keyDown(items()[0], { key: 'Escape' });
    });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });
});
