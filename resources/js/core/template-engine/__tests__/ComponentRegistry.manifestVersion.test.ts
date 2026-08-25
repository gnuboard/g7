/**
 * ComponentRegistry 매니페스트 캐시 버전 테스트 (#122 작업 B)
 *
 * components.json 요청에 `?v`(확장 캐시 버전)가 부착되지 않아 확장
 * 라이프사이클 직후 stale 매니페스트를 받을 수 있던 결함과, 정적
 * manifestCache 가 버전 무시 키로 교차 오염되던 결함의 회귀를 막는다.
 *
 * 편집기 호출처(useEditorTemplateAssets)는 버전 미전달 — 옵셔널 파라미터로
 * 하위 호환을 유지하고 그 경로는 v0 캐시 키 + 무버전 URL 을 사용한다
 * (서버는 `?v` 생략 시 현재 버전 폴백 — #588).
 *
 * @effects versioned_boot_urls, manifest_cache_keys_are_version_scoped
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ComponentRegistry } from '../ComponentRegistry';

/** components.json 정상 응답 (raw manifest) */
function manifestOk(): Response {
    return {
        ok: true,
        status: 200,
        json: async () => ({
            version: '1.0.0',
            templateId: 'sirsoft-basic',
            components: { basic: [], composite: [], layout: [] },
        }),
    } as unknown as Response;
}

describe('ComponentRegistry 매니페스트 캐시 버전 (#122)', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        ComponentRegistry.resetInstance();
        // 정적 manifestCache 초기화 (테스트 격리)
        (ComponentRegistry as any).manifestCache = new Map();

        fetchMock = vi.fn().mockResolvedValue(manifestOk());
        vi.stubGlobal('fetch', fetchMock);

        // IIFE 번들 전역 (sirsoft-basic → SirsoftBasic)
        (window as any).SirsoftBasic = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        delete (window as any).SirsoftBasic;
    });

    function manifestCalls(): string[] {
        return fetchMock.mock.calls
            .map((c: any[]) => String(c[0]))
            .filter((u: string) => u.includes('/components'));
    }

    it('버전 전달 시 components.json 요청에 `?v` 가 부착된다', async () => {
        const registry = ComponentRegistry.createIsolatedInstance();
        await registry.loadComponents('sirsoft-basic', 'user', 7);

        const calls = manifestCalls();
        expect(calls).toHaveLength(1);
        expect(calls[0]).toContain('v=7');
    });

    it('버전 미전달(편집기 경로) 시 무버전 URL 을 유지한다 (하위 호환)', async () => {
        const registry = ComponentRegistry.createIsolatedInstance();
        await registry.loadComponents('sirsoft-basic', 'user');

        const calls = manifestCalls();
        expect(calls).toHaveLength(1);
        expect(calls[0]).not.toContain('v=');
    });

    it('정적 manifestCache 키가 버전을 포함해 교차 오염되지 않는다', async () => {
        // v7 로드 → fetch 1회
        const a = ComponentRegistry.createIsolatedInstance();
        await a.loadComponents('sirsoft-basic', 'user', 7);
        expect(manifestCalls()).toHaveLength(1);

        // 같은 템플릿을 v9 로 로드 → v7 캐시를 재사용하면 안 된다 (fetch 2회째)
        const b = ComponentRegistry.createIsolatedInstance();
        await b.loadComponents('sirsoft-basic', 'user', 9);
        expect(manifestCalls()).toHaveLength(2);
        expect(manifestCalls()[1]).toContain('v=9');

        // 같은 버전(v7) 재로드는 캐시 히트 (fetch 추가 없음)
        const c = ComponentRegistry.createIsolatedInstance();
        await c.loadComponents('sirsoft-basic', 'user', 7);
        expect(manifestCalls()).toHaveLength(2);
    });

    it('버전 미전달 경로는 v0 캐시 키를 공유한다 (편집기 경로 캐시 유지)', async () => {
        const a = ComponentRegistry.createIsolatedInstance();
        await a.loadComponents('sirsoft-basic', 'user');
        expect(manifestCalls()).toHaveLength(1);

        const b = ComponentRegistry.createIsolatedInstance();
        await b.loadComponents('sirsoft-basic', 'user');
        expect(manifestCalls()).toHaveLength(1);
    });
});
