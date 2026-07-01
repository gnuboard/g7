/**
 * GDPR 1st-party Cookie 가로채기 (cookieInterceptor)
 *
 * `document.cookie` setter 를 가로채 functional 카테고리 미동의 상태에서
 * 신규 쿠키 쓰기를 차단. EDPB Guidelines 2/2023 §16 "동의 전 사전 차단" 충족.
 *
 * 본 모듈은 **클라이언트 측 쓰기** 만 가로챔. 서버 Set-Cookie 응답은
 * CookieConsentMiddleware (서버) 가 처리. 두 라인이 함께 동작해야 완전.
 *
 * 게이팅 규칙 (Phase 2 단순화 — 4단계):
 *   1. cleared cookie (Max-Age=0 / expires 과거) → 항상 허용 (§117 충돌 회피)
 *   2. strictly necessary allowlist 매칭 → 허용
 *   3. functional 동의 → 허용
 *   4. user-initiated 면제 (WP29 §3.6, 항상 활성) → 사용자 인터랙션 직후 허용
 *   5. 그 외 → 차단
 *
 * "운영자 등록 표" 는 제거됨 — 모든 비-필수 cookie 는 동일 규칙 적용.
 *
 * @module sirsoft-gdpr/cookieInterceptor
 */

import { isUserInitiated } from './userInitiatedTracker';

/**
 * 인터셉터 설정.
 *
 * @property functionalConsented functional 카테고리 동의 여부
 * @property necessaryAllowlist strictly necessary 쿠키 이름 (서버 cookie 와 별개 — 클라이언트 쓰기 화이트리스트)
 */
export interface CookieInterceptorConfig {
    functionalConsented: boolean;
    necessaryAllowlist: readonly string[];
}

/**
 * 정적 strictly necessary 쿠키 화이트리스트 (클라이언트 쓰기).
 *
 * 일반적으로 코어가 쓰는 쿠키는 모두 서버 Set-Cookie 로 발급되므로 클라이언트 쓰기는
 * 거의 없음. 폼 보호용 XSRF-TOKEN refresh, 세션 ID 등 일부만 화이트리스트.
 */
export const DEFAULT_NECESSARY_COOKIE_ALLOWLIST: readonly string[] = [
    'XSRF-TOKEN',
    'laravel_session',
    'laravel_maintenance',
    'gdpr_session',
];

let installed = false;
let originalCookieDescriptor: PropertyDescriptor | null = null;
let config: CookieInterceptorConfig = {
    functionalConsented: false,
    necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
};

/**
 * "name=value; Path=/; ..." 형식의 cookie 문자열에서 name 만 추출합니다.
 *
 * @param  raw  document.cookie setter 에 전달된 raw 문자열
 * @return 쿠키 이름 (실패 시 null)
 */
function extractCookieName(raw: string): string | null {
    const eqIdx = raw.indexOf('=');
    if (eqIdx <= 0) {
        return null;
    }
    return raw.substring(0, eqIdx).trim();
}

/**
 * 이름이 strictly necessary 화이트리스트에 포함되는지 검사합니다.
 *
 * @param  name  쿠키 이름
 * @return 매칭 여부
 */
function matchesNecessary(name: string): boolean {
    return config.necessaryAllowlist.includes(name);
}

/**
 * cookie 쓰기가 허용되는지 판정합니다.
 *
 * 파기 cookie (값 비어있음 + expires 또는 Max-Age 가 과거/0) 는 항상 통과 —
 * EDPB §117 (철회 즉시 파기) 와 인터셉터의 §16 (사전 차단) 가 충돌하지 않도록.
 * cleaner 가 발송하는 Max-Age=0 cookie 도 본 분기로 통과.
 *
 * @param  rawValue  document.cookie setter 입력
 * @return 허용 여부
 */
export function isCookieAllowed(rawValue: string): boolean {
    const name = extractCookieName(rawValue);
    if (name === null) {
        // 파싱 불가 → 보수적 차단 (잘못된 cookie 문자열은 어차피 브라우저가 무시).
        return false;
    }

    // 파기 cookie 패턴 — Max-Age=0 / Max-Age=-N / expires=past
    if (isClearingCookie(rawValue)) {
        return true;
    }

    if (matchesNecessary(name)) {
        return true;
    }

    if (config.functionalConsented) {
        return true;
    }

    // user-initiated 면제 (WP29 §3.6) — 사용자가 직접 트리거한 일회성 설정은 동의 없이 허용.
    if (isUserInitiated()) {
        return true;
    }

    return false;
}

/**
 * cookie 문자열이 파기 의도 (Max-Age=0 / 음수 또는 expires 과거) 인지 판정.
 *
 * 표준 cookie 삭제 패턴 — 브라우저가 즉시 해당 cookie 를 파기. EDPB §117 (철회 즉시 파기)
 * 의 필수 메커니즘이므로 인터셉터가 항상 통과시켜야 함.
 *
 * @param  rawValue  document.cookie setter 입력
 * @return 파기 cookie 여부
 */
function isClearingCookie(rawValue: string): boolean {
    const lower = rawValue.toLowerCase();

    // Max-Age=0 또는 음수
    const maxAgeMatch = lower.match(/max-age\s*=\s*(-?\d+)/);
    if (maxAgeMatch && parseInt(maxAgeMatch[1], 10) <= 0) {
        return true;
    }

    // expires=과거 — 1970 휴리스틱
    if (lower.includes('expires=thu, 01 jan 1970') || lower.includes('expires=thu, 01-jan-1970')) {
        return true;
    }

    return false;
}

/**
 * 인터셉터를 설치합니다 — Document.prototype 의 cookie setter 를 가로채기.
 *
 * @param  initialConfig  초기 설정
 * @return void
 */
export function installCookieInterceptor(initialConfig: CookieInterceptorConfig): void {
    if (installed) {
        return;
    }
    installed = true;
    config = initialConfig;

    originalCookieDescriptor = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie') ?? null;
    if (!originalCookieDescriptor || !originalCookieDescriptor.set || !originalCookieDescriptor.get) {
        // 환경 미지원 — 폴백 없이 종료 (jsdom 일부 버전).
        installed = false;
        return;
    }

    const originalSet = originalCookieDescriptor.set;
    const originalGet = originalCookieDescriptor.get;

    Object.defineProperty(Document.prototype, 'cookie', {
        configurable: true,
        get(this: Document): string {
            return originalGet.call(this);
        },
        set(this: Document, value: string) {
            if (!isCookieAllowed(value)) {
                return;
            }
            originalSet.call(this, value);
        },
    });
}

/**
 * 인터셉터 설정을 갱신합니다.
 *
 * @param  newConfig  갱신할 설정
 * @return void
 */
export function updateCookieInterceptorConfig(newConfig: CookieInterceptorConfig): void {
    config = newConfig;
}

/**
 * 인터셉터를 해제합니다 (테스트 / cleanup).
 *
 * @return void
 */
export function uninstallCookieInterceptor(): void {
    if (!installed) {
        return;
    }
    if (originalCookieDescriptor) {
        Object.defineProperty(Document.prototype, 'cookie', originalCookieDescriptor);
    }
    originalCookieDescriptor = null;
    config = {
        functionalConsented: false,
        necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
    };
    installed = false;
}