/**
 * 네트워크 복원력 유틸리티
 *
 * 페이지 로드에 필요한 요청 1건이 일시적으로 실패했다고 앱 전체가 죽지 않도록,
 * **네트워크 레벨 실패에만** 지수 백오프 재시도를 거는 fetch/script 로더를 제공한다.
 *
 * 판별의 핵심: `fetch` 는 4xx/5xx 로 reject 하지 않는다(`Response.ok=false` 로 resolve).
 * 따라서 `TypeError: Failed to fetch` 는 **응답 자체가 없었다**는 뜻이다 — 요청 취소 또는
 * 커넥션 유실. 서버가 에러를 응답한 것과는 근본이 다르며, 전자만 재시도 가치가 있다.
 * HTTP 응답은 그대로 호출부에 넘겨 기존 상태코드 분기(401 재시도 등)를 보존한다.
 *
 * @since engine-v1.53.0
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('networkResilience');

/**
 * 재시도 옵션
 *
 * @since engine-v1.53.0
 */
export interface RetryOptions {
    /** 재시도 횟수 (기본 2 = 총 3시도) */
    retries?: number;
    /** 첫 백오프 대기 (기본 300ms, 시도마다 2배 + ±25% jitter) */
    baseDelayMs?: number;
    /** 백오프 상한 (기본 2000ms) */
    maxDelayMs?: number;
    /** 시도별 타임아웃 (기본 15000ms, 0 이면 비활성) */
    timeoutMs?: number;
    /** 로그 식별용 라벨 */
    label?: string;
}

const DEFAULT_RETRIES = 2;
const DEFAULT_BASE_DELAY_MS = 300;
const DEFAULT_MAX_DELAY_MS = 2000;
const DEFAULT_TIMEOUT_MS = 15000;

/**
 * 취소(AbortError) 여부를 판별합니다.
 *
 * `instanceof DOMException` 을 쓰지 않는 이유: jsdom 등 테스트 환경에서 DOMException 이
 * 없거나 다른 realm 의 것일 수 있어 판정이 어긋난다. name 비교가 환경 독립적이다.
 *
 * @param error 검사할 에러
 * @return bool 취소로 인한 에러이면 true
 * @since engine-v1.53.0
 */
export function isAbortError(error: unknown): boolean {
    return (error as { name?: string } | null)?.name === 'AbortError';
}

/**
 * 네트워크 레벨 실패(응답 없음) 여부를 판별합니다.
 *
 * fetch 스펙상 네트워크 오류는 TypeError 로 reject 된다. 취소(AbortError)는
 * 호출부의 의도이므로 재시도 대상이 아니다.
 *
 * @param error 검사할 에러
 * @return bool 재시도할 가치가 있는 네트워크 실패이면 true
 * @since engine-v1.53.0
 */
export function isNetworkFailure(error: unknown): boolean {
    if (isAbortError(error)) return false;
    return error instanceof TypeError;
}

let unloading = false;
let unloadGuardInstalled = false;

/**
 * 현재 문서가 이탈(새로고침/이동) 중인지 반환합니다.
 *
 * @return bool 이탈 중이면 true
 * @since engine-v1.53.0
 */
export function isDocumentUnloading(): boolean {
    return unloading;
}

/**
 * 문서 이탈 감지 가드를 설치합니다 (중복 설치 무해).
 *
 * 이탈 중 발생한 요청 실패는 "버려지는 문서" 의 실패이므로 에러 화면을 띄우면 안 된다.
 * 사용자가 이미 떠난 화면에 에러를 그리는 것은 그 자체가 결함이다.
 *
 * `visibilitychange`/`hidden` 은 의도적으로 쓰지 않는다 — 탭 전환·앱 백그라운드로도
 * 발화하므로, 백그라운드에서 재시도가 모두 실패한 **정상적 초기화 실패**까지 삼키면
 * 사용자가 돌아왔을 때 영구 빈 화면이 된다(현행보다 나쁨). 새로고침·이탈은 `pagehide`
 * 가 반드시 발화한다.
 *
 * @return void
 * @since engine-v1.53.0
 */
export function installUnloadGuard(): void {
    if (typeof window === 'undefined' || unloadGuardInstalled) return;
    unloadGuardInstalled = true;

    window.addEventListener('pagehide', () => {
        unloading = true;
    });

    // bfcache 복귀 시 해제 — 없으면 뒤로가기로 복귀한 문서가 영구히 "이탈 중" 으로 오판된다.
    window.addEventListener('pageshow', (event) => {
        if ((event as PageTransitionEvent).persisted) {
            unloading = false;
        }
    });

    // Safari 보강 (pagehide 미발화 케이스 대비)
    window.addEventListener('beforeunload', () => {
        unloading = true;
    });
}

