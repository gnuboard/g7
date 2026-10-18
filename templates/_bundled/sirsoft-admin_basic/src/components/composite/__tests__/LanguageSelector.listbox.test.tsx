/**
 * 관리자 헤더 언어 메뉴 — 단추가 알리는 팝업 종류(listbox)와 펼친 목록의 구조·키보드 동작이 맞는다.
 *
 * 7.1.0 전수 실측(O-PF-1, 결정 ⑬): 헤더 언어 단추는 `aria-haspopup="listbox"` 인데 펼친 항목은 역할 없는
 * 단추라 화면 읽기 프로그램에 목록 항목으로 읽히지 않았다. 목록은 `role="listbox"`(이름 = 언어), 항목은
 * `role="option"` + `aria-selected` 이고, 단추에서 ↓/↑ 로 열면 현재 언어 항목에 초점이 간다. 목록 안에서는
 * ↓/↑/Home/End 로 옮기고 Enter 로 고르며, Esc 는 목록을 닫고 단추로 초점을 돌린다.
 *
 * @scenario-manifest tests/scenarios/language-selector-listbox.yaml
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSelector } from '../LanguageSelector';

describe('LanguageSelector listbox 구조와 키보드', () => {
  const g7 = (window as any).G7Core;
  const originalApi = g7.api;
  const originalLocale = g7.locale;
  let apiMock: { patch: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    localStorage.setItem('g7_locale', 'ko');
    apiMock = { patch: vi.fn().mockResolvedValue({ success: true }) };
    g7.api = apiMock;
    g7.locale = { change: vi.fn().mockResolvedValue(undefined) };
  });

  afterEach(() => {
    vi.clearAllMocks();
    g7.api = originalApi;
    g7.locale = originalLocale;
    localStorage.removeItem('g7_locale');
  });

  // @scenario case=open_by_click
  // @effects listbox_named, options_have_role, selected_marked, trigger_controls_listbox
  it('펼친 목록은 이름 있는 listbox 이고 항목은 option, 현재 언어만 aria-selected=true 다', async () => {
    const user = userEvent.setup();
    render(<LanguageSelector availableLocales={['ko', 'en', 'ja']} languageText="언어" showCode />);
    const trigger = screen.getByRole('button', { name: '언어' });
    expect(trigger).toHaveAttribute('aria-haspopup', 'listbox');

    await user.click(trigger);

    const listbox = screen.getByRole('listbox', { name: '언어' });
    const options = screen.getAllByRole('option');
    expect(options).toHaveLength(3);
    expect(options.map((o) => o.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false']);
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(trigger.getAttribute('aria-controls')).toBe(listbox.id);
    expect(listbox.id).not.toBe('');
  });

  // @scenario case=open_by_arrow
  // @effects focus_on_selected_option
  it('단추에서 ↓ 를 누르면 목록이 열리고 현재 언어 항목에 초점이 간다', async () => {
    const user = userEvent.setup();
    localStorage.setItem('g7_locale', 'en');
    render(<LanguageSelector availableLocales={['ko', 'en', 'ja']} languageText="언어" showCode />);
    screen.getByRole('button', { name: '언어' }).focus();

    await user.keyboard('{ArrowDown}');

    expect(screen.getByRole('listbox')).toBeInTheDocument();
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /English/ }));
  });

  // @scenario case=arrow_and_enter
  // @effects arrow_moves_focus, enter_selects
  it('목록 안에서 ↓/↑/End/Home 으로 옮기고 Enter 로 고른다', async () => {
    const user = userEvent.setup();
    render(<LanguageSelector availableLocales={['ko', 'en', 'ja']} languageText="언어" showCode />);
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{Enter}');
    // Enter 로 열어도 현재 언어 항목에 초점이 간다
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /한국어/ }));

    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /English/ }));
    await user.keyboard('{End}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /日本語/ }));
    await user.keyboard('{Home}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /한국어/ }));
    await user.keyboard('{ArrowUp}');
    // 처음에서 ↑ 는 끝으로 돈다
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /日本語/ }));
    await user.keyboard('{ArrowUp}');
    await user.keyboard('{Enter}');

    expect(apiMock.patch).toHaveBeenCalledWith('/api/admin/users/me/language', { language: 'en' });
  });

  // @scenario case=escape
  // @effects escape_closes_and_returns_focus
  it('Esc 는 목록을 닫고 단추로 초점을 돌린다', async () => {
    const user = userEvent.setup();
    render(<LanguageSelector availableLocales={['ko', 'en']} languageText="언어" showCode />);
    const trigger = screen.getByRole('button', { name: '언어' });
    trigger.focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('listbox')).toBeInTheDocument();

    await user.keyboard('{Escape}');

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  // @scenario case=reselect_current
  // @effects reselect_closes_without_request
  it('현재 언어를 다시 고르면 요청 없이 목록을 닫고 단추로 초점을 돌린다', async () => {
    const user = userEvent.setup();
    render(<LanguageSelector availableLocales={['ko', 'en']} languageText="언어" showCode />);
    const trigger = screen.getByRole('button', { name: '언어' });
    trigger.focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{Enter}');

    expect(apiMock.patch).not.toHaveBeenCalled();
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
  });
});
