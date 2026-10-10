/**
 * ActionDispatcher apiCall 인증 정보 첨부 대상 테스트 (KVE-2026-2350)
 *
 * 레이아웃은 apiCall 대상 주소를 표현식(`{{_local.u}}`·문자열 조립)으로 만들 수 있어 저장측 정적 규칙이
 * 외부 주소를 원리상 다 잡지 못한다. 그런데 handleApiCall 이 대상 출처와 무관하게 `Authorization: Bearer`
 * 를 붙였으므로, 레이아웃 편집 권한만 있는 계정이 관리자 브라우저의 토큰을 외부 호스트로 보내게 할 수 있었다
 * (권한전이 → 설치 API). 인증 정보(Bearer·X-XSRF-TOKEN)는 동일 출처 대상에만 싣는다 — ApiClient 와 같은 원칙.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';
import { isSameOriginRequestUrl } from '../../support/navPolicy';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';

const mockGetToken = vi.fn(() => 'test-token');

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({
        getToken: mockGetToken,
    })),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({
            login: vi.fn(),
            logout: vi.fn(),
        })),
    },
}));

describe('ActionDispatcher - apiCall 인증 정보는 동일 출처에만 (engine-v1.73.0)', () => {
    let dispatcher: ActionDispatcher;
    let requests: Array<{ url: string; headers: Record<string, string> }>;
    let originalFetch: typeof fetch;

    beforeEach(() => {
        dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        Logger.getInstance().setDebug(false);
        requests = [];
        originalFetch = globalThis.fetch;
        globalThis.fetch = vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
            requests.push({ url: String(url), headers: (init?.headers ?? {}) as Record<string, string> });
            return { ok: true, status: 200, json: async () => ({ data: {} }) } as Response;
        }) as unknown as typeof fetch;
        document.cookie = 'XSRF-TOKEN=xsrf-value; path=/';
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });

    const run = (target: string, local: Record<string, unknown> = {}) =>
        (dispatcher as any).executeAction(
            { handler: 'apiCall', target, auth_required: true, params: { method: 'GET' } },
            { data: { _local: local }, state: {}, setState: vi.fn() }
        );

    /**
     * @effects api_call_credentials_same_origin_only, sandbox_escape_blocked
     */
    it('표현식으로 만든 교차 출처 대상에는 Bearer·XSRF 헤더를 싣지 않는다', async () => {
        const offsite = [
            'https://evil.example/steal',
            '//evil.example/steal',
            '/\\evil.example/steal',
            ' https://evil.example/steal',
        ];

        for (const u of offsite) {
            await run('{{_local.u}}', { u });
        }

        expect(requests).toHaveLength(offsite.length);
        for (const request of requests) {
            expect(request.headers.Authorization).toBeUndefined();
            expect(request.headers['X-XSRF-TOKEN']).toBeUndefined();
        }
    });

    /**
     * @effects same_expression_same_value_across_paths
     */
    it('동일 출처 대상은 종전대로 Bearer·XSRF 헤더를 싣는다 (정상 회귀)', async () => {
        await run('/api/me');
        await run('{{_local.u}}', { u: `${window.location.origin}/api/admin/users` });
        await run('api/relative');

        expect(requests).toHaveLength(3);
        for (const request of requests) {
            expect(request.headers.Authorization).toBe('Bearer test-token');
            expect(request.headers['X-XSRF-TOKEN']).toBe('xsrf-value');
        }
    });

    /**
     * 교차 출처 응답의 428 은 본인인증 launcher 로 넘기지 않는다. launcher 는 응답이 지정한
     * `challenge_start_url` 로 토큰을 실어 보내므로, 외부 서버가 428 을 흉내 내면 이 요청에서 뺀 토큰을
     * 후속 요청으로 받아 갈 수 있었다. 동일 출처 428 은 종전대로 넘긴다(정상 쌍).
     *
     * @effects cross_origin_identity_response_not_trusted, sandbox_escape_blocked
     */
    it('교차 출처 대상의 428 응답은 본인인증 흐름을 시작하지 않고, 동일 출처 428 은 시작한다', async () => {
        const launched: Array<Record<string, unknown>> = [];
        IdentityGuardInterceptor.setLauncher(async (payload) => {
            launched.push(payload as unknown as Record<string, unknown>);
            return { status: 'cancelled' };
        });
        globalThis.fetch = vi.fn(async () => ({
            ok: false,
            status: 428,
            json: async () => ({
                success: false,
                error_code: 'identity_verification_required',
                message: '',
                verification: {
                    policy_key: 'p',
                    purpose: 'sensitive_action',
                    challenge_start_url: 'https://evil.example/steal',
                    return_request: null,
                },
            }),
        })) as unknown as typeof fetch;

        try {
            for (const u of ['https://evil.example/428', '//evil.example/428', '/\\evil.example/428']) {
                await run('{{_local.u}}', { u }).catch(() => undefined);
            }
            expect(launched).toHaveLength(0);

            await run('/api/admin/action').catch(() => undefined);
            expect(launched).toHaveLength(1);
        } finally {
            IdentityGuardInterceptor.reset();
        }
    });

    /**
     * @effects api_call_credentials_same_origin_only
     */
    it('isSameOriginRequestUrl 은 fetch 와 같은 URL 파서로 출처를 판정한다', () => {
        expect(isSameOriginRequestUrl('/api/me')).toBe(true);
        expect(isSameOriginRequestUrl('api/me?x=//a')).toBe(true);
        expect(isSameOriginRequestUrl(`${window.location.origin}/a`)).toBe(true);
        expect(isSameOriginRequestUrl('https://evil.example/a')).toBe(false);
        expect(isSameOriginRequestUrl('//evil.example/a')).toBe(false);
        expect(isSameOriginRequestUrl('/\\evil.example/a')).toBe(false);
        expect(isSameOriginRequestUrl('/\t/evil.example/a')).toBe(false);
        expect(isSameOriginRequestUrl('javascript:alert(1)')).toBe(false);
        expect(isSameOriginRequestUrl(undefined)).toBe(false);
    });
});
