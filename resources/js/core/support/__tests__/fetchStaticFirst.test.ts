/**
 * 정적 우선 fetch 폴백 테스트 (#122 S3).
 *
 * 정적 게시본 miss(404/네트워크 실패)가 legacy API 폴백으로 즉시 수렴하고,
 * 폴백이 warn 로그로 관측 가능함을 잠근다 (조용한 폴백 금지 — 자가 치유
 * 실패를 발견할 유일한 통로).
 *
 * @effects static_first_fetch_falls_back_to_api_on_miss, fallback_is_observable_via_console_warn
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fetchStaticFirst } from '../fetchStaticFirst';
import { extStaticUrl, extStaticVersion, staticToLegacy } from '../assetUrl';

function ok(body: unknown = {}): Response {
    return {
        ok: true,
        status: 200,
        json: async () => body,
    } as unknown as Response;
}

function notFound(): Response {
    return { ok: false, status: 404 } as unknown as Response;
}

describe('fetchStaticFirst (#122)', () => {
    let fetchMock: ReturnType<typeof vi.fn>;
    let warnSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        delete (globalThis as any).G7Config;
    });

    it('정적 200 이면 legacy 를 호출하지 않는다', async () => {
        fetchMock.mockResolvedValue(ok({ from: 'static' }));

        const response = await fetchStaticFirst('/build/ext/7/templates/t/routes.json', '/api/templates/t/routes.json');

        expect(response.ok).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('/build/ext/7/');
    });

    it('정적 404 이면 legacy 로 1회 폴백하고 warn 을 남긴다', async () => {
        fetchMock.mockImplementation((url: string) =>
            Promise.resolve(String(url).includes('/build/ext/') ? notFound() : ok({ from: 'legacy' }))
        );

        const response = await fetchStaticFirst('/build/ext/7/templates/t/routes.json', '/api/templates/t/routes.json');

        expect(response.ok).toBe(true);
        const urls = fetchMock.mock.calls.map((c: any[]) => String(c[0]));
        expect(urls).toHaveLength(2);
        expect(urls[1]).toContain('/api/templates/t/routes.json');
        expect(warnSpy).toHaveBeenCalled();
    });

    it('정적 네트워크 실패면 legacy 로 폴백한다', async () => {
        fetchMock.mockImplementation((url: string) =>
            String(url).includes('/build/ext/')
                ? Promise.reject(new TypeError('Failed to fetch'))
                : Promise.resolve(ok({ from: 'legacy' }))
        );

        const response = await fetchStaticFirst('/build/ext/7/templates/t/routes.json', '/api/templates/t/routes.json');

        expect(response.ok).toBe(true);
        expect(warnSpy).toHaveBeenCalled();
    });

    it('staticUrl 이 null(staticBase 미주입)이면 legacy 로 직행한다', async () => {
        fetchMock.mockResolvedValue(ok());

        await fetchStaticFirst(null, '/api/templates/t/routes.json');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('/api/');
    });

    it('legacy 응답은 4xx/5xx 라도 그대로 반환한다 (호출부 분기 보존)', async () => {
        fetchMock.mockImplementation((url: string) =>
            Promise.resolve(String(url).includes('/build/ext/') ? notFound() : ({ ok: false, status: 500 } as unknown as Response))
        );

        const response = await fetchStaticFirst('/build/ext/7/x.json', '/api/x.json');

        expect(response.status).toBe(500);
    });
});

describe('extStaticUrl / extStaticVersion (#122)', () => {
    afterEach(() => {
        delete (globalThis as any).G7Config;
    });

    it('staticBase 미주입 시 null', () => {
        delete (globalThis as any).G7Config;
        expect(extStaticUrl('templates/t/routes.json')).toBeNull();
        expect(extStaticVersion()).toBeNull();
    });

    it('staticBase 기반 URL 조합 + 버전 추출', () => {
        (globalThis as any).G7Config = { staticBase: '/build/ext/1234' };

        expect(extStaticUrl('templates/t/routes.json')).toBe('/build/ext/1234/templates/t/routes.json');
        expect(extStaticVersion()).toBe(1234);
    });

    it('forVersion 이 페이지 버전과 다르면 그 버전 디렉토리를 조합한다 (핸드셰이크 재로드)', () => {
        (globalThis as any).G7Config = { staticBase: '/build/ext/1234' };

        expect(extStaticUrl('templates/t/routes.json', 5678)).toBe('/build/ext/5678/templates/t/routes.json');
        expect(extStaticUrl('templates/t/routes.json', 1234)).toBe('/build/ext/1234/templates/t/routes.json');
    });
});

describe('staticToLegacy (#122 F15 — 역변환 규칙)', () => {
    it('템플릿 dist 에셋 → 종전 자산 API', () => {
        expect(staticToLegacy('/build/ext/1234/templates/sirsoft-basic/assets/css/components.css')).toBe(
            '/api/templates/assets/sirsoft-basic/css/components.css?v=1234'
        );
        expect(staticToLegacy('/build/ext/1234/templates/sirsoft-basic/assets/js/components.iife.js')).toBe(
            '/api/templates/assets/sirsoft-basic/js/components.iife.js?v=1234'
        );
    });

    it('병합 번들 → 종전 번들 API', () => {
        expect(staticToLegacy('/build/ext/1234/bundles/modules.js')).toBe('/api/modules/bundle.js?v=1234');
        expect(staticToLegacy('/build/ext/1234/bundles/plugins.css')).toBe('/api/plugins/bundle.css?v=1234');
    });

    it('fetch 계층 리소스(routes/lang/components)도 역변환된다', () => {
        expect(staticToLegacy('/build/ext/1234/templates/t/routes.json')).toBe('/api/templates/t/routes.json?v=1234');
        expect(staticToLegacy('/build/ext/1234/templates/t/components.json')).toBe('/api/templates/t/components.json?v=1234');
        expect(staticToLegacy('/build/ext/1234/templates/t/lang/ko.json')).toBe('/api/templates/t/lang/ko.json?v=1234');
    });

    it('정적 게시 경로가 아니면 null (`/build/core/**` 제외 유지)', () => {
        expect(staticToLegacy('/build/core/template-engine.min.js')).toBeNull();
        expect(staticToLegacy('/api/templates/t/routes.json')).toBeNull();
        expect(staticToLegacy('')).toBeNull();
    });
});
