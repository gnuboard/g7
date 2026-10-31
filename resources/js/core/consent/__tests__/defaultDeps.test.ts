/**
 * 동의 런타임 기본 의존성 — 비인증 조회는 토큰을 싣지 않는다 (#639)
 *
 * 서버에서 폐기된 토큰이 브라우저에 남은 방문자는 preloadAuth 가 실패해도 토큰이 지워지지 않는다. 그 상태로
 * 코어 ApiClient 를 쓰면 Bearer 가 실려 `/api/consent` 가 401 을 내고, ApiClient 의 onUnauthorized 가 공개
 * 페이지에서 로그인 화면으로 보낸다. 비인증이면 토큰 없는 요청으로 게스트 기준 답을 받아야 한다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { apiGet, authState } = vi.hoisted(() => ({
    apiGet: vi.fn(async () => ({ success: true, data: { contract_version: 1, status: 'none', categories: {}, needs_renewal: false, policy_version: null } })),
    authState: { authenticated: false },
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ get: apiGet })),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({
            on: vi.fn(() => () => {}),
            getState: vi.fn(() => ({ isAuthenticated: authState.authenticated })),
            isAuthenticated: vi.fn(() => authState.authenticated),
        })),
    },
}));

import { defaultConsentRuntimeDeps } from '..';

describe('defaultConsentRuntimeDeps.fetchSnapshot', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet.mockClear();
        fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ success: true, data: { status: 'none' } }) }));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    // @scenario surface=runtime, change=boot, response=ok
    // @effects runtime_guest_lookup_sends_no_token
    it('비인증이면 ApiClient 를 쓰지 않고 토큰 없이 부른다', async () => {
        authState.authenticated = false;

        await defaultConsentRuntimeDeps(() => {}).fetchSnapshot();

        expect(apiGet).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/consent');
        expect((init as RequestInit).headers).not.toHaveProperty('Authorization');
    });

    // @scenario surface=runtime, change=login, response=ok
    // @effects runtime_guest_lookup_sends_no_token
    it('인증 상태면 코어 ApiClient 로 부른다 (Bearer·토큰 갱신 일원화)', async () => {
        authState.authenticated = true;

        await defaultConsentRuntimeDeps(() => {}).fetchSnapshot();

        expect(apiGet).toHaveBeenCalledWith('/consent');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    // @scenario surface=runtime, change=boot, response=error
    // @effects runtime_fails_closed_on_bad_response
    it('비인증 조회가 !ok 면 예외로 알려 런타임이 unknown 으로 둔다', async () => {
        authState.authenticated = false;
        fetchMock.mockResolvedValueOnce({ ok: false, status: 500, json: async () => ({}) });

        await expect(defaultConsentRuntimeDeps(() => {}).fetchSnapshot()).rejects.toThrow('500');
    });
});
