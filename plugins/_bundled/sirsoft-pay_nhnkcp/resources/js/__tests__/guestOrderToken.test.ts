/**
 * 비회원 주문 조회 토큰 — 공개 상태 계약으로만 읽는다 (NHN KCP)
 *
 * 결제창 닫힘 보고·재결제 준비는 비회원 주문이면 조회 토큰(X-Guest-Order-Token)으로 소유를 증명한다. 예전에는
 * 세션 저장소 키와 로그인 토큰(localStorage)을 플러그인이 직접 읽었다 — 저장 방식이 바뀌면 조용히 깨진다.
 * 이제 공개 상태 계약 `_global.guestOrderToken` 만 읽는다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/payment-close-report-ownership.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getGuestOrderToken, guestOwnershipHeaders, waitForGuestOrderToken } from '../guestOrderToken';

type Listener = (state: Record<string, unknown>) => void;

let globalState: Record<string, unknown>;
let listeners: Listener[];

/**
 * 공개 상태 API 만 가진 코어를 꾸민다.
 */
function installCore(): void {
    (window as any).G7Core = {
        state: {
            get: () => globalState,
            subscribe: (listener: Listener) => {
                listeners.push(listener);

                return () => {
                    listeners = listeners.filter((l) => l !== listener);
                };
            },
        },
    };
}

/**
 * 전역 상태를 바꾸고 구독자에게 알린다.
 *
 * @param patch 바꿀 값
 */
function setGlobal(patch: Record<string, unknown>): void {
    globalState = { ...globalState, ...patch };
    listeners.forEach((l) => l(globalState));
}

beforeEach(() => {
    globalState = {};
    listeners = [];
    sessionStorage.clear();
    localStorage.clear();
    installCore();
});

afterEach(() => {
    delete (window as any).G7Core;
    vi.useRealTimers();
});

describe('getGuestOrderToken / guestOwnershipHeaders', () => {
    // @scenario case=token_public_state
    // @effects guest_token_from_public_state_only
    it('_global.guestOrderToken 을 X-Guest-Order-Token 헤더로 만든다', () => {
        setGlobal({ guestOrderToken: 'guest-token' });

        expect(getGuestOrderToken()).toBe('guest-token');
        expect(guestOwnershipHeaders()).toEqual({ 'X-Guest-Order-Token': 'guest-token' });
    });

    // @scenario case=token_storage_only
    // @effects guest_token_from_public_state_only
    it('세션 저장소에만 있고 공개 상태에 없으면 읽지 않는다 (저장소 키를 직접 보지 않는다)', () => {
        sessionStorage.setItem('g7_guest_order_token', 'storage-token');
        localStorage.setItem('auth_token', 'member-token');

        expect(getGuestOrderToken()).toBeNull();
        expect(guestOwnershipHeaders()).toEqual({});
    });

    // @scenario case=token_absent
    // @effects no_header_without_token
    it('토큰이 없거나 빈 값이면 헤더를 싣지 않는다', () => {
        expect(guestOwnershipHeaders()).toEqual({});
        setGlobal({ guestOrderToken: '' });
        expect(guestOwnershipHeaders()).toEqual({});
        delete (window as any).G7Core;
        expect(guestOwnershipHeaders()).toEqual({});
    });
});

describe('waitForGuestOrderToken', () => {
    // @scenario case=token_wait
    // @effects waits_until_template_restores_token
    it('템플릿이 토큰을 되살리면 그때 끝나고 구독을 푼다', async () => {
        vi.useFakeTimers();
        let done = false;
        const waiting = waitForGuestOrderToken(3000).then(() => { done = true; });

        await vi.advanceTimersByTimeAsync(500);
        expect(done).toBe(false);

        setGlobal({ guestOrderToken: 'late-token' });
        await waiting;

        expect(done).toBe(true);
        expect(listeners).toHaveLength(0);
    });

    // @scenario case=token_wait
    // @effects waits_until_template_restores_token
    it('토큰이 끝내 생기지 않으면(회원) 제한 시간 뒤에 끝난다', async () => {
        vi.useFakeTimers();
        let done = false;
        void waitForGuestOrderToken(3000).then(() => { done = true; });

        await vi.advanceTimersByTimeAsync(2999);
        expect(done).toBe(false);
        await vi.advanceTimersByTimeAsync(1);

        expect(done).toBe(true);
        expect(listeners).toHaveLength(0);
    });

    // @scenario case=token_wait
    // @effects waits_until_template_restores_token
    it('토큰이 이미 있으면 기다리지 않는다', async () => {
        setGlobal({ guestOrderToken: 'ready' });

        await expect(waitForGuestOrderToken(3000)).resolves.toBeUndefined();
        expect(listeners).toHaveLength(0);
    });
});
