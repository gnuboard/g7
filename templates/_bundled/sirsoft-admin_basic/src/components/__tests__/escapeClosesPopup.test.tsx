/**
 * 펼친 목록 안 어디에서든 Esc 로 닫고 여는 단추로 돌아간다 (#869 키보드 접근)
 *
 * 아이콘 선택(메뉴 편집 폼), 알림 판, 내 정보 메뉴, 테마 메뉴, 다국어 입력의 언어 추가 메뉴, 검색어 제안은 바깥 클릭으로만
 * 닫혀, 목록 안(검색칸, 항목)에서 Esc 를 눌러도 열린 채였다. 이제 Esc 로 닫고 여는 단추로 포커스를 돌린다(검색어 제안은
 * 입력칸에 남는다). 이 Esc 는 바깥으로 번지지 않아 목록을 품은 창(Modal)까지 닫지 않는다 — 여는 단추는 Modal 이 「펼친
 * 팝업」으로 알아보도록 `aria-haspopup` 과 `aria-expanded` 를 단다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { IconSelect } from '../composite/IconSelect';
import { NotificationCenter } from '../composite/NotificationCenter';
import { UserProfile } from '../composite/UserProfile';
import { ThemeToggle } from '../composite/ThemeToggle';
import { MultilingualInput } from '../composite/MultilingualInput';
import { SearchBar } from '../composite/SearchBar';
import { DropdownButton } from '../composite/DropdownButton';

let outerKeyDown: Mock<(e: React.KeyboardEvent<HTMLDivElement>) => void>;
let documentKeyDown: Mock<(e: KeyboardEvent) => string>;

beforeEach(() => {
  (window as any).G7Core = {
    ...(window as any).G7Core,
    t: (key: string) => ({ 'common.add_language': '언어 추가' } as Record<string, string>)[key] ?? key,
    locale: { supported: () => ['ko', 'en'], current: () => 'ko' },
  };
  outerKeyDown = vi.fn<(e: React.KeyboardEvent<HTMLDivElement>) => void>();
  documentKeyDown = vi.fn((e: KeyboardEvent) => e.key);
  document.addEventListener('keydown', documentKeyDown);
});

afterEach(() => {
  document.removeEventListener('keydown', documentKeyDown);
});

/** 바깥(창 몸통 자리)에 키 처리기를 둔 감싸개 */
const inOuter = (ui: React.ReactElement) => render(<div onKeyDown={outerKeyDown}>{ui}</div>);

/** Esc 가 번지지 않았는가 — 바깥 감싸개와 문서 버블 리스너 모두 받지 않아야 Modal 이 닫히지 않는다 */
const expectContained = () => {
  expect(outerKeyDown, 'Esc 가 바깥으로 번졌습니다').not.toHaveBeenCalled();
  expect(documentKeyDown.mock.calls.filter(([e]) => e.key === 'Escape')).toHaveLength(0);
};

describe('펼친 목록 안 Esc (#869)', () => {
  // @scenario element=icon_select
  // @effects escape_inside_popup_closes_and_returns_focus
  it('아이콘 선택: 검색칸에서 Esc 로 닫고 여는 단추로 돌아간다', () => {
    inOuter(<IconSelect value="" onChange={() => {}} searchPlaceholder="아이콘 검색" />);
    const trigger = screen.getAllByRole('button')[0];
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-haspopup')).toBeTruthy();
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    const search = screen.getByPlaceholderText('아이콘 검색');
    search.focus();

    fireEvent.keyDown(search, { key: 'Escape' });

    expect(screen.queryByPlaceholderText('아이콘 검색'), '목록이 열린 채입니다').toBeNull();
    expect(document.activeElement).toBe(trigger);
    expectContained();
  });

  // @scenario element=icon_select
  // @effects escape_inside_popup_closes_and_returns_focus
  it('아이콘 선택: 아이콘 항목에서 Esc 로도 닫는다', () => {
    inOuter(<IconSelect value="" onChange={() => {}} searchPlaceholder="아이콘 검색" />);
    const trigger = screen.getAllByRole('button')[0];
    fireEvent.click(trigger);
    const item = screen.getAllByRole('button').find((b) => b !== trigger)!;
    item.focus();

    fireEvent.keyDown(item, { key: 'Escape' });

    expect(screen.queryByPlaceholderText('아이콘 검색')).toBeNull();
    expect(document.activeElement).toBe(trigger);
    expectContained();
  });

  // @scenario element=notification_center
  // @effects escape_inside_popup_closes_and_returns_focus
  it('알림 판: 판 안에서 Esc 로 닫고 종 단추로 돌아간다', () => {
    const { container } = inOuter(<NotificationCenter titleText="알림" notifications={[]} />);
    const bell = screen.getByRole('button', { name: '알림' });
    fireEvent.click(bell);
    const panel = container.querySelector('.w-80') as HTMLElement;
    expect(panel).not.toBeNull();

    fireEvent.keyDown(panel, { key: 'Escape' });

    expect(container.querySelector('.w-80'), '알림 판이 열린 채입니다').toBeNull();
    expect(document.activeElement).toBe(bell);
    expect(bell.getAttribute('aria-haspopup')).toBeTruthy();
    expectContained();
  });

  // @scenario element=user_profile
  // @effects escape_inside_popup_closes_and_returns_focus
  it('내 정보 메뉴: 메뉴 항목에서 Esc 로 닫고 여는 단추로 돌아간다', () => {
    inOuter(<UserProfile user={{ name: '관리자', email: 'a@example.com' } as any} />);
    const trigger = screen.getAllByRole('button').find((b) => b.getAttribute('aria-haspopup') === 'menu')!;
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    const item = screen.getAllByRole('button').find((b) => b !== trigger)!;
    item.focus();

    fireEvent.keyDown(item, { key: 'Escape' });

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(trigger);
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

  // @scenario element=multilingual_add_menu
  // @effects escape_inside_popup_closes_and_returns_focus
  it('다국어 입력 언어 추가 메뉴: 항목에서 Esc 로 닫고 「언어 추가」 단추로 돌아간다', () => {
    inOuter(
      <MultilingualInput
        defaultLocale="ko"
        value={{ ko: '' }}
        onChange={() => {}}
        availableLocales={[
          { code: 'ko', name: '한국어', nativeName: '한국어' },
          { code: 'en', name: 'English', nativeName: 'English' },
        ]}
      />
    );
    const trigger = screen.getByText('언어 추가').closest('button') as HTMLElement;
    fireEvent.click(trigger);
    const item = screen.getByText('English').closest('button') as HTMLElement;
    item.focus();

    fireEvent.keyDown(item, { key: 'Escape' });

    expect(screen.queryByText('English'), '언어 추가 메뉴가 열린 채입니다').toBeNull();
    expect(document.activeElement).toBe(trigger);
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

  // @scenario element=dropdown_button
  // @effects escape_inside_popup_closes_and_returns_focus
  it('작업 메뉴 단추: 여는 단추가 펼침 상태를 알리고, 메뉴 안 Esc 로 닫고 돌아간다', () => {
    inOuter(<DropdownButton label="작업"><button type="button">복사</button></DropdownButton>);
    const trigger = screen.getByRole('button', { name: /작업/ });
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-haspopup')).toBe('menu');
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    const item = screen.getByText('복사');
    fireEvent.keyDown(item, { key: 'Escape' });
    expect(screen.queryByText('복사')).toBeNull();
    expect(document.activeElement).toBe(trigger);
    expectContained();
  });
});
