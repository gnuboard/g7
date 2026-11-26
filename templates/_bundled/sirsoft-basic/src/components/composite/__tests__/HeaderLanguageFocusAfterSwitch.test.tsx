/**
 * 사용자 헤더 언어 메뉴 — 언어를 고른 뒤 다시 마운트돼도 포커스가 언어 단추로 돌아온다 (8차 5부 실측)
 *
 * 헤더는 고른 직후 포커스를 언어 단추로 돌리지만, 언어 전환이 엔진을 다시 띄워 같은 화면을 새로 마운트하면서
 * (`TemplateApp.changeLocale`) 포커스가 body 로 빠졌다. 전환 중 언마운트될 때 단추에 포커스가 있었으면 엔진 인계
 * 저장소(`G7Core.locale.handoff`)에 맡겼다가, 새 헤더가 마운트될 때 같은 단추로 돌린다. 다른 곳으로 옮겼으면 그대로 둔다.
 *
 * 조합 요약(마커 아님, 평문): case=switch_returns_focus|moved_away_keeps_focus|reopen_during_switch
 * 효과 요약(마커 아님, 평문): language_switch_returns_focus_to_trigger, language_switch_reopen_lands_in_options
 */
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Header from '../Header';

// 엔진 인계 저장소와 같은 규칙의 작은 저장소 — 전환 중에만 받고, 한 번 꺼내면 지운다
const store = { active: false, entries: new Map<string, unknown>() };
const handoff = {
  isSwitching: () => store.active,
  stash: (key: string, value: unknown) => (store.active ? (store.entries.set(key, value), true) : false),
  take: (key: string) => {
    const v = store.entries.get(key);
    store.entries.delete(key);
    return v;
  },
};

beforeEach(() => {
  store.active = false;
  store.entries.clear();
  (window as any).G7Core = {
    t: (key: string) => ({ 'common.language': '언어', 'user.menu': '메뉴' } as Record<string, string>)[key] ?? key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    // 엔진처럼 언어 전환을 시작한다 — 언마운트와 다시 마운트는 시험이 차례로 한다
    dispatch: vi.fn(() => {
      store.active = true;
    }),
    locale: { handoff },
    state: { getLocal: () => ({}), getGlobal: () => ({}), get: () => undefined },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

const ui = () => <Header siteName="S" availableLocales={['ko', 'en', 'ja']} currentLocale="ko" />;

describe('사용자 헤더 언어 전환 뒤 포커스', () => {
  // @scenario case=switch_returns_focus
  // @effects language_switch_returns_focus_to_trigger
  it('키보드로 고르면 다시 마운트된 뒤에도 언어 단추에 포커스가 있다', async () => {
    const user = userEvent.setup();
    const first = render(ui());
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{End}{Enter}');
    expect(document.activeElement).toBe(screen.getByRole('button', { name: '언어' }));

    first.unmount();
    store.active = false;
    await act(async () => {
      render(ui());
    });
    expect(document.activeElement, '다시 마운트된 뒤 포커스가 body 에 남았습니다').toBe(screen.getByRole('button', { name: '언어' }));
  });

  // @scenario case=moved_away_keeps_focus
  // @effects language_switch_returns_focus_to_trigger
  it('전환 중 다른 곳으로 옮겼으면 포커스를 빼앗지 않는다', async () => {
    const user = userEvent.setup();
    const outside = document.createElement('input');
    document.body.appendChild(outside);
    const first = render(ui());
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{End}{Enter}');
    outside.focus();

    first.unmount();
    store.active = false;
    await act(async () => {
      render(ui());
    });
    expect(document.activeElement).toBe(outside);
    outside.remove();
  });
});

describe('사용자 헤더 전환 중 다시 열기 (8차 7부)', () => {
  // @scenario case=reopen_during_switch
  // @effects language_switch_reopen_lands_in_options
  it('전환이 끝나기 전에는 언어 단추로 목록이 다시 열리지 않고, 다시 그린 뒤 열면 선택지로 들어간다', async () => {
    // 전환이 늦게 끝나는 실제 서버처럼 — 끝날 때까지 dispatch 약속이 풀리지 않는다
    let finish: () => void = () => {};
    (window as any).G7Core.dispatch = vi.fn(() => {
      store.active = true;
      return new Promise<void>((resolve) => { finish = resolve; });
    });
    const user = userEvent.setup();
    const first = render(ui());
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{End}{Enter}');
    expect(screen.queryByRole('listbox')).toBeNull();

    await user.keyboard('{Enter}');
    expect(screen.queryByRole('listbox'), '전환 중에 목록이 다시 열렸습니다').toBeNull();
    expect(screen.getByRole('button', { name: '언어' }).getAttribute('aria-busy')).toBe('true');

    first.unmount();
    store.active = false;
    await act(async () => {
      finish();
      render(<Header siteName="S" availableLocales={['ko', 'en', 'ja']} currentLocale="ja" />);
    });
    await user.keyboard('{Enter}');
    expect(document.activeElement, '다시 연 목록의 선택지로 포커스가 들어가지 않았습니다').toBe(screen.getByRole('option', { name: /日本語/ }));
  });
});
