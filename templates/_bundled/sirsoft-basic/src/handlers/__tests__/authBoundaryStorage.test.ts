/**
 * 인증 경계에서의 비회원 저장값 정리 테스트 (#639 ⑦)
 *
 * 배경(브라우저 실측 2026-10-05): 로그아웃해도 `localStorage.g7_cart_key` 와 세션 저장소의 비회원
 * 주문 토큰이 그대로 남아 `_global.guestOrderToken` 에 다시 실렸고, 로그인한 회원의 요청에도
 * `X-Guest-Order-Token` 헤더가 섞여 나갔다. 로그인 전후로 카트키도 같은 값이었다.
 *
 * 처치(D15):
 * - 로그아웃(인증 → 비인증): 카트키와 비회원 주문 토큰 3키를 지우고 `_global.cartKey`·`_global.guestOrderToken` 을 비운다.
 * - 로그인(비인증 → 인증, 또는 다른 회원으로 교체): 서버 병합이 끝난 뒤이므로 카트키를 새로 발급해 저장한다.
 * - 같은 (인증 여부, uuid) 의 재발행은 무시한다. 두 번 설치해도 구독은 하나다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installAuthBoundaryStorageCleanup } from '../authBoundaryStorage';

type AuthListener = (state: { isAuthenticated: boolean; user: { uuid?: string } | null }) => void;

/**
 * AuthManager 흉내 — 코어처럼 실제 로그인은 `login` 뒤 `authStateChange`, 부팅 시 인증 복원(`checkAuth`)과 로그아웃
 * 전이는 `authStateChange` 만 낸다.
 */
function createAuthManager(initial: { isAuthenticated: boolean; user: { uuid?: string } | null }) {
    const listeners = new Set<AuthListener>();
    const loginListeners = new Set<AuthListener>();
    let state = initial;

    return {
        listeners,
        loginListeners,
        getState: () => ({ ...state, type: state.isAuthenticated ? 'user' : null }),
        on: vi.fn((event: string, handler: AuthListener) => {
            const target = event === 'authStateChange' ? listeners : event === 'login' ? loginListeners : null;
            target?.add(handler);
            return () => target?.delete(handler);
        }),
        emit(next: { isAuthenticated: boolean; user: { uuid?: string } | null }) {
            state = next;
            listeners.forEach((listener) => listener(next));
        },
        login(next: { isAuthenticated: boolean; user: { uuid?: string } | null }) {
            state = next;
            loginListeners.forEach((listener) => listener(next));
            listeners.forEach((listener) => listener(next));
        },
    };
}

