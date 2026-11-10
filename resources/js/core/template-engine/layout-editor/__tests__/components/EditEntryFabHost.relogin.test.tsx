/**
 * EditEntryFabHost — 로그아웃 뒤 다시 로그인하면 편집 권한 판정을 새로 받는다 (#869 독립 검토 σ)
 *
 * 로그인 응답에는 `can_edit_layouts` 가 없어 호스트가 로그인 사용자 조회로 판정을 한 번 받아 온다. 종전에는
 * 「물은 사용자」 기억과 받아 온 판정을 로그아웃 때 비우지 않아, 새로고침 없이 로그아웃 뒤 같은 계정으로 다시
 * 로그인하고 그사이 역할이 바뀌었으면 예전 판정으로 단추가 남았다.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, screen, act, cleanup } from '@testing-library/react';
import { EditEntryFabHost } from '../../components/EditEntryFabHost';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

type Listener = (...args: unknown[]) => void;

function fakeAuthManager() {
  let state: { isAuthenticated: boolean; user: Record<string, unknown> | null; type: 'admin' | 'user' | null } = {
    isAuthenticated: false,
    user: null,
    type: null,
  };
  const listeners = new Map<string, Listener[]>();
  return {
    getState: () => ({ ...state, user: state.user ? { ...state.user } : null }) as any,
    on: (event: string, fn: Listener) => {
      listeners.set(event, [...(listeners.get(event) ?? []), fn]);
      return () => listeners.set(event, (listeners.get(event) ?? []).filter((f) => f !== fn));
    },
    getConfig: (type: 'admin' | 'user') => ({ userEndpoint: type === 'admin' ? '/admin/auth/user' : '/auth/user' }) as any,
    set(next: typeof state) {
      state = next;
      for (const fn of listeners.get('authStateChange') ?? []) fn(state);
    },
  };
}

const flush = async (): Promise<void> => {
  await act(async () => {
    await new Promise((r) => setTimeout(r, 0));
  });
};

describe('EditEntryFabHost — 로그아웃 뒤 다시 로그인', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    localStorage.removeItem('auth_token');
  });

  // @scenario case=ops_screen_floating_fab_relogin_role_changed
  // @effects ops_screen_fab_refetches_ability_after_relogin
  it('같은 계정이 역할을 잃고 다시 로그인하면 판정을 새로 받아 단추를 숨긴다', async () => {
    localStorage.setItem('auth_token', 'test-token');
    const answers = [true, false];
    const calls: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        calls.push(String(input));
        const can = answers[Math.min(calls.length - 1, answers.length - 1)];
        return new Response(JSON.stringify({ success: true, data: { can_edit_layouts: can } }), { status: 200 });
      }),
    );
    const manager = fakeAuthManager();
    const engine = new TranslationEngine();
    render(
      <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
        <EditEntryFabHost templateIdentifier="sirsoft-admin_basic" authManager={manager} getRouter={() => null} />
      </TranslationProvider>,
    );

    // 첫 로그인(로그인 응답 — 판정 필드 없음) → 조회 1회, 참
    act(() => manager.set({ isAuthenticated: true, user: { uuid: 'u1', name: 'a', email: 'a@x' }, type: 'admin' }));
    await flush();
    expect(screen.queryByTestId('g7le-edit-entry-fab')).not.toBeNull();
    expect(calls).toHaveLength(1);

    // 로그아웃
    act(() => manager.set({ isAuthenticated: false, user: null, type: null }));
    await flush();
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();

    // 같은 계정으로 다시 로그인 — 그사이 역할을 잃었다
    act(() => manager.set({ isAuthenticated: true, user: { uuid: 'u1', name: 'a', email: 'a@x' }, type: 'admin' }));
    await flush();
    expect(calls, '다시 로그인하면 판정을 새로 받아야 한다').toHaveLength(2);
    expect(screen.queryByTestId('g7le-edit-entry-fab'), '예전 판정(참)으로 남으면 안 된다').toBeNull();
  });

  // @scenario case=ops_screen_floating_fab_logout_during_fetch
  // @effects ops_screen_fab_refetches_ability_after_relogin
  it('로그아웃 전에 나간 조회가 늦게 돌아와도 그 판정을 쓰지 않는다', async () => {
    localStorage.setItem('auth_token', 'test-token');
    let release: (v: Response) => void = () => {};
    const pending = new Promise<Response>((r) => {
      release = r;
    });
    const fetchMock = vi.fn().mockImplementationOnce(() => pending).mockImplementation(
      async () => new Response(JSON.stringify({ success: true, data: { can_edit_layouts: false } }), { status: 200 }),
    );
    vi.stubGlobal('fetch', fetchMock);
    const manager = fakeAuthManager();
    const engine = new TranslationEngine();
    render(
      <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
        <EditEntryFabHost templateIdentifier="sirsoft-admin_basic" authManager={manager} getRouter={() => null} />
      </TranslationProvider>,
    );
    act(() => manager.set({ isAuthenticated: true, user: { uuid: 'u1', name: 'a', email: 'a@x' }, type: 'admin' }));
    await flush();
    act(() => manager.set({ isAuthenticated: false, user: null, type: null }));
    act(() => manager.set({ isAuthenticated: true, user: { uuid: 'u1', name: 'a', email: 'a@x' }, type: 'admin' }));
    await flush();
    // 로그아웃 전 조회가 이제야 참으로 돌아온다
    await act(async () => {
      release(new Response(JSON.stringify({ success: true, data: { can_edit_layouts: true } }), { status: 200 }));
      await new Promise((r) => setTimeout(r, 0));
    });
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();
  });
});
