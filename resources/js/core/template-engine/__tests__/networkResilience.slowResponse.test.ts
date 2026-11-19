/**
 * 레이아웃 요청 시도 상한 30초 — 느린 정상 응답을 끊지 않는다 (설계 확정 2026-11-09, #869)
 *
 * `fetchWithRetry` 는 시도마다 상한 안에 응답 머리가 오지 않으면 끊고 백오프 뒤 다시 묻는다(재시도 2, 총 3시도).
 * 종전 레이아웃 요청은 공용 기본값 15초를 써서, 첫 요청이 16~18초 걸리는 느린 서버에서는 정상 응답이 세 번 모두 끊겨
 * 「페이지 로딩 실패」 화면으로 끝났고 서버는 같은 요청을 세 번 받았다. 레이아웃 요청(`LayoutLoader.fetchLayout`)만
 * 다른 API 요청(`ApiClient` axios 30초)과 같은 30초로 맞춘다. 라우트, 컴포넌트, 언어 파일 같은 다른 요청은 종전 15초다.
 * 30초를 넘는 무응답은 종전처럼 재시도 뒤 `LayoutLoaderError` 로 끝나 경로 오류 화면(`TemplateApp.showRouteError`)으로 간다.
 * 가짜 타이머로만 잰다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchWithRetry, LAYOUT_REQUEST_TIMEOUT_MS } from '../networkResilience';
import { LayoutLoader, LayoutLoaderError } from '../LayoutLoader';
import type { ComponentRegistry } from '../ComponentRegistry';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({ getToken: () => null, removeToken: () => undefined }),
}));

const LAYOUT = { version: '1.0.0', layout_name: 'home', components: [] };

/**
 * 레이아웃 로더 — 컴포넌트 레지스트리는 이 시험이 쓰지 않아 빈 조회 면만 준다.
 *
 * @returns 새 로더
 */
function newLoader(): LayoutLoader {
    return new LayoutLoader({ getComponent: () => undefined } as unknown as ComponentRegistry);
}

/**
 * 지정한 시간 뒤에 200 을 내는 fetch 대역 — signal 이 끊기면 AbortError 로 거절한다. delayMs 가 null 이면 끝내 응답하지 않는다.
 *
 * @param delayMs 응답까지 걸리는 시간(null 이면 무응답)
 * @returns fetch 대역
 */
function slowServer(delayMs: number | null) {
    return vi.fn().mockImplementation((_url: string, init?: RequestInit) => {
        return new Promise((resolve, reject) => {
            const timer = delayMs === null
                ? undefined
                : setTimeout(() => resolve(new Response(JSON.stringify({ data: LAYOUT }), { status: 200 })), delayMs);
            init?.signal?.addEventListener('abort', () => {
                if (timer !== undefined) clearTimeout(timer);
                reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
            });
        });
    });
}

/**
 * 약속의 결말을 이름으로 돌려준다(가짜 타이머를 돌리는 동안 미처리 거절 경고를 막는다).
 *
 * @param promise 대상
 * @returns 'resolved' 또는 오류 이름
 */
function outcome(promise: Promise<unknown>): Promise<string> {
    return promise.then(
        () => 'resolved',
        (error: unknown) => (error instanceof LayoutLoaderError ? `LayoutLoaderError:${error.code}` : String((error as { name?: string }).name)),
    );
}

describe('레이아웃 요청 시도 상한과 느린 정상 응답', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0.5);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    /**
     * @scenario case=layout_limit_constant
     * @effects slow_layout_response_received_in_one_attempt
     */
    it('레이아웃 요청 상한은 30초다(ApiClient 기본 30초와 같다)', () => {
        expect(LAYOUT_REQUEST_TIMEOUT_MS).toBe(30_000);
    });

    /**
     * @scenario case=layout_16s
     * @effects slow_layout_response_received_in_one_attempt
     */
    it('16초 걸리는 레이아웃 응답은 끊지 않고 한 번에 받는다', async () => {
        const fetchMock = slowServer(16_000);
        vi.stubGlobal('fetch', fetchMock);

        const settled = outcome(newLoader().loadLayout('t', 'home'));
        await vi.advanceTimersByTimeAsync(16_000);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        await expect(settled).resolves.toBe('resolved');
    });

    /**
     * @scenario case=layout_29_9s
     * @effects slow_layout_response_received_in_one_attempt
     */
    it('29.9초 걸리는 레이아웃 응답도 한 번에 받는다', async () => {
        const fetchMock = slowServer(29_900);
        vi.stubGlobal('fetch', fetchMock);

        const settled = outcome(newLoader().loadLayout('t', 'home'));
        await vi.advanceTimersByTimeAsync(29_900);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        await expect(settled).resolves.toBe('resolved');
    });

    /**
     * @scenario case=layout_no_response
     * @effects layout_no_response_retried_then_fails
     */
    it('응답이 끝내 없으면 30초마다 끊고 세 번 물은 뒤 LayoutLoaderError 로 끝난다', async () => {
        const fetchMock = slowServer(null);
        vi.stubGlobal('fetch', fetchMock);

        const settled = outcome(newLoader().loadLayout('t', 'home'));

        await vi.advanceTimersByTimeAsync(29_999);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1 + 300);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(30_000 + 600);
        expect(fetchMock).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(30_000);

        await expect(settled).resolves.toBe('LayoutLoaderError:LOAD_FAILED');
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    /**
     * @scenario case=other_16s
     * @effects other_requests_keep_default_limit
     */
    it('레이아웃이 아닌 요청(기본값)은 종전대로 15초에 끊겨 16초 응답이 세 번 모두 실패한다', async () => {
        const fetchMock = slowServer(16_000);
        vi.stubGlobal('fetch', fetchMock);

        const settled = outcome(fetchWithRetry('/api/templates/t/routes.json'));
        await vi.advanceTimersByTimeAsync(15_000 * 3 + 300 + 600);

        await expect(settled).resolves.toBe('AbortError');
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    /**
     * @scenario case=other_14s
     * @effects other_requests_keep_default_limit
     */
    it('레이아웃이 아닌 요청의 14초 응답은 한 번에 받는다', async () => {
        const fetchMock = slowServer(14_000);
        vi.stubGlobal('fetch', fetchMock);

        const settled = outcome(fetchWithRetry('/api/templates/t/routes.json'));
        await vi.advanceTimersByTimeAsync(14_000);

        await expect(settled).resolves.toBe('resolved');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