describe('authBoundaryStorage (#639 ⑦)', () => {
    let auth: ReturnType<typeof createAuthManager>;
    let setState: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        localStorage.clear();
        sessionStorage.clear();
        setState = vi.fn();
        auth = createAuthManager({ isAuthenticated: true, user: { uuid: 'member-1' } });
        (window as any).G7Core = {
            AuthManager: { getInstance: () => auth },
            state: { set: setState, get: () => ({}) },
        };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ data: { cart_key: 'ck_new_after_login' } }),
        }));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete (window as any).G7Core;
    });

    // @scenario surface=auth_boundary, storage=guest_keys
    // @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
    it('로그아웃하면 카트키와 비회원 주문 토큰을 지우고 전역 값도 비운다', () => {
        localStorage.setItem('g7_cart_key', 'ck_old');
        sessionStorage.setItem('g7_guest_order_token', 'tok');
        sessionStorage.setItem('g7_guest_order_number', 'ORD-1');
        sessionStorage.setItem('g7_guest_order_expires_at', '2099-01-01T00:00:00Z');

        installAuthBoundaryStorageCleanup();
        auth.emit({ isAuthenticated: false, user: null });

        expect(localStorage.getItem('g7_cart_key')).toBeNull();
        expect(sessionStorage.getItem('g7_guest_order_token')).toBeNull();
        expect(sessionStorage.getItem('g7_guest_order_number')).toBeNull();
        expect(sessionStorage.getItem('g7_guest_order_expires_at')).toBeNull();
        expect(setState).toHaveBeenCalledWith({ cartKey: null, guestOrderToken: null });
    });

    // @scenario surface=auth_boundary, storage=guest_keys
    // @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
    it('로그인하면 카트키를 새로 발급해 저장한다', async () => {
        auth = createAuthManager({ isAuthenticated: false, user: null });
        (window as any).G7Core.AuthManager = { getInstance: () => auth };
        localStorage.setItem('g7_cart_key', 'ck_guest');

        installAuthBoundaryStorageCleanup();
        auth.login({ isAuthenticated: true, user: { uuid: 'member-1' } });

        await vi.waitFor(() => expect(localStorage.getItem('g7_cart_key')).toBe('ck_new_after_login'));
        expect(setState).toHaveBeenCalledWith({ cartKey: 'ck_new_after_login' });
    });

    // @scenario surface=auth_boundary, storage=guest_keys
    // @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
    it('로그인 폼을 거치지 않은 로그인(소셜 로그인 등)에서도 비회원 주문 토큰을 지운다', async () => {
        auth = createAuthManager({ isAuthenticated: false, user: null });
        (window as any).G7Core.AuthManager = { getInstance: () => auth };
        sessionStorage.setItem('g7_guest_order_token', 'tok');
        sessionStorage.setItem('g7_guest_order_number', 'ORD-1');
        sessionStorage.setItem('g7_guest_order_expires_at', '2099-01-01T00:00:00Z');

        installAuthBoundaryStorageCleanup();
        auth.login({ isAuthenticated: true, user: { uuid: 'member-1' } });

        expect(sessionStorage.getItem('g7_guest_order_token')).toBeNull();
        expect(sessionStorage.getItem('g7_guest_order_number')).toBeNull();
        expect(sessionStorage.getItem('g7_guest_order_expires_at')).toBeNull();
        expect(setState).toHaveBeenCalledWith({ guestOrderToken: null });
    });

    // @scenario surface=auth_boundary, storage=guest_keys
    // @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
    it('같은 회원 상태의 재발행은 무시한다', () => {
        localStorage.setItem('g7_cart_key', 'ck_keep');

        installAuthBoundaryStorageCleanup();
        auth.emit({ isAuthenticated: true, user: { uuid: 'member-1' } });

        expect(localStorage.getItem('g7_cart_key')).toBe('ck_keep');
        expect(fetch).not.toHaveBeenCalled();
    });

    // @scenario surface=auth_boundary, storage=guest_keys
    // @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
    it('두 번 설치해도 구독은 하나다', () => {
        installAuthBoundaryStorageCleanup();
        installAuthBoundaryStorageCleanup();

        expect(auth.listeners.size).toBe(1);
        expect(auth.loginListeners.size).toBe(1);
    });

    // @scenario surface=auth_boundary, storage=guest_keys
    // @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
    it('부팅 시 인증 복원(로그인 이벤트 없이 인증 상태만 바뀜)으로는 카트키를 다시 발급하지 않는다', async () => {
        // 템플릿 초기화는 window.load 에 돌아 부팅 인증 확인보다 먼저 설치될 수 있다 — 그 뒤 도착하는
        // 인증 확인 결과(비인증 → 인증)는 로그인이 아니라 새로고침이다(브라우저 실측: 새로고침마다 키가 바뀌었다).
        auth = createAuthManager({ isAuthenticated: false, user: null });
        (window as any).G7Core.AuthManager = { getInstance: () => auth };
        localStorage.setItem('g7_cart_key', 'ck_member_keep');

        installAuthBoundaryStorageCleanup();
        auth.emit({ isAuthenticated: true, user: { uuid: 'member-1' } });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetch).not.toHaveBeenCalled();
        expect(localStorage.getItem('g7_cart_key')).toBe('ck_member_keep');

        // 그 뒤 로그아웃 정리는 그대로 동작한다
        auth.emit({ isAuthenticated: false, user: null });
        expect(localStorage.getItem('g7_cart_key')).toBeNull();
    });
});
