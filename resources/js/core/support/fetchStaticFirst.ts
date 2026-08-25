/**
 * 정적 게시(bake) 우선 fetch 헬퍼 (#122).
 *
 * 부트스트랩 리소스(routes/lang/components)는 정적 게시본(`/build/ext/{v}/…`)을
 * 먼저 시도하고, 응답이 `!ok`(부분 게시·GC 직후 404 포함)이거나 네트워크 실패면
 * **즉시** 종전 API URL 로 폴백한다. 폴백은 관측 가능해야 자가 치유 실패를
 * 발견할 수 있으므로 warn 1줄을 남긴다 (조용한 폴백 금지).
 *
 * legacy 측은 `fetchWithRetry` 를 재사용해 종전의 네트워크 복원력(#463)을 유지한다.
 *
 * @since engine-v1.61.0
 */

import { fetchWithRetry, type RetryOptions } from '../template-engine/networkResilience';

/**
 * 정적 URL 우선 + legacy API 폴백 fetch.
 *
 * @param staticUrl 정적 게시 URL (null 이면 legacy 직행 — staticBase 미주입)
 * @param legacyUrl 종전 API URL
 * @param options legacy 측 재시도 옵션 + fetch init (정적 측은 init 만 사용)
 * @returns HTTP 응답 (legacy 측은 4xx/5xx 포함 — 호출부의 기존 분기 보존)
 */
export async function fetchStaticFirst(
    staticUrl: string | null,
    legacyUrl: string,
    options: RetryOptions & { init?: RequestInit } = {}
): Promise<Response> {
    if (!staticUrl) {
        return fetchWithRetry(legacyUrl, options);
    }

    try {
        const response = await fetch(staticUrl, options.init);

        if (response.ok) {
            return response;
        }

        // 디버그 게이트 없는 console.warn — 폴백은 자가 치유 실패를 발견할 유일한
        // 신호라 프로덕션 콘솔에서도 보여야 한다 (bootstrap 인라인 재시도와 동일 사상)
        console.warn(
            `[fetchStaticFirst] Static fast path miss (${response.status}) — falling back to API: ${staticUrl}`
        );
    } catch (error) {
        console.warn(
            `[fetchStaticFirst] Static fast path fetch failed — falling back to API: ${staticUrl}`,
            error
        );
    }

    return fetchWithRetry(legacyUrl, options);
}
