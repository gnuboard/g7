/**
 * AuthManager.getRedirectUrl 복귀 경로 정책 테스트 (KVE-2026-2348)
 *
 * 종전 가드 `decoded.startsWith('/')` 는 `//evil.com`·`/\evil.com` 을 통과시켜 로그인 후
 * 오프사이트로 내보냈다. sink 와 같은 판정(navPolicy)을 써야 두 경로의 차집합이 생기지 않는다.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({
        getToken: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        setToken: vi.fn(),
        removeToken: vi.fn(),
    })),
}));

import { AuthManager } from '../AuthManager';

describe('AuthManager.getRedirectUrl — 복귀 경로 정책', () => {
    let authManager: AuthManager;

    beforeEach(() => {
        AuthManager.resetInstance();
        authManager = AuthManager.getInstance();
    });

    const withSearch = (search: string) => {
        Object.defineProperty(window, 'location', {
            value: { search },
            writable: true,
            configurable: true,
        });
    };

    /**
     * @effects nav_sink_rejects_offsite_target, nav_sink_rejects_script_scheme
     */
    it('protocol-relative·백슬래시·탭 우회·javascript: 는 기본 경로를 반환한다', () => {
        for (const raw of ['%2F%2Fevil.com', '%2F%5Cevil.com', '%2F%09%2Fevil.com', 'javascript%3Aalert(1)']) {
            withSearch(`?redirect=${raw}`);
            expect(authManager.getRedirectUrl('admin')).toBe('/admin');
        }
    });

    /**
     * @effects same_origin_redirect_preserved
     */
    it('same-origin 경로는 쿼리를 보존해 반환한다', () => {
        withSearch('?redirect=%2Fadmin%2Forders%3Fstatus%3Dpaid');
        expect(authManager.getRedirectUrl('admin')).toBe('/admin/orders?status=paid');
    });
});
