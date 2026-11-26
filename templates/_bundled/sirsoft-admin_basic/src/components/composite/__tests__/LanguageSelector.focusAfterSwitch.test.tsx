/**
 * LanguageSelector — 언어를 고른 뒤 포커스가 언어 단추로 돌아온다 (8차 5부 실측)
 *
 * 머리글 「언어」 메뉴에서 日本語를 고르면 포커스가 body 로 빠졌다. 두 단계에서 잃었다.
 * ① 고른 항목(목록)이 닫히며 사라진다. ② 언어 전환은 엔진을 다시 띄워 같은 화면을 새로 마운트한다
 * (`TemplateApp.changeLocale` 의 `destroyTemplate` 와 다시 그리기). 고르는 순간 여는 단추로 포커스를 옮기고,
 * 전환 중 언마운트될 때 단추에 포커스가 있었으면 엔진 인계 저장소(`G7Core.locale.handoff`)에 맡겼다가,
 * 새 인스턴스가 마운트될 때 같은 단추로 돌린다. 그 사이 사용자가 다른 곳으로 옮겼으면 건드리지 않는다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  beginLocaleSwitchHandoff,
  closeLocaleSwitchHandoff,
  clearLocaleSwitchHandoff,
  isLocaleSwitching,
  stashForLocaleSwitch,
  takeLocaleSwitchHandoff,
} from '@core/template-engine/localeSwitchHandoff';
import { LanguageSelector } from '../LanguageSelector';

describe('LanguageSelector 언어 전환 뒤 포커스', () => {
  const g7 = (window as any).G7Core;
  const originalApi = g7.api;
  const originalLocale = g7.locale;

  beforeEach(() => {
    localStorage.setItem('g7_locale', 'ko');
    g7.api = { patch: vi.fn().mockResolvedValue({ success: true }) };
    g7.locale = {
      // 엔진처럼 전환을 시작한다 — 언마운트와 다시 마운트는 시험이 차례로 한다
      change: vi.fn(async () => {
        beginLocaleSwitchHandoff();
      }),
      handoff: { isSwitching: isLocaleSwitching, stash: stashForLocaleSwitch, take: takeLocaleSwitchHandoff },
    };
  });

  afterEach(() => {
    clearLocaleSwitchHandoff();
    g7.api = originalApi;
    g7.locale = originalLocale;
    localStorage.removeItem('g7_locale');
  });

  const ui = () => <LanguageSelector id="language_selector_desktop" availableLocales={['ko', 'en', 'ja']} languageText="언어" showCode />;

  // @scenario case=switch_returns_focus
  // @effects language_switch_returns_focus_to_trigger
  it('키보드로 日本語를 고르면 단추로 포커스가 가고, 다시 마운트된 뒤에도 같은 단추에 남는다', async () => {
    const user = userEvent.setup();
    const first = render(ui());
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{End}{Enter}');

    expect(g7.locale.change).toHaveBeenCalled();
    expect(document.activeElement, '고른 직후 포커스가 단추에 없습니다').toBe(screen.getByRole('button', { name: '언어' }));

    // 엔진: 전환 중 언마운트 → 맡기기 닫기 → 같은 화면 새로 마운트
    first.unmount();
    closeLocaleSwitchHandoff();
    expect(document.activeElement).toBe(document.body);
    await act(async () => {
      render(ui());
    });

    expect(document.activeElement, '다시 마운트된 뒤 포커스가 body 에 남았습니다').toBe(screen.getByRole('button', { name: '언어' }));
  });

  // @scenario case=moved_away_keeps_focus
  // @effects language_switch_returns_focus_to_trigger
  it('전환 중 사용자가 다른 곳으로 옮겼으면 다시 마운트 뒤 포커스를 빼앗지 않는다', async () => {
    const user = userEvent.setup();
    const outside = document.createElement('input');
    document.body.appendChild(outside);
    const first = render(ui());
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{End}{Enter}');

    outside.focus();
    first.unmount();
    closeLocaleSwitchHandoff();
    await act(async () => {
      render(ui());
    });

    expect(document.activeElement).toBe(outside);
    outside.remove();
  });
});

describe('LanguageSelector 전환 중 다시 열기 (8차 7부)', () => {
  const g7 = (window as any).G7Core;
  const originalApi = g7.api;
  const originalLocale = g7.locale;
  let releasePatch: () => void = () => {};

  beforeEach(() => {
    localStorage.setItem('g7_locale', 'ko');
    // 언어 저장 요청이 늦게 끝나는 실제 서버처럼 — 끝날 때까지 전환이 진행 중이다
    g7.api = { patch: vi.fn(() => new Promise<void>((resolve) => { releasePatch = resolve; })) };
    g7.locale = {
      change: vi.fn(async () => {
        beginLocaleSwitchHandoff();
      }),
      handoff: { isSwitching: isLocaleSwitching, stash: stashForLocaleSwitch, take: takeLocaleSwitchHandoff },
    };
  });

  afterEach(() => {
    clearLocaleSwitchHandoff();
    g7.api = originalApi;
    g7.locale = originalLocale;
    localStorage.removeItem('g7_locale');
  });

  const ui = () => <LanguageSelector id="language_selector_desktop" availableLocales={['ko', 'en', 'ja']} languageText="언어" showCode />;

  // @scenario case=reopen_during_switch
  // @effects language_switch_reopen_lands_in_options
  it('고르면 목록이 바로 닫히고, 전환이 끝나기 전 단추로는 다시 열리지 않으며, 다시 그린 뒤 열면 선택지로 들어간다', async () => {
    const user = userEvent.setup();
    const first = render(ui());
    screen.getByRole('button', { name: '언어' }).focus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{ArrowDown}{Enter}');

    // 저장 요청이 아직 안 끝났어도 고른 즉시 닫힌다 — 종전에는 응답 뒤에야 닫혀, 그 사이 단추를 누르면 닫는 토글이 됐다
    expect(screen.queryByRole('listbox'), '고른 뒤에도 목록이 열려 있습니다').toBeNull();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: '언어' }));

    // 전환 중 다시 누르면 열지 않는다 — 곧 화면 전체가 다시 그려져 열린 목록과 그 안의 포커스가 사라진다
    await user.keyboard('{Enter}');
    expect(screen.queryByRole('listbox'), '전환 중에 목록이 다시 열렸습니다').toBeNull();
    expect(screen.getByRole('button', { name: '언어' }).getAttribute('aria-busy')).toBe('true');

    await act(async () => {
      releasePatch();
    });
    first.unmount();
    closeLocaleSwitchHandoff();
    localStorage.setItem('g7_locale', 'en');
    await act(async () => {
      render(ui());
    });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: '언어' }));

    await user.keyboard('{Enter}');
    expect(screen.getByRole('listbox')).toBeTruthy();
    expect(document.activeElement, '다시 연 목록의 선택지로 포커스가 들어가지 않았습니다').toBe(screen.getByRole('option', { name: /English/ }));
  });
});

describe('LanguageSelector 인라인 모양 언어 전환 뒤 포커스', () => {
  const g7 = (window as any).G7Core;
  const originalApi = g7.api;
  const originalLocale = g7.locale;

  beforeEach(() => {
    localStorage.setItem('g7_locale', 'ko');
    g7.api = { patch: vi.fn().mockResolvedValue({ success: true }) };
    g7.locale = {
      change: vi.fn(async () => {
        beginLocaleSwitchHandoff();
      }),
      handoff: { isSwitching: isLocaleSwitching, stash: stashForLocaleSwitch, take: takeLocaleSwitchHandoff },
    };
  });

  afterEach(() => {
    clearLocaleSwitchHandoff();
    g7.api = originalApi;
    g7.locale = originalLocale;
    localStorage.removeItem('g7_locale');
  });

  // @scenario case=switch_returns_focus
  // @effects language_switch_returns_focus_to_trigger
  it('칩을 고르면 여닫는 단추로 포커스가 가고, 다시 마운트된 뒤에도 그 단추에 남는다', async () => {
    const user = userEvent.setup();
    const ui = () => <LanguageSelector id="language_selector_inline" inline availableLocales={['ko', 'en', 'ja']} languageText="언어" />;
    const first = render(ui());
    const toggle = screen.getByRole('button', { name: /언어/ });
    await user.click(toggle);
    const ja = screen.getAllByRole('button').find((b) => b !== toggle && /日本語|ja/i.test(b.textContent ?? ''))!;
    ja.focus();
    await user.keyboard('{Enter}');

    expect(document.activeElement, '고른 직후 포커스가 여닫는 단추에 없습니다').toBe(screen.getByRole('button', { name: /언어/ }));
    first.unmount();
    closeLocaleSwitchHandoff();
    await act(async () => {
      render(ui());
    });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: /언어/ }));
  });
});
