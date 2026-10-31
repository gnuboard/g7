/**
 * Toast — 알림 하나는 만든 시각부터 정해진 시간만 보인다(화면 교체로 다시 마운트돼도 처음부터 다시 세지 않는다).
 *
 * 로그인 화면처럼 `_admin_base` 를 상속하지 않는 레이아웃에서 띄운 알림은 다음 화면으로 넘어갈 때 Toast 가 새로 마운트된다.
 * 새 Toast 가 같은 항목을 받자마자 자기 마운트 시각부터 표시 시간을 다시 세면, 옛 Toast 가 남긴 타이머가 원래 시각에 상태에서
 * 항목을 빼는 순간 숨김 동작 없이 갑자기 사라지고, 표시 시간이 이미 지난 항목이 늦게 그려진 화면에서 다시 나타날 수 있다.
 *
 * 이 시험은 `_global.toasts` 를 흉내 낸 작은 저장소(`G7Core.state.update` 를 받아 실제로 항목을 뺀다)로 Toast 를 그린다.
 *
 * 시나리오 매니페스트: `tests/scenarios/toast-lifetime-across-remount.yaml`
 */
import React, { useState } from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { Toast, ToastItem } from '../Toast';

const DURATION = 5000;
const HIDE_MS = 300;

type Screen = 'a' | 'b' | 'blank';

interface Harness {
  /** 지금 저장소의 항목 */
  toasts: () => ToastItem[];
  /** 화면 바꾸기 — 'a'·'b' 는 각자 다른 Toast 인스턴스(레이아웃 교체), 'blank' 는 Toast 없음 */
  show: (screen: Screen) => void;
}

/**
 * `_global.toasts` 저장소와 화면 교체를 흉내 내는 시험대를 그린다.
 *
 * @param initial 처음 항목
 * @param startScreen 처음 화면
 * @returns 시험대 조작자
 */
function mountHarness(initial: ToastItem[], startScreen: Screen = 'a'): Harness {
  let current = initial;
  let setScreenRef: (s: Screen) => void = () => {};
  let setToastsRef: (t: ToastItem[]) => void = () => {};

  const Host: React.FC = () => {
    const [toasts, setToasts] = useState<ToastItem[]>(initial);
    const [screenName, setScreen] = useState<Screen>(startScreen);
    setScreenRef = setScreen;
    setToastsRef = (next) => {
      current = next;
      setToasts(next);
    };

    if (screenName === 'blank') return <div data-testid="blank" />;

    return <Toast key={screenName} toasts={toasts} duration={DURATION} />;
  };

  (window as any).G7Core.state.update = vi.fn((updater: (prev: Record<string, any>) => Record<string, any>) => {
    const next = updater({ toasts: current });
    setToastsRef(next.toasts ?? []);
  });

  render(<Host />);

  return {
    toasts: () => current,
    show: (s: Screen) => act(() => setScreenRef(s)),
  };
}

/**
 * 문구로 알림 요소(role=alert)를 찾는다.
 *
 * @param message 문구
 * @returns 요소 또는 null
 */
function alertOf(message: string): HTMLElement | null {
  const text = screen.queryByText(message);

  return text ? (text.closest('[role="alert"]') as HTMLElement | null) : null;
}

/**
 * 시간을 흘린다.
 *
 * @param ms 흘릴 시간
 */
function advance(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms);
  });
}

