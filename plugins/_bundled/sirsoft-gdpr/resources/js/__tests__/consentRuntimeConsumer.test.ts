/**
 * @file consentRuntimeConsumer.test.ts
 * @description 이 플러그인의 브라우저 쪽은 코어 동의 런타임의 소비자다 (#639, 결정 D8)
 *
 * 예전에는 부팅 때와 동의 저장 직후(`sirsoft-gdpr.syncConsent`)에 자체적으로 동의 상태를 조회하고
 * `gdpr:consent-changed` 를 보냈다. SPA 로그인 뒤에는 아무도 다시 조회하지 않아 회원 동의 대신 게스트 거부가
 * 남았다. 이제 코어 런타임 하나가 조회·보관하고(`_global.consent`) 로그인·로그아웃 때 다시 읽으며, 이 플러그인은
 * 부팅 때 `G7Core.consent.getSnapshot()` 을 읽고 `G7Core.consent.subscribe()` 로 변경을 받는다.
 *
 * 소비자 규칙(계획 §3.2):
 * - `ready`·`none` 일 때만 엔진을 바꾼다. `unknown`(부팅 중·재조회 중)에는 현재 상태 유지.
 * - 기능 저장소 정리는 `ready` 이면서 기능 미동의일 때만.
 * - 자체 동의 상태 조회 0회.
 *
 * @scenario case=frontend_boot
 *
 * @effects frontend_consumes_core_consent_runtime_only
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const engine = vi.hoisted(() => ({
    setCurrentConsent: vi.fn(),
    setBlockedDomains: vi.fn(),
    startBlocker: vi.fn(),
    installPreblocker: vi.fn(),
    updatePreblockerConfig: vi.fn(),
    restorePreblockedCategory: vi.fn(),
    installStorageInterceptor: vi.fn(),
    updateStorageInterceptorConfig: vi.fn(),
    installCookieInterceptor: vi.fn(),
    updateCookieInterceptorConfig: vi.fn(),
    cleanupFunctionalArtifacts: vi.fn(),
    installUserInitiatedTracker: vi.fn(),
}));

vi.mock('../blocker', () => ({
    setCurrentConsent: engine.setCurrentConsent,
    setBlockedDomains: engine.setBlockedDomains,
    startBlocker: engine.startBlocker,
}));
vi.mock('../preblocker', () => ({
    installPreblocker: engine.installPreblocker,
    updatePreblockerConfig: engine.updatePreblockerConfig,
    restorePreblockedCategory: engine.restorePreblockedCategory,
}));
vi.mock('../storageInterceptor', () => ({
    installStorageInterceptor: engine.installStorageInterceptor,
    updateStorageInterceptorConfig: engine.updateStorageInterceptorConfig,
}));
vi.mock('../cookieInterceptor', () => ({
    installCookieInterceptor: engine.installCookieInterceptor,
    updateCookieInterceptorConfig: engine.updateCookieInterceptorConfig,
}));
vi.mock('../functionalCleaner', () => ({ cleanupFunctionalArtifacts: engine.cleanupFunctionalArtifacts }));
vi.mock('../userInitiatedTracker', () => ({ installUserInitiatedTracker: engine.installUserInitiatedTracker }));

type Snapshot = {
    contractVersion: number;
    status: 'unknown' | 'ready' | 'none';
    categories: Record<string, boolean>;
    needsRenewal: boolean;
    policyVersion: string | null;
};

const DOMAINS = { analytics: ['ga.example.com'] };

/** 코어 동의 스냅샷을 만든다 */
function snapshot(status: Snapshot['status'], categories: Record<string, boolean> = {}, needsRenewal = false): Snapshot {
    return { contractVersion: 1, status, categories: { necessary: true, ...categories }, needsRenewal, policyVersion: 'v2' };
}

let current: Snapshot;
let listeners: Array<(next: Snapshot) => void>;
let apiGet: ReturnType<typeof vi.fn>;
let fetchSpy: ReturnType<typeof vi.fn>;

/** 코어 런타임이 변경을 알리는 것을 흉내 낸다 (`consent:changed`) */
function emit(next: Snapshot): void {
    current = next;
    listeners.forEach((listener) => listener(next));
}

/** 진입점을 새로 불러 부트스트랩을 끝까지 돌린다 */
async function boot(): Promise<void> {
    vi.resetModules();
    await import('../index');
    for (let i = 0; i < 5; i++) await Promise.resolve();
}

