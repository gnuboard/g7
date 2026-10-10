/**
 * GDPR 레이아웃 확장 부트스트랩
 *
 * G7 코어가 `resources/extensions/*.json` 파일들을 자동 발견·등록하므로
 * 별도의 명시적 등록 코드는 필요 없다. 본 모듈은:
 *
 * 1. 페이지 로드 직후 `/plugins/sirsoft-gdpr/settings` 호출하여 banner_enabled 토글 확인
 *
 * 방문자의 동의 상태는 여기서 조회하지 않는다 — 코어 동의 런타임(`_global.consent`)이 조회·보관하고
 * 로그인·로그아웃 때 다시 읽는다(#639). 이 플러그인은 그 스냅샷을 소비만 한다.
 *
 * banner_enabled 가 단일 토글 (쿠키 배너 노출) — ON 시 배너 노출 + 동의 전 외부 추적
 * 자동 차단이 함께 시작됨. 차단 별도 토글 없음 (위반 조합 구조적 차단).
 *
 * 마이페이지 동의 카드(F-04)는 banner_enabled 와 무관 — GDPR Art.7(3) 대칭성 의무(철회는
 * 동의만큼 쉬워야 함)에 따라 회원에게 동의 데이터가 있으면 배너 비활성 중에도 항상 노출
 * (빈 카드 노출만 방지). 가드 조건은 `mypage_privacy_tab.g7.html` 의 `if` 속성에 정의.
 *
 * 조회는 G7Core.api.get() 만 쓴다 (auth/devtools/locale 헤더 자동). 코어는 확장 번들보다 먼저
 * `G7Core.api` 를 준비하므로(template-engine 모듈 로드 시 설정, 확장 에셋은 그 뒤) 토큰 없는 raw fetch
 * 폴백을 두지 않는다 — 폴백은 회원 요청을 게스트로 보내 다른 답을 받는다(#639 ①).
 *
 * @module sirsoft-gdpr/layouts
 */

export interface GdprPublicSettings {
    cookie_policy_version: string;
    /**
     * 쿠키 배너 노출 단일 토글. ON 시 배너 + 자동 차단이 함께 활성.
     * 마이페이지 동의 관리 카드는 이 값과 무관 (동의/철회 이력 존재 여부로만 판정).
     */
    banner_enabled: boolean;
    /**
     * 카테고리 → 차단 도메인 패턴 배열 (정확 매칭 또는 `*.example.com` 와일드카드).
     * 게스트도 차단 동작해야 하므로 공개 응답에 포함.
     */
    blocked_domains?: Record<string, string[]>;
    /**
     * 기본 카탈로그(GA/GTM/Meta Pixel 등) 자동 적용 토글. default OFF.
     */
    blocked_domains_default_catalog?: boolean;
    [key: string]: unknown;
}

interface G7ApiClient {
    get: <T = unknown>(url: string) => Promise<T>;
}

let cachedSettings: GdprPublicSettings | null = null;

/**
 * G7Core.api 클라이언트 반환 (없으면 null).
 *
 * @return G7ApiClient | null
 */
function resolveG7Api(): G7ApiClient | null {
    if (typeof window === 'undefined') return null;
    const g7Core = (window as unknown as { G7Core?: { api?: G7ApiClient } }).G7Core;
    const api = g7Core?.api;
    if (api && typeof api.get === 'function') return api;
    return null;
}

/**
 * G7Core.api 로 GET 호출 (G7Core 가 없으면 null).
 *
 * @param path API 경로 (`/api` 자동 prefix)
 * @return JSON 데이터 또는 null
 */
async function getJson<T>(path: string): Promise<T | null> {
    const api = resolveG7Api();
    if (!api) return null;

    try {
        return await api.get<T>(path);
    } catch {
        return null;
    }
}

/**
 * 공개 설정 페치 + 캐시.
 *
 * @return GdprPublicSettings
 */
export async function fetchPublicSettings(): Promise<GdprPublicSettings | null> {
    if (cachedSettings) return cachedSettings;

    const json = await getJson<{ data?: GdprPublicSettings }>('/plugins/sirsoft-gdpr/settings');
    const data = json?.data;
    if (!data) return null;

    cachedSettings = data;
    return data;
}

/**
 * 캐시 클리어 — 테스트용.
 */
export function clearSettingsCache(): void {
    cachedSettings = null;
}