describe('Toast 표시 시간 — 만든 시각 기준', () => {
  let originalStateUpdate: any;

  beforeEach(() => {
    vi.useFakeTimers();
    originalStateUpdate = (window as any).G7Core?.state?.update;
    (window as any).G7Core = (window as any).G7Core ?? {};
    (window as any).G7Core.state = (window as any).G7Core.state ?? {};
  });

  afterEach(() => {
    vi.useRealTimers();
    (window as any).G7Core.state.update = originalStateUpdate;
  });

  /**
   * @scenario component=toast, mount=late
   * @effects toast_shows_only_remaining_time_from_created_at
   */
  it('만든 지 2초 지난 항목을 처음 받은 Toast 는 남은 3초만 보이고 상태에서 지운다', () => {
    const message = '비밀번호를 변경했습니다';
    const h = mountHarness([{ id: 't1', type: 'success', message, createdAt: Date.now() - 2000 }]);

    expect(alertOf(message)?.className).toContain('opacity-100');

    advance(2999);
    expect(alertOf(message)?.className).toContain('opacity-100');

    advance(1);
    expect(alertOf(message)?.className).toContain('opacity-0');

    advance(HIDE_MS);
    expect(h.toasts()).toHaveLength(0);
    expect(screen.queryByText(message)).toBeNull();
  });

  /**
   * @scenario component=toast, mount=remount
   * @effects toast_keeps_lifetime_across_remount
   */
  it('레이아웃 교체로 Toast 가 다시 마운트돼도 처음 만든 시각에서 5초에 숨기 시작하고 5.3초에 지운다', () => {
    const message = '비밀번호를 변경했습니다';
    const h = mountHarness([{ id: 't2', type: 'success', message, createdAt: Date.now() }]);

    advance(1200);
    h.show('b');
    expect(alertOf(message)?.className).toContain('opacity-100');

    advance(3799); // 만든 뒤 4999ms
    expect(alertOf(message)?.className).toContain('opacity-100');

    advance(1); // 만든 뒤 5000ms — 다시 마운트된 Toast 도 숨김을 시작한다
    expect(alertOf(message)?.className).toContain('opacity-0');

    advance(HIDE_MS);
    expect(h.toasts()).toHaveLength(0);
    expect(screen.queryByText(message)).toBeNull();

    // 다시 마운트된 Toast 가 자기 마운트 시각부터 센 타이머가 남아 나중에 무엇을 하지 않는다
    const updates = ((window as any).G7Core.state.update as ReturnType<typeof vi.fn>).mock.calls.length;
    advance(DURATION);
    expect(screen.queryByText(message)).toBeNull();
    expect(((window as any).G7Core.state.update as ReturnType<typeof vi.fn>).mock.calls.length).toBe(updates);
  });

  /**
   * @scenario component=toast, mount=after_blank
   * @effects toast_keeps_lifetime_across_remount, toast_expired_item_never_reappears
   */
  it('Toast 가 없는 화면을 거쳐 표시 시간이 지난 뒤 그려진 화면은 같은 알림을 다시 그리지 않고 상태 항목을 지운다', () => {
    const message = '비밀번호를 변경했습니다';
    const h = mountHarness([{ id: 't3', type: 'success', message, createdAt: Date.now() }]);

    advance(2000);
    h.show('blank');
    expect(screen.queryByText(message)).toBeNull();

    // 떠난 Toast 는 타이머를 남기지 않는다 — 항목은 다음 Toast 가 정리한다
    advance(3500); // 만든 뒤 5500ms
    expect(h.toasts()).toHaveLength(1);

    h.show('b');
    expect(screen.queryByText(message)).toBeNull();
    expect(h.toasts()).toHaveLength(0);

    advance(DURATION + HIDE_MS);
    expect(screen.queryByText(message)).toBeNull();
  });

  /**
   * @scenario component=toast, mount=after_blank
   * @effects toast_keeps_lifetime_across_remount
   */
  it('빈 화면 뒤 표시 시간 안에 그려진 Toast 는 남은 시간만 보인다', () => {
    const message = '비밀번호를 변경했습니다';
    const h = mountHarness([{ id: 't4', type: 'success', message, createdAt: Date.now() }]);

    advance(2000);
    h.show('blank');
    advance(1000);
    h.show('b'); // 만든 뒤 3000ms
    expect(alertOf(message)?.className).toContain('opacity-100');

    advance(1999);
    expect(alertOf(message)?.className).toContain('opacity-100');
    advance(1);
    expect(alertOf(message)?.className).toContain('opacity-0');
    advance(HIDE_MS);
    expect(h.toasts()).toHaveLength(0);
  });

  /**
   * @scenario component=toast, mount=expired
   * @effects toast_expired_item_never_reappears
   */
  it('표시 시간이 이미 지난 항목은 새로 마운트된 Toast 가 그리지 않고 상태에서 지운다', () => {
    const message = '비밀번호를 변경했습니다';
    const h = mountHarness([
      { id: 't5', type: 'success', message, createdAt: Date.now() - (DURATION + 100) },
      { id: 't6', type: 'info', message: '아직 보일 알림', createdAt: Date.now() - 1000 },
    ]);

    expect(screen.queryByText(message)).toBeNull();
    expect(screen.getByText('아직 보일 알림')).toBeInTheDocument();
    expect(h.toasts().map((t) => t.id)).toEqual(['t6']);
  });

  /**
   * @scenario component=toast, mount=late
   * @effects toast_shows_only_remaining_time_from_created_at
   */
  it('개별 duration 이 있으면 만든 시각 + 그 시간으로 끝난다', () => {
    const message = '짧은 알림';
    const h = mountHarness([{ id: 't7', type: 'info', message, duration: 1000, createdAt: Date.now() - 400 }]);

    advance(599);
    expect(alertOf(message)?.className).toContain('opacity-100');
    advance(1);
    expect(alertOf(message)?.className).toContain('opacity-0');
    advance(HIDE_MS);
    expect(h.toasts()).toHaveLength(0);
  });

  /**
   * @scenario component=toast, mount=late
   * @effects toast_without_created_at_counts_from_mount
   */
  it('만든 시각이 없는 항목은 종전대로 마운트 시각부터 센다', () => {
    const message = '시각 없는 알림';
    const h = mountHarness([{ id: 't8', type: 'info', message }]);

    advance(DURATION - 1);
    expect(alertOf(message)?.className).toContain('opacity-100');
    advance(1);
    expect(alertOf(message)?.className).toContain('opacity-0');
    advance(HIDE_MS);
    expect(h.toasts()).toHaveLength(0);
  });

  /**
   * @scenario component=toast, mount=late
   * @effects toast_manual_close_removes_once
   */
  it('닫기 버튼으로 닫으면 표시 시간과 무관하게 바로 지우고, 남은 타이머가 다시 지우지 않는다', () => {
    const message = '닫을 알림';
    const h = mountHarness([{ id: 't9', type: 'info', message, createdAt: Date.now() }]);

    act(() => {
      screen.getByRole('button').click();
    });
    advance(HIDE_MS);
    expect(h.toasts()).toHaveLength(0);

    const updates = ((window as any).G7Core.state.update as ReturnType<typeof vi.fn>).mock.calls.length;
    advance(DURATION + HIDE_MS);
    expect(((window as any).G7Core.state.update as ReturnType<typeof vi.fn>).mock.calls.length).toBe(updates);
  });
});
