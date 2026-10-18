/**
 * 사용자 헤더(데스크톱) 언어 메뉴의 listbox 구조·키보드와 장바구니 단추 접근 이름.
 *
 * 7.1.0 전수 실측(O-PF-1·O-C1-3, 결정 ⑬): 관리자 언어 메뉴를 listbox 패턴으로 맞추면서 사용자 헤더도 같은
 * 계약을 둔다 — 목록은 이름 있는 `role="listbox"`(단추가 aria-controls 로 가리킴), 항목은 `role="option"` +
 * `aria-selected`, 단추에서 ↓/↑·Enter 로 열면 현재 언어 항목에 초점, 목록 안 ↓/↑/Home/End 이동, Esc 는 닫고
 * 단추로 초점. 아이콘만 있던 장바구니 단추는 담긴 수를 함께 말하는 이름을 갖는다.
 *
 * @scenario-manifest tests/scenarios/language-selector-listbox.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Header from '../Header';

let dispatch: ReturnType<typeof vi.fn>;

beforeEach(() => {
  dispatch = vi.fn();
  (window as any).G7Core = {
    t: (key: string, params?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'common.language': '언어',
        'nav.cart_with_count': '장바구니 (담긴 상품 {{count}}개)',
        'user.menu': '메뉴',
      };
      const text = map[key] ?? key;
      return params ? text.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? '')) : text;
    },
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch,
    state: { getLocal: () => ({}), getGlobal: () => ({}), get: () => undefined },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

describe('사용자 헤더 언어 메뉴 listbox', () => {
  // @scenario case=open_by_click
  // @effects listbox_named, options_have_role, selected_marked, trigger_controls_listbox
  it('펼친 목록은 이름 있는 listbox 이고 단추가 aria-controls 로 가리킨다', async () => {
    const user = userEvent.setup();
    render(<Header siteName="S" availableLocales={['ko', 'en', 'ja']} currentLocale="ko" />);
    const trigger = screen.getByRole('button', { name: '언어' });
    await user.click(trigger);

    const listbox = screen.getByRole('listbox', { name: '언어' });
    expect(trigger.getAttribute('aria-controls')).toBe(listbox.id);
    expect(listbox.id).not.toBe('');
    expect(screen.getAllByRole('option').map((o) => o.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false']);
  });

  // @scenario case=open_by_arrow
  // @effects focus_on_selected_option
  it('단추에서 ↓ 를 누르면 목록이 열리고 현재 언어 항목에 초점이 간다', async () => {
    const user = userEvent.setup();
    render(<Header siteName="S" availableLocales={['ko', 'en', 'ja']} currentLocale="en" />);
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /English/ }));
  });

  // @scenario case=arrow_and_enter
  // @effects arrow_moves_focus, enter_selects
  it('목록 안에서 ↓/End/Home 으로 옮기고 Enter 로 고르면 setLocale 을 부른다', async () => {
    const user = userEvent.setup();
    render(<Header siteName="S" availableLocales={['ko', 'en', 'ja']} currentLocale="ko" />);
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{Enter}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /한국어/ }));
    await user.keyboard('{End}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /日本語/ }));
    await user.keyboard('{Home}');
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(screen.getByRole('option', { name: /English/ }));
    await user.keyboard('{Enter}');

    expect(dispatch).toHaveBeenCalledWith({ handler: 'setLocale', target: 'en' });
  });

  // @scenario case=escape
  // @effects escape_closes_and_returns_focus
  it('Esc 는 목록을 닫고 단추로 초점을 돌린다', async () => {
    const user = userEvent.setup();
    render(<Header siteName="S" availableLocales={['ko', 'en']} currentLocale="ko" />);
    const trigger = screen.getByRole('button', { name: '언어' });
    trigger.focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
  });
});

describe('사용자 헤더 아이콘 단추 이름', () => {
  // @scenario case=cart_name
  // @effects cart_named_with_count
  it('아이콘만 있는 장바구니 단추가 담긴 수를 함께 말하는 이름을 갖는다', () => {
    render(<Header siteName="S" availableLocales={['ko']} currentLocale="ko" cartCount={3} />);
    expect(screen.getByRole('button', { name: '장바구니 (담긴 상품 3개)' })).toBeInTheDocument();
  });

  // @scenario case=mobile_menu_name
  // @effects mobile_menu_named
  it('좁은 폭에서 그리는 메뉴 단추도 이름을 갖는다', () => {
    (window as any).G7Core.useResponsive = () => ({ width: 390, breakpoint: 'mobile' });
    render(<Header siteName="S" availableLocales={['ko']} currentLocale="ko" onMobileMenuOpen={() => {}} />);
    expect(screen.getByRole('button', { name: '메뉴' })).toBeInTheDocument();
  });
});