describe('코어 동의 런타임 소비자', () => {
    beforeEach(() => {
        Object.values(engine).forEach((fn) => fn.mockClear());
        current = snapshot('unknown');
        listeners = [];
        apiGet = vi.fn(async (path: string) => {
            if (path === '/plugins/sirsoft-gdpr/settings') {
                return { data: { banner_enabled: true, cookie_policy_version: 'v2', blocked_domains: DOMAINS } };
            }
            throw new Error(`unexpected ${path}`);
        });
        fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        (window as any).G7Config = { plugins: { 'sirsoft-gdpr': { banner_enabled: true, blocked_domains: DOMAINS } } };
        (window as any).G7Core = {
            api: { get: apiGet },
            consent: {
                getSnapshot: () => current,
                subscribe: (listener: (next: Snapshot) => void) => {
                    listeners.push(listener);
                    return () => {
                        listeners = listeners.filter((l) => l !== listener);
                    };
                },
            },
        };
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete (window as any).G7Core;
        delete (window as any).G7Config;
    });

    // @scenario case=frontend_boot
    // @effects frontend_consumes_core_consent_runtime_only
    it('부팅 때 자체 동의 조회 없이 코어 스냅샷을 구독한다 — unknown 이면 엔진을 건드리지 않는다', async () => {
        await boot();

        expect(apiGet.mock.calls.map((c) => c[0])).toEqual(['/plugins/sirsoft-gdpr/settings']);
        expect(fetchSpy).not.toHaveBeenCalled();
        expect(listeners).toHaveLength(1);
        expect(engine.startBlocker).toHaveBeenCalledTimes(1);
        expect(engine.setCurrentConsent).not.toHaveBeenCalled();
        expect(engine.cleanupFunctionalArtifacts).not.toHaveBeenCalled();
    });

    // @scenario case=frontend_change
    // @effects frontend_consumes_core_consent_runtime_only
    it('consent:changed 의 ready 스냅샷으로 차단 엔진·preblocker·인터셉터를 맞추고, 기능 미동의면 정리한다', async () => {
        await boot();

        emit(snapshot('ready', { analytics: true, functional: false }));

        expect(engine.setCurrentConsent).toHaveBeenLastCalledWith({
            categories: { necessary: true, analytics: true, functional: false },
            needs_renewal: false,
        });
        expect(engine.updatePreblockerConfig).toHaveBeenLastCalledWith(expect.objectContaining({
            blockedDomains: DOMAINS,
            consent: { necessary: true, analytics: true, functional: false },
            needs_renewal: false,
        }));
        expect(engine.updateStorageInterceptorConfig).toHaveBeenLastCalledWith(expect.objectContaining({ functionalConsented: false }));
        expect(engine.updateCookieInterceptorConfig).toHaveBeenLastCalledWith(expect.objectContaining({ functionalConsented: false }));
        expect(engine.cleanupFunctionalArtifacts).toHaveBeenCalledTimes(1);
        expect(engine.restorePreblockedCategory).toHaveBeenCalledWith('analytics');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    // @scenario case=frontend_change
    // @effects frontend_consumes_core_consent_runtime_only
    it('재조회 중 unknown 은 현재 엔진 상태를 유지하고, 동의한 회원의 저장소를 지우지 않는다', async () => {
        await boot();
        emit(snapshot('ready', { functional: true, analytics: true }));
        expect(engine.updateStorageInterceptorConfig).toHaveBeenLastCalledWith(expect.objectContaining({ functionalConsented: true }));
        expect(engine.cleanupFunctionalArtifacts).not.toHaveBeenCalled();
        const calls = engine.setCurrentConsent.mock.calls.length;

        // 로그아웃·로그인 직후 — 코어가 unknown 을 먼저 알린다
        emit(snapshot('unknown'));

        expect(engine.setCurrentConsent).toHaveBeenCalledTimes(calls);
        expect(engine.cleanupFunctionalArtifacts).not.toHaveBeenCalled();
    });

    // @scenario case=frontend_change
    // @effects frontend_consumes_core_consent_runtime_only
    it('정책 재동의가 필요하면 차단 요소를 복원하지 않는다', async () => {
        await boot();

        emit(snapshot('ready', { analytics: true, functional: true }, true));

        expect(engine.restorePreblockedCategory).not.toHaveBeenCalled();
        expect(engine.updateStorageInterceptorConfig).toHaveBeenLastCalledWith(expect.objectContaining({ functionalConsented: false }));
    });

    // @scenario case=frontend_boot
    // @effects frontend_consumes_core_consent_runtime_only
    it('부팅 시점에 이미 ready 면 곧바로 반영한다', async () => {
        current = snapshot('ready', { analytics: true, functional: true });

        await boot();

        expect(engine.setCurrentConsent).toHaveBeenCalledTimes(1);
        expect(engine.cleanupFunctionalArtifacts).not.toHaveBeenCalled();
    });

    // @scenario case=frontend_boot
    // @effects frontend_consumes_core_consent_runtime_only
    it('동의 런타임이 없는 화면(관리자)에서는 보수적 차단을 유지하고 조회하지 않는다', async () => {
        delete (window as any).G7Core.consent;

        await boot();

        expect(engine.setCurrentConsent).not.toHaveBeenCalled();
        expect(apiGet.mock.calls.map((c) => c[0])).toEqual(['/plugins/sirsoft-gdpr/settings']);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    // @scenario case=frontend_boot
    // @effects frontend_consumes_core_consent_runtime_only
    it('G7Core.api 가 없으면 토큰 없는 raw fetch 로 대신 묻지 않는다', async () => {
        delete (window as any).G7Core.api;

        await boot();

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(engine.startBlocker).not.toHaveBeenCalled();
    });
});
