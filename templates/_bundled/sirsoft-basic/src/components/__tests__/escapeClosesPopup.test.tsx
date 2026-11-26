/**
 * 펼친 목록 안 어디에서든 Esc 로 닫고 여는 단추로 돌아간다 (키보드 접근, 사용자 템플릿)
 *
 * 알림 판, 테마 메뉴, 작성자 메뉴(UserInfo), 검색어 제안은 바깥 클릭으로만 닫혀, 목록 안에서 Esc 를 눌러도 열린 채였다.
 * 이제 Esc 로 닫고 여는 단추로 포커스를 돌린다(검색어 제안은 입력칸에 남는다). 이 Esc 는 바깥으로 번지지 않아 목록을 품은
 * 창(Modal)까지 닫지 않는다 — 여는 단추는 Modal 이 「펼친 팝업」으로 알아보도록 `aria-haspopup` 과 `aria-expanded` 를 단다.
 *
 * 조합 요약(마커 아님, 평문): element=notification_center|theme_toggle|user_info|search_suggestions
 * 효과 요약(마커 아님, 평문): escape_inside_popup_closes_and_returns_focus
 */
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { NotificationCenter } from '../composite/NotificationCenter';
import { ThemeToggle } from '../composite/ThemeToggle';
import { UserInfo } from '../composite/UserInfo';
import { SearchBar } from '../composite/SearchBar';

let outerKeyDown: Mock<(event: React.KeyboardEvent<HTMLDivElement>) => void>;
let documentKeyDown: Mock<(event: KeyboardEvent) => void>;

beforeEach(() => {
  (window as any).G7Core = { ...(window as any).G7Core, t: (key: string) => key, navigate: vi.fn() };
  outerKeyDown = vi.fn<(event: React.KeyboardEvent<HTMLDivElement>) => void>();
  documentKeyDown = vi.fn<(event: KeyboardEvent) => void>();
  document.addEventListener('keydown', documentKeyDown);
});

afterEach(() => {
  document.removeEventListener('keydown', documentKeyDown);
});

const inOuter = (ui: React.ReactElement) => render(<div onKeyDown={outerKeyDown}>{ui}</div>);
const expectContained = () => {
  expect(outerKeyDown, 'Esc 가 바깥으로 번졌습니다').not.toHaveBeenCalled();
  expect(documentKeyDown.mock.calls.filter(([e]) => (e as KeyboardEvent).key === 'Escape')).toHaveLength(0);
};

describe('펼친 목록 안 Esc (사용자 템플릿)', () => {
  // @scenario element=notification_center
  // @effects escape_inside_popup_closes_and_returns_focus
  it('알림 판: 판 안에서 Esc 로 닫고 종 단추로 돌아간다', () => {
    const { container } = inOuter(<NotificationCenter titleText="알림" notifications={[]} />);
    const bell = screen.getByRole('button', { name: '알림' });
    fireEvent.click(bell);
    expect(bell.getAttribute('aria-expanded')).toBe('true');
    const panel = container.querySelector('[class*="w-80"]') as HTMLElement;
    expect(panel).not.toBeNull();

    fireEvent.keyDown(panel, { key: 'Escape' });

    expect(bell.getAttribute('aria-expanded'), '알림 판이 열린 채입니다').toBe('false');
    expect(document.activeElement).toBe(bell);
    expectContained();
  });

  // @scenario element=theme_toggle
  // @effects escape_inside_popup_closes_and_returns_focus
  it('테마 메뉴: 항목에서 Esc 로 닫고 여는 단추로 돌아간다', () => {
    inOuter(<ThemeToggle autoText="Auto" lightText="Light" darkText="Dark" />);
    const trigger = screen.getAllByRole('button')[0];
    fireEvent.click(trigger);
    const item = screen.getByText('Dark').closest('button') as HTMLElement;
    item.focus();

    fireEvent.keyDown(item, { key: 'Escape' });

    expect(screen.queryByText('Dark'), '테마 메뉴가 열린 채입니다').toBeNull();
    expect(document.activeElement).toBe(trigger);
    expect(trigger.getAttribute('aria-haspopup')).toBeTruthy();
    expectContained();
  });

  // @scenario element=user_info
  // @effects escape_inside_popup_closes_and_returns_focus
  it('작성자 메뉴: 메뉴 항목에서 Esc 로 닫고 이름 단추로 돌아간다', () => {
    inOuter(<UserInfo author={{ id: 1, uuid: 'u-1', name: '홍길동' } as any} showDropdown />);
    const nameButton = screen.getByRole('button', { name: '홍길동' });
    fireEvent.click(nameButton);
    const menu = screen.getByTestId('author-dropdown-menu');
    const item = (menu.querySelector('button, a') ?? menu) as HTMLElement;
    item.focus();

    fireEvent.keyDown(item, { key: 'Escape' });

    expect(screen.queryByTestId('author-dropdown-menu'), '작성자 메뉴가 열린 채입니다').toBeNull();
    expect(document.activeElement).toBe(nameButton);
    expect(nameButton.getAttribute('aria-haspopup')).toBe('menu');
    expectContained();
  });

  // @scenario element=search_suggestions
  // @effects escape_inside_popup_closes_and_returns_focus
  it('검색어 제안: 입력칸에서 Esc 로 제안을 닫고 입력칸에 남는다', () => {
    inOuter(<SearchBar value="ab" onChange={() => {}} showSuggestions suggestions={[{ id: 1, text: 'abc 제안' }]} />);
    const input = (screen.queryByRole('searchbox') ?? screen.getByRole('textbox')) as HTMLInputElement;
    fireEvent.focus(input);
    input.focus();
    expect(screen.getByText('abc 제안')).toBeTruthy();

    fireEvent.keyDown(input, { key: 'Escape' });

    expect(screen.queryByText('abc 제안'), '제안이 열린 채입니다').toBeNull();
    expect(document.activeElement).toBe(input);
    expectContained();
  });
});
