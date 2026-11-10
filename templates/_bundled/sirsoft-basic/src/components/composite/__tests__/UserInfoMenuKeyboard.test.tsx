/**
 * 작성자 메뉴(UserInfo) — 키보드로 열고 고른다 (#869 8차 8부)
 *
 * 메뉴는 포털로 문서 끝에 붙는다. 종전에는 Enter 로 열어도 포커스가 이름 단추에 남아, Tab 은 메뉴 밖 다음 글로 가고
 * ArrowDown 은 움직이지 않았다. WAI-ARIA 메뉴 단추 관례대로 연 순간 첫 항목(ArrowUp 이면 마지막)으로 포커스를 옮기고,
 * 화살표 순환, Home, End, Esc(닫고 단추로), Tab(닫고 단추 다음으로)을 처리한다.
 *
 * 조합 요약(마커 아님, 평문): case=open_enter_first|arrows_cycle|home_end|open_arrowup_last|escape_returns|tab_returns
 * 효과 요약(마커 아님, 평문): portal_menu_keyboard_operable
 */
import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { UserInfo } from '../UserInfo';

const g7 = {
  t: vi.fn((key: string) => ({ 'userinfo.view_profile': '프로필 보기', 'userinfo.view_posts': '작성글 보기' } as Record<string, string>)[key] ?? key),
  navigate: vi.fn(),
};

beforeEach(() => {
  (window as any).G7Core = g7;
});
afterEach(() => {
  delete (window as any).G7Core;
});

async function openWith(key: 'Enter' | 'ArrowUp') {
  render(
    <div>
      <UserInfo author={{ id: 1, uuid: 'user-uuid-1', name: '홍길동' }} showDropdown />
      <button type="button">다음 글</button>
    </div>
  );
  const trigger = screen.getByRole('button', { name: '홍길동' });
  trigger.focus();
  await act(async () => {
    fireEvent.keyDown(trigger, { key });
    // 브라우저는 단추 위 Enter 를 click 으로 바꾼다(detail 0)
    if (key === 'Enter') fireEvent.click(trigger, { detail: 0 });
  });
  return trigger;
}

const items = () => screen.getAllByRole('menuitem');

describe('작성자 메뉴 키보드', () => {
  // @scenario case=open_enter_first
  // @effects portal_menu_keyboard_operable
  it('Enter 로 열면 메뉴(role=menu)가 단추와 이어지고 첫 항목으로 포커스가 간다', async () => {
    const trigger = await openWith('Enter');
    const menu = screen.getByRole('menu');
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(trigger.getAttribute('aria-controls')).toBe(menu.id);
    expect(items().every((i) => i.getAttribute('tabindex') === '-1')).toBe(true);
    expect(document.activeElement).toBe(items()[0]);
  });

  // @scenario case=arrows_cycle
  // @effects portal_menu_keyboard_operable
  it('ArrowDown 과 ArrowUp 은 항목 사이를 돌고, 끝에서 처음으로 넘어간다', async () => {
    await openWith('Enter');
    const [first, second] = items();
    fireEvent.keyDown(first, { key: 'ArrowDown' });
    expect(document.activeElement).toBe(second);
    fireEvent.keyDown(second, { key: 'ArrowDown' });
    expect(document.activeElement).toBe(first);
    fireEvent.keyDown(first, { key: 'ArrowUp' });
    expect(document.activeElement).toBe(second);
  });

  // @scenario case=home_end
  // @effects portal_menu_keyboard_operable
  it('Home 과 End 는 첫 항목과 마지막 항목으로 간다', async () => {
    await openWith('Enter');
    const all = items();
    fireEvent.keyDown(all[0], { key: 'End' });
    expect(document.activeElement).toBe(all[all.length - 1]);
    fireEvent.keyDown(all[all.length - 1], { key: 'Home' });
    expect(document.activeElement).toBe(all[0]);
  });

  // @scenario case=open_arrowup_last
  // @effects portal_menu_keyboard_operable
  it('닫힌 단추에서 ArrowUp 으로 열면 마지막 항목으로 간다', async () => {
    await openWith('ArrowUp');
    const all = items();
    expect(document.activeElement).toBe(all[all.length - 1]);
  });

  // @scenario case=escape_returns
  // @effects portal_menu_keyboard_operable
  it('Esc 는 메뉴를 닫고 이름 단추로 돌아간다', async () => {
    const trigger = await openWith('Enter');
    await act(async () => {
      fireEvent.keyDown(items()[0], { key: 'Escape' });
    });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  // @scenario case=tab_returns
  // @effects portal_menu_keyboard_operable
  it('Tab 은 메뉴를 닫고 포커스를 이름 단추에 둔다 — 기본 Tab 이동이 단추 다음 자리로 간다', async () => {
    const trigger = await openWith('Enter');
    await act(async () => {
      fireEvent.keyDown(items()[0], { key: 'Tab' });
    });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });
});
