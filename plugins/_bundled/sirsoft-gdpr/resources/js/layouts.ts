/**
 * GDPR 레이아웃 확장 부트스트랩
 *
 * G7 코어가 `resources/extensions/*.json` 파일들을 자동 발견·등록하므로
 * 별도의 명시적 등록 코드는 필요 없다. 본 모듈은:
 *
 * 1. 페이지 로드 직후 `/plugins/sirsoft-gdpr/settings` 호출하여 banner_enabled 토글 확인
 * 2. `/plugins/sirsoft-gdpr/consent/cookie/status` 호출하여 카테고리별 동의 상태 페치
 *    (회원/게스트 통합 — 인증 불필요. 회원은 sanctum 토큰이 있으면 자동 적용)
 *
 * banner_enabled 가 단일 토글 (쿠키 배너 노출) — ON 시 배너 노출 + 동의 전 외부 추적
 * 자동 차단 + 마이페이지 동의 관리 카드 일괄 활성. 차단 별도 토글 없음 (위반 조합 구조적 차단).
 *
 * 마이페이지 동의 카드(F-04)는 GDPR Art.7(3) 대칭성 의무에 따라 회원에게 동의 데이터가 있을
 * 때만 노출 (빈 카드 노출 방지). 가드 조건은 `mypage_privacy_tab.json` 의 `if` 속성에 정의.
 *
 * G7Core.api 가 로드된 환경에서는 G7Core.api.get() 사용 (auth/devtools/locale 헤더 자동),
 * 미로드 환경(부트 초기 등)에서는 fetch 로 fallback.
 *
 * @module sirsoft-gdpr/layouts
 */

export interface GdprPublicSettings {
    cookie_policy_version: string;
    /**
     * 쿠키 배너 노출 단일 토글. ON 시 배너 + 자동 차단 + 마이페이지 카드 일괄 활성.
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
 * G7Core.api 또는 fetch 로 GET 호출.
 *
 * @param path API 경로 (G7Core.api 는 `/api` 자동 prefix, fetch 는 `/api/...` 명시)
 * @return JSON 데이터 또는 null
 */
async function getJson<T>(path: string): Promise<T | null> {
    const api = resolveG7Api();
    if (api) {
        try {
            return await api.get<T>(path);
        } catch {
            return null;
        }
    }

    // Fallback: G7Core 미로드 (부트 초기) — fetch 직접 사용
    try {
        const response = await fetch('/api' + path, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return null;
        return (await response.json()) as T;
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
 * 현재 방문자(회원/게스트)의 카테고리별 쿠키 동의 상태를 페치합니다.
 *
 * 자동 차단 엔진(blocker) 의 초기 캐시 주입용. 응답 키는 cookie_ 접두사가 제거된
 * 카테고리 키 (예: 'analytics', 'marketing'). 정책 버전이 일치하지 않거나 동의가
 * 없으면 빈 객체 반환.
 *
 * needs_renewal=true 면 옛 정책 동의가 신정책에 그대로 쓰이는 상태 — 차단 엔진이
 * 필수 외 모든 카테고리 강제 false 처리 (보수적 차단, GDPR Art.6 강화).
 * current_policy_version 은 배너 사유 안내문 ("버전 X 로 변경") 용.
 *
 * 인증 불필요 — 회원이 sanctum 토큰을 가지고 있으면 자동으로 user_id 기반 응답.
 *
 * @return 동의 스냅샷 (categories + needs_renewal + current_policy_version), 또는 페치 실패 시 null
 */
export interface FetchedConsentSnapshot {
    categories: Record<string, boolean>;
    needs_renewal: boolean;
    current_policy_version: string;
}

export async function fetchConsentSnapshot(): Promise<FetchedConsentSnapshot | null> {
    const json = await getJson<{
        data?: {
            consents?: Record<string, boolean>;
            needs_renewal?: boolean;
            current_policy_version?: string;
        };
    }>('/plugins/sirsoft-gdpr/consent/cookie/status');

    const consents = json?.data?.consents;
    if (consents === undefined || consents === null) return null;

    return {
        categories: consents,
        needs_renewal: json?.data?.needs_renewal === true,
        current_policy_version: String(json?.data?.current_policy_version ?? ''),
    };
}

/**
 * 캐시 클리어 — 테스트용.
 */
export function clearSettingsCache(): void {
    cachedSettings = null;
}