/**
 * 테스트 전용 — unload 가드 상태를 초기화합니다.
 *
 * @return void
 * @since engine-v1.53.0
 */
export function resetUnloadGuardForTesting(): void {
    unloading = false;
    unloadGuardInstalled = false;
}

/**
 * 지수 백오프 대기 시간을 계산합니다 (2배 증가 + ±25% jitter).
 *
 * jitter 를 두는 이유: 병렬 요청이 동시에 실패하면(커넥션 유실은 대개 그렇다)
 * 재시도가 한 시점에 몰려 스탬피드가 된다.
 *
 * @param attempt 완료된 시도 횟수 (0부터)
 * @param baseDelayMs 기준 대기 시간
 * @param maxDelayMs 상한
 * @return number 대기할 밀리초
 * @since engine-v1.53.0
 */
function computeBackoffDelay(attempt: number, baseDelayMs: number, maxDelayMs: number): number {
    const exponential = Math.min(baseDelayMs * Math.pow(2, attempt), maxDelayMs);
    const jitter = exponential * 0.25 * (Math.random() * 2 - 1);
    return Math.max(0, Math.round(exponential + jitter));
}

/**
 * 지정 시간만큼 대기합니다.
 *
 * @param ms 대기할 밀리초
 * @return Promise<void>
 * @since engine-v1.53.0
 */
function delay(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * 네트워크 레벨 실패에만 재시도하는 fetch 래퍼입니다.
 *
 * HTTP 응답(2xx/4xx/5xx 무관)은 **재시도하지 않고 그대로 반환**한다. `!response.ok`
 * 검사와 throw 는 호출부 책임으로 남긴다 — 유틸이 `.ok` 를 보면 호출부의 상태코드
 * 분기(예: LayoutLoader 의 401 재시도)와 이중 재시도가 겹친다.
 *
 * 외부 signal 에 의한 AbortError 는 즉시 rethrow 한다(호출부의 명시적 취소를 유틸이
 * 되살리면 안 된다). 내부 timeout 이 발화시킨 abort 만 재시도 대상이다.
 *
 * @param url 요청 URL
 * @param options 재시도 옵션 + fetch init
 * @return Promise<Response> HTTP 응답 (4xx/5xx 포함)
 * @throws TypeError 모든 시도가 네트워크 실패로 끝난 경우
 * @since engine-v1.53.0
 */
export async function fetchWithRetry(
    url: string,
    options: RetryOptions & { init?: RequestInit } = {}
): Promise<Response> {
    const {
        retries = DEFAULT_RETRIES,
        baseDelayMs = DEFAULT_BASE_DELAY_MS,
        maxDelayMs = DEFAULT_MAX_DELAY_MS,
        timeoutMs = DEFAULT_TIMEOUT_MS,
        label = url,
        init,
    } = options;

    const maxAttempts = retries + 1;
    let lastError: unknown;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        // 내부 timeout 이 발화시킨 abort 와 외부 signal 의 abort 를 구분하기 위한 플래그.
        // 이 구분이 없으면 호출부가 명시적으로 취소한 요청을 유틸이 되살려 재발사한다.
        let timedOut = false;
        let timeoutId: ReturnType<typeof setTimeout> | undefined;
        const controller = timeoutMs > 0 ? new AbortController() : null;

        try {
            const requestInit: RequestInit = { ...init };

            if (controller) {
                // 외부 signal 이 있으면 그 취소를 내부 controller 로 전파한다.
                const externalSignal = init?.signal;
                if (externalSignal) {
                    if (externalSignal.aborted) {
                        controller.abort();
                    } else {
                        externalSignal.addEventListener('abort', () => controller.abort(), { once: true });
                    }
                }
                requestInit.signal = controller.signal;
                timeoutId = setTimeout(() => {
                    timedOut = true;
                    controller.abort();
                }, timeoutMs);
            }

            const response = await fetch(url, requestInit);

            // HTTP 응답이 왔다 = 재시도 대상 아님. 상태코드 판단은 호출부 몫.
            return response;
        } catch (error) {
            lastError = error;

            // 외부 취소는 즉시 rethrow (유틸이 되살리지 않는다)
            if (isAbortError(error) && !timedOut) {
                throw error;
            }

            // 내부 timeout 이 발화시킨 abort 는 네트워크 실패와 동급으로 취급해 재시도한다.
            const retryable = timedOut || isNetworkFailure(error);
            if (!retryable) {
                throw error;
            }

            // 이탈 중인 문서는 남은 재시도를 포기한다 (버려질 문서에 대한 낭비)
            if (isDocumentUnloading()) {
                logger.warn(`Document unloading, aborting retries: ${label}`);
                throw error;
            }

            const isLastAttempt = attempt === maxAttempts - 1;
            if (isLastAttempt) {
                logger.warn(
                    `All ${maxAttempts} attempts failed: ${label}`,
                    error
                );
                throw error;
            }

            const waitMs = computeBackoffDelay(attempt, baseDelayMs, maxDelayMs);
            logger.warn(
                `Network failure (attempt ${attempt + 1}/${maxAttempts}), retrying in ${waitMs}ms: ${label}`
            );
            await delay(waitMs);
        } finally {
            if (timeoutId !== undefined) {
                clearTimeout(timeoutId);
            }
        }
    }

    // 도달 불가 (루프가 반드시 return 또는 throw 한다) — 타입 완결용
    throw lastError;
}

