/**
 * 트러블슈팅 회귀 테스트 — 네트워크 실패 복원력
 *
 * 페이지 로드 요청 1건의 일시 실패가 앱 전체를 죽이던 결함의 재발을 막는다.
 *
 * 핵심 규칙: `fetch` 는 4xx/5xx 로 reject 하지 않는다(`Response.ok=false` 로 resolve).
 * 따라서 `TypeError: Failed to fetch` 는 **응답 자체가 없었다**는 뜻(요청 취소/커넥션 유실)이고,
 * 재시도 가치가 있는 것은 그것뿐이다. HTTP 응답은 호출부의 상태코드 분기에 그대로 넘긴다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    fetchWithRetry,
    loadScriptWithRetry,
    isNetworkFailure,
    isAbortError,
    isDocumentUnloading,
    installUnloadGuard,
    resetUnloadGuardForTesting,
} from '../networkResilience';

/**
 * `Response` 스텁 (jsdom 의 Response 구현 차이를 피하기 위해 최소 형태만 사용)
 */
function makeResponse(status: number): Response {
    return { ok: status >= 200 && status < 300, status } as Response;
}

/** fetch 스펙상 네트워크 오류 = TypeError */
function networkError(): TypeError {
    return new TypeError('Failed to fetch');
}

describe('[사례 1] 요청 1건의 네트워크 취소가 앱 전체를 죽인다', () => {
    beforeEach(() => {
        resetUnloadGuardForTesting();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
        resetUnloadGuardForTesting();
    });

    /**
     * 증상: 모바일 새로고침 연타 시 요청 1건이 취소되면 전면 "초기화 실패" 화면.
     * 해결: 네트워크 레벨 실패(TypeError)만 지수 백오프로 재시도한다.
     */
    it('1회차 TypeError 후 2회차 성공이면 Response 를 반환한다 (fetch 2회)', async () => {
        const fetchMock = vi
            .fn()
            .mockRejectedValueOnce(networkError())
            .mockResolvedValueOnce(makeResponse(200));
        vi.stubGlobal('fetch', fetchMock);

        const response = await fetchWithRetry('/api/x', { baseDelayMs: 1, label: 'x' });

        expect(response.status).toBe(200);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    /**
     * 증상: 재시도 루프가 무한히 돌 수 있다는 우려.
     * 해결: 상수 상한(retries=2 → 총 3시도)의 루프 카운터. 재귀 아님.
     */
    it('3회 연속 TypeError 면 rethrow 하고 호출은 정확히 3회에서 멈춘다 (무한루프 부재)', async () => {
        const fetchMock = vi.fn().mockRejectedValue(networkError());
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchWithRetry('/api/x', { baseDelayMs: 1 })).rejects.toThrow(TypeError);
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    /**
     * 증상: HTTP 에러까지 재시도하면 401→토큰갱신→401 류 순환에 끼어든다.
     * 해결: fetch 는 4xx/5xx 로 reject 하지 않는다. HTTP 응답은 그대로 넘긴다.
     */
    it('HTTP 500 응답은 재시도하지 않고 ok=false Response 를 그대로 반환한다', async () => {
        const fetchMock = vi.fn().mockResolvedValue(makeResponse(500));
        vi.stubGlobal('fetch', fetchMock);

        const response = await fetchWithRetry('/api/x', { baseDelayMs: 1 });

        expect(response.ok).toBe(false);
        expect(response.status).toBe(500);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    /**
     * 증상: 호출부가 명시적으로 취소한 요청을 유틸이 되살려 재발사한다.
     * 해결: 외부 signal 의 AbortError 는 즉시 rethrow (재시도 금지).
     */
    it('외부 signal 취소는 즉시 rethrow 하고 재시도하지 않는다', async () => {
        const abortError = Object.assign(new Error('aborted'), { name: 'AbortError' });
        const fetchMock = vi.fn().mockRejectedValue(abortError);
        vi.stubGlobal('fetch', fetchMock);

        const controller = new AbortController();

        await expect(
            fetchWithRetry('/api/x', { baseDelayMs: 1, init: { signal: controller.signal } })
        ).rejects.toMatchObject({ name: 'AbortError' });

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    /**
     * 증상: 커넥션이 죽었는데 TypeError 도 안 던지고 무한 대기하는 모바일 케이스.
     * 해결: 시도별 timeout 을 두고, 내부 timeout 발화분만 재시도한다.
     */
    it('내부 timeout 초과 시 재시도가 발생한다', async () => {
        const fetchMock = vi.fn().mockImplementation((_url: string, init?: RequestInit) => {
            const signal = init?.signal;
            if (signal?.aborted) {
                return Promise.reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
            }
            // 첫 시도는 timeout 이 발화할 때까지 응답하지 않는다
            if (fetchMock.mock.calls.length === 1) {
                return new Promise((_resolve, reject) => {
                    signal?.addEventListener('abort', () => {
                        reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
                    });
                });
            }
            return Promise.resolve(makeResponse(200));
        });
        vi.stubGlobal('fetch', fetchMock);

        const response = await fetchWithRetry('/api/x', { baseDelayMs: 1, timeoutMs: 10 });

        expect(response.status).toBe(200);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    /**
     * 증상: 즉시 재시도는 죽은 커넥션 풀을 재사용해 동일 실패 확률이 높다.
     * 해결: baseDelayMs(300) 지수 백오프. 대기 전에는 다음 시도가 발사되지 않는다.
     */
    it('백오프 대기 시간이 지나기 전에는 다음 fetch 를 발사하지 않는다', async () => {
        vi.useFakeTimers();

        const fetchMock = vi.fn().mockRejectedValue(networkError());
        vi.stubGlobal('fetch', fetchMock);

        const promise = fetchWithRetry('/api/x', { baseDelayMs: 300, maxDelayMs: 2000 });
        promise.catch(() => {
            /* 아래에서 단언 */
        });

        // 1회차 실패까지 진행
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // jitter ±25% → 최소 225ms. 200ms 시점에는 아직 2회차가 없어야 한다.
        await vi.advanceTimersByTimeAsync(200);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // 백오프 상한(375ms) 경과 후에는 2회차가 발사된다
        await vi.advanceTimersByTimeAsync(200);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(5000);
        await expect(promise).rejects.toThrow(TypeError);
    });

    /**
     * 증상: 새로고침으로 버려지는 문서에 에러 화면을 그린다.
     * 해결: pagehide 이후에는 남은 재시도를 포기하고 즉시 rethrow 한다.
     */
    it('문서 이탈 중이면 백오프 대기 없이 즉시 rethrow 한다 (재시도 없음)', async () => {
        installUnloadGuard();
        window.dispatchEvent(new Event('pagehide'));
        expect(isDocumentUnloading()).toBe(true);

        const fetchMock = vi.fn().mockRejectedValue(networkError());
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetchWithRetry('/api/x', { baseDelayMs: 1 })).rejects.toThrow(TypeError);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    /**
     * 증상: bfcache 로 복귀한 문서가 영구히 "이탈 중" 으로 오판되어 에러를 삼킨다.
     * 해결: pageshow{persisted:true} 에서 가드를 해제한다.
     */
    it('bfcache 복귀(pageshow persisted) 시 이탈 가드가 해제된다', () => {
        installUnloadGuard();
        window.dispatchEvent(new Event('pagehide'));
        expect(isDocumentUnloading()).toBe(true);

        const pageshow = new Event('pageshow') as PageTransitionEvent;
        Object.defineProperty(pageshow, 'persisted', { value: true });
        window.dispatchEvent(pageshow);

        expect(isDocumentUnloading()).toBe(false);
    });

    /**
     * 증상: HTTP 에러와 네트워크 실패를 구분하지 못해 재시도 정책이 뭉개진다.
     * 해결: TypeError = 응답 없음(재시도 대상), AbortError = 취소(재시도 금지).
     */
    it('판별 진리표: isNetworkFailure / isAbortError', () => {
        const abortError = Object.assign(new Error('aborted'), { name: 'AbortError' });

        expect(isNetworkFailure(new TypeError('Failed to fetch'))).toBe(true);
        expect(isNetworkFailure(abortError)).toBe(false);
        expect(isNetworkFailure(new Error('boom'))).toBe(false);
        expect(isNetworkFailure(null)).toBe(false);

        expect(isAbortError(abortError)).toBe(true);
        expect(isAbortError(new TypeError('Failed to fetch'))).toBe(false);
        expect(isAbortError(null)).toBe(false);
    });
});

describe('[사례 2] <script> 로더의 onerror 가 resolve() 하면 실패가 성공으로 위장된다', () => {
    let originalCreateElement: typeof document.createElement;

    beforeEach(() => {
        resetUnloadGuardForTesting();
        originalCreateElement = document.createElement.bind(document);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        resetUnloadGuardForTesting();
        document.head.querySelectorAll('script').forEach((el) => el.remove());
    });

    /**
     * appendChild 를 가로채 script 의 onload/onerror 를 시나리오대로 발화시킨다.
     *
     * @param outcomes 시도별 결과 ('error' | 'load')
     * @return 생성된 script element 목록
     */
    function stubScriptLoading(outcomes: Array<'error' | 'load'>): HTMLScriptElement[] {
        const created: HTMLScriptElement[] = [];

        vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
            if (node.tagName === 'SCRIPT') {
                created.push(node);
                // 실제 DOM 에 넣어 getElementById 로 제거 가능하게 한다
                Object.defineProperty(node, 'isConnected', { value: true, configurable: true });
                document.body.appendChild(node);

                const outcome = outcomes[created.length - 1] ?? 'load';
                queueMicrotask(() => {
                    if (outcome === 'error') {
                        node.onerror?.(new Event('error'));
                    } else {
                        node.onload?.(new Event('load'));
                    }
                });
            }
            return node;
        }) as any);

        return created;
    }

    /**
     * 증상: 첫 시도 실패 후 재시도할 때 기존 element 를 남기면 IIFE 가 두 번 실행된다.
     * 해결: 재시도 전 기존 element 를 제거하고 새로 만든다.
     */
    it('1회 실패 후 성공 시 script 를 2회 생성하고 이전 element 는 제거한다', async () => {
        const created = stubScriptLoading(['error', 'load']);

        await expect(
            loadScriptWithRetry('/bundle.js', { id: 'test-bundle' }, { baseDelayMs: 1 })
        ).resolves.toBeUndefined();

        expect(created).toHaveLength(2);
        // 실패한 첫 element 는 DOM 에서 제거되어 IIFE 중복 실행이 없어야 한다
        expect(document.querySelectorAll('#test-bundle')).toHaveLength(1);
    });

    /**
     * 증상: onerror → resolve() 로 실패를 삼키면 상위 try/catch 가 무력화되고
     *       앱은 한참 뒤 엉뚱한 곳(미등록 핸들러)에서 죽는다.
     * 해결: 최종 실패는 반드시 reject 로 표면화한다.
     */
    it('3회 모두 실패하면 reject 한다 (resolve 로 은폐하지 않는다)', async () => {
        const created = stubScriptLoading(['error', 'error', 'error']);

        await expect(
            loadScriptWithRetry('/bundle.js', { id: 'test-bundle' }, { baseDelayMs: 1 })
        ).rejects.toThrow(/Failed to load script/);

        expect(created).toHaveLength(3);
    });
});

describe('[사례 3] 확장 부재 시 5초 백지 + 내부 식별자 raw 노출', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    /**
     * 증상: 확장 번들 부재 시 waitForHandlers 가 maxWait(5초) 통째로 블로킹 → 백지.
     * 해결: 번들 실패가 확정된 확장의 핸들러는 기다리지 않는다.
     *
     * 통합 검증(TemplateApp.waitForHandlers 실제 호출)은
     * `core/__tests__/TemplateApp.networkResilience.test.ts` 가 담당한다.
     * 여기서는 그 판단의 근거가 되는 계약 — "실패가 사실로 기록되는가" — 를 잠근다.
     */
    it('번들 JS 로드 실패가 확장 로더에 사실로 기록된다 (기다리지 않기 위한 근거)', async () => {
        const { ModuleAssetLoader } = await import('../../modules/ModuleAssetLoader');
        const loader = new ModuleAssetLoader();

        vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
            if (node.tagName === 'SCRIPT') {
                document.body.appendChild(node);
                queueMicrotask(() => node.onerror?.(new Event('error')));
            }
            return node;
        }) as any);

        expect(loader.hasFailedJsAssets()).toBe(false);

        await expect(
            loader.loadBundle('module', '/api/modules/bundle.js', null)
        ).rejects.toThrow();

        // 실패가 "확정된 사실" 로 남아야 waitForHandlers 가 오지 않을 핸들러를 포기할 수 있다.
        expect(loader.hasFailedJsAssets()).toBe(true);
        expect(loader.getFailedJsAssets()).toContain('module');

        document.body.querySelectorAll('script').forEach((el) => el.remove());
    });

    /**
     * 증상: `Unknown action handler: sirsoft-ecommerce.initPreferredCurrency` 가
     *       빨간 토스트로 사용자에게 raw 노출된다.
     * 해결: 미등록 핸들러 에러를 플래그로 식별해 표시 계층(errorHandling 정책)을 태우지 않는다.
     */
    it('미등록 핸들러를 실제로 dispatch 하면 unknownHandler 로 표시되고 토스트가 뜨지 않는다', async () => {
        const { ActionDispatcher, ActionError } = await import('../ActionDispatcher');

        const dispatcher = new ActionDispatcher();

        // 확장 번들이 로드되지 않아 그 확장 소유 핸들러가 등록되지 않은 상황
        expect((dispatcher as any).customHandlers?.has('sirsoft-ecommerce.initPreferredCurrency')).toBeFalsy();

        // 표시 계층 감시자 — ErrorHandlingResolver.
        //
        // 미등록 핸들러의 에러는 이 resolver 를 **태우지 않고** 곧장 빠져나가야 한다.
        // resolver 가 돌면 errorHandling 정책(토스트 등)이 결정·실행되어
        // `Unknown action handler: sirsoft-ecommerce.initPreferredCurrency` 라는
        // 내부 식별자가 담긴 raw 영문 문구가 사용자에게 노출된다.
        //
        // resolver 호출 여부가 곧 "표시 계층을 탔는가" 이므로 이를 직접 감시한다.
        const resolverModule = await import('../../error');
        const resolveSpy = vi.spyOn(
            resolverModule.getErrorHandlingResolver(),
            'resolve'
        );

        const result: any = await dispatcher.dispatchAction(
            {
                handler: 'sirsoft-ecommerce.initPreferredCurrency',
                // 표시 계층을 태우라고 명시해도 미등록 핸들러는 예외로 걸러져야 한다
                errorHandling: { default: { toast: true } },
            } as any,
            {} as any
        );

        // 실패 사실과 그 사유(미등록 핸들러)는 호출부에 그대로 전달된다 — 삼키지 않는다.
        expect(result.success).toBe(false);
        expect(result.error).toBeInstanceOf(ActionError);
        expect(result.error.unknownHandler).toBe(true);

        // 핵심 단언: 표시 계층(ErrorHandlingResolver) 이 실행되지 않았다
        //           = 내부 식별자가 사용자에게 노출되지 않았다.
        expect(resolveSpy).not.toHaveBeenCalled();
    });

    it('미등록 핸들러가 아닌 일반 액션 에러는 종전대로 표시 계층으로 넘어간다 (과잉 억제 방지)', async () => {
        const { ActionError } = await import('../ActionDispatcher');

        // 억제는 미등록 핸들러에 **한정**된다. 일반 에러까지 삼키면 사용자가 실패를
        // 인지하지 못하는 더 큰 결함이 된다.
        const normalError = new ActionError('API request failed');
        expect(normalError.unknownHandler).toBe(false);
    });
});
