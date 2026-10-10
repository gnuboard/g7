/**
 * AdminSidebar 전역 상태 구독 수명 테스트
 *
 * 사이드바는 `G7Core.state.subscribe` 로 현재 경로·로케일을 따라간다. 코어는 구독 해제 함수를 돌려주며,
 * 사이드바는 마운트 동안 구독을 하나만 유지하고(리렌더마다 새로 구독하지 않는다) 언마운트 때 해제해야 한다.
 * 구독이 남으면 화면을 떠난 뒤에도 전역 상태가 바뀔 때마다 해제된 컴포넌트의 setState 가 불린다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { AdminSidebar, MenuItem } from '../AdminSidebar';

const menu: MenuItem[] = [
  { id: 1, name: { ko: '대시보드', en: 'Dashboard' }, slug: 'dashboard', url: '/admin', icon: 'fas fa-home', is_active: true },
  { id: 2, name: { ko: '설정', en: 'Settings' }, slug: 'settings', url: '/admin/settings', icon: 'fas fa-cog' },
];

/** 구독자 수를 셀 수 있는 G7Core.state 대역. */
const makeState = () => {
  const listeners = new Set<(state: any) => void>();
  return {
    listeners,
    emit: (state: any) => listeners.forEach((listener) => listener(state)),
    state: {
      get: vi.fn(() => ({})),
      set: vi.fn(),
      subscribe: vi.fn((listener: (state: any) => void) => {
        listeners.add(listener);
        return () => {
          listeners.delete(listener);
        };
      }),
    },
  };
};

describe('AdminSidebar — 전역 상태 구독 수명', () => {
  let originalG7Core: any;
  let store: ReturnType<typeof makeState>;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    store = makeState();
    (window as any).G7Core = {
      ...originalG7Core,
      dispatch: vi.fn(),
      locale: { current: vi.fn(() => 'ko'), supported: vi.fn(() => ['ko', 'en']), change: vi.fn() },
      state: store.state,
    };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  // @scenario surface=admin_sidebar_subscription
  // @effects state_subscription_released
  it('리렌더해도 구독은 하나로 유지되고 언마운트하면 구독자 수가 0 으로 돌아간다', () => {
    const { rerender, unmount } = render(<AdminSidebar menu={menu} />);
    expect(store.listeners.size).toBe(1);

    rerender(<AdminSidebar menu={menu} currentLocale="en" />);
    rerender(<AdminSidebar menu={[...menu]} />);
    expect(store.state.subscribe).toHaveBeenCalledTimes(1);
    expect(store.listeners.size).toBe(1);

    unmount();
    expect(store.listeners.size).toBe(0);
  });

  // @scenario surface=admin_sidebar_subscription
  // @effects state_subscription_released
  it('다시 마운트하면 새로 구독하고, 해제된 구독에는 상태 변경이 닿지 않는다', () => {
    const first = render(<AdminSidebar menu={menu} />);
    first.unmount();
    expect(store.listeners.size).toBe(0);

    render(<AdminSidebar menu={menu} />);
    expect(store.state.subscribe).toHaveBeenCalledTimes(2);
    expect(store.listeners.size).toBe(1);

    // 살아 있는 구독만 경로 변경을 받는다 — 설정 메뉴가 활성 표시된다
    act(() => store.emit({ _global: { currentPath: '/admin/settings' } }));
    expect(screen.getByText('설정').closest('.admin-sidebar-item-active')).not.toBeNull();
  });

  // @scenario surface=admin_sidebar_subscription
  // @effects state_subscription_released
  it('subscribe 가 해제 함수를 돌려주지 않는 구 코어에서도 언마운트가 오류 없이 끝난다', () => {
    store.state.subscribe.mockImplementation(() => undefined as any);

    const { unmount } = render(<AdminSidebar menu={menu} />);

    expect(() => unmount()).not.toThrow();
  });
});