/**
 * `<script src>` 로드를 재시도하는 로더입니다.
 *
 * 최종 실패 시 **반드시 reject** 한다. `onerror` 에서 `resolve()` 하면 실패가 성공으로
 * 위장되어 상위 try/catch 가 무력화되고, 앱은 한참 뒤 엉뚱한 곳(미등록 핸들러 등)에서
 * 죽는다 — 그것이 이 유틸이 도입된 계기다.
 *
 * `<script>` 의 `onerror` 는 실패 사유를 주지 않으므로(404 인지 네트워크 유실인지 구분
 * 불가) **모든 실패를 재시도 대상**으로 본다. 404 라면 3회 시도 후 reject 되어 명시적
 * 에러로 끝나므로 안전하다.
 *
 * 재시도 전 기존 element 를 제거하고 새로 만든다 — 남겨두면 IIFE 번들이 두 번 실행되어
 * 핸들러가 중복 등록된다.
 *
 * @param url 스크립트 URL
 * @param attrs script element 에 부여할 속성 (id 등)
 * @param options 재시도 옵션
 * @return Promise<void> 로드 성공 시 resolve
 * @throws Error 모든 시도가 실패한 경우
 * @since engine-v1.53.0
 */
export async function loadScriptWithRetry(
    url: string,
    attrs: Record<string, string> = {},
    options: RetryOptions = {}
): Promise<void> {
    const {
        retries = DEFAULT_RETRIES,
        baseDelayMs = DEFAULT_BASE_DELAY_MS,
        maxDelayMs = DEFAULT_MAX_DELAY_MS,
        label = url,
    } = options;

    const maxAttempts = retries + 1;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        try {
            await loadScriptOnce(url, attrs);
            return;
        } catch (error) {
            // 실패한 element 를 제거한다 (IIFE 중복 실행 방지 + 다음 시도의 id 충돌 방지)
            if (attrs.id) {
                document.getElementById(attrs.id)?.remove();
            }

            if (isDocumentUnloading()) {
                logger.warn(`Document unloading, aborting script retries: ${label}`);
                throw error;
            }

            const isLastAttempt = attempt === maxAttempts - 1;
            if (isLastAttempt) {
                logger.warn(`All ${maxAttempts} script attempts failed: ${label}`);
                throw error;
            }

            const waitMs = computeBackoffDelay(attempt, baseDelayMs, maxDelayMs);
            logger.warn(
                `Script load failed (attempt ${attempt + 1}/${maxAttempts}), retrying in ${waitMs}ms: ${label}`
            );
            await delay(waitMs);
        }
    }
}

/**
 * `<script>` 1회 로드를 시도합니다 (재시도 없음).
 *
 * @param url 스크립트 URL
 * @param attrs script element 속성
 * @return Promise<void> 로드 성공 시 resolve, 실패 시 reject
 * @since engine-v1.53.0
 */
function loadScriptOnce(url: string, attrs: Record<string, string>): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.async = false; // 번들 내부 물리 순서로 실행 보장

        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'id') {
                script.id = value;
            } else {
                script.setAttribute(key, value);
            }
        }

        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${url}`));

        document.head.appendChild(script);
    });
}
