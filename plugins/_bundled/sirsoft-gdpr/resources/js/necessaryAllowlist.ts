/**
 * strictly necessary 허용목록 해석기 (necessaryAllowlist)
 *
 * 허용목록은 **운영자 설정**이다 (`necessary_storage_allowlist`). 이 모듈은 그 설정을
 * 인라인 페이로드에서 읽어 정규화하고, 세 소비자(storageInterceptor · cookieInterceptor ·
 * functionalCleaner)가 **같은 함수**로 판정하도록 매칭 규칙을 한 곳에 모은다.
 *
 * 목록을 소비자마다 따로 해석하면 저장소 카드는 와일드카드가 되고 쿠키 카드는 정확 일치만
 * 되는 식으로 갈라진다 — 그 어긋남은 예외도 로그도 남기지 않고 "그 항목만 안 되는" 상태로만
 * 나타난다.
 *
 * 잠금 항목(`necessary_storage_locked`)은 설정이 아니라 코드가 정한다. 운영자가 지울 수
 * 있으면 잠금이 아니기 때문이다. 판정은 언제나 **운영자 목록 ∪ 잠금 집합**이다.
 *
 * @module sirsoft-gdpr/necessaryAllowlist
 */

/**
 * 허용목록 스코프 — 저장소 두 종류 + 쿠키.
 */
export type AllowlistScope = 'localStorage' | 'sessionStorage' | 'cookie';

/**
 * 스코프 순서 고정 목록 (정규화·순회 기준).
 */
export const ALLOWLIST_SCOPES: readonly AllowlistScope[] = ['localStorage', 'sessionStorage', 'cookie'];

/**
 * 스코프별 허용 패턴 목록.
 *
 * 패턴은 운영자 표기 그대로다 — 끝에 `*` 가 붙으면 앞부분 매칭, 없으면 정확 일치.
 */
export interface NecessaryAllowlist {
    localStorage: readonly string[];
    sessionStorage: readonly string[];
    cookie: readonly string[];
}

/**
 * 인라인 페이로드를 읽지 못했을 때의 최소 폴백 — **잠금 집합만** 담는다.
 *
 * 출하 카탈로그 전체를 여기 복사하면 PHP 카탈로그와 두 벌이 되어, 지금 없애려는 드리프트를
 * 그대로 다시 만든다. 그래서 복사하지 않는다: 인라인이 오지 않은 극단 상황에서도 로그인
 * 토큰과 CSRF·동의 쿠키만은 살아 있어야 사이트가 선다.
 *
 * 세션 쿠키 이름은 서버 설정(`session.cookie`)이 정하는 런타임 값이라 여기 담을 수 없다.
 * 그 항목이 빠지는 상황에서는 세션 쿠키의 `httpOnly` 기본값이 방어한다.
 */
export const LOCKED_FALLBACK: NecessaryAllowlist = {
    localStorage: ['auth_token'],
    sessionStorage: [],
    cookie: ['XSRF-TOKEN', 'gdpr_session'],
};

/**
 * 빈 허용목록을 만듭니다.
 *
 * @return 세 스코프가 모두 빈 배열인 허용목록
 */
export function emptyAllowlist(): NecessaryAllowlist {
    return { localStorage: [], sessionStorage: [], cookie: [] };
}

/**
 * 임의 입력을 허용목록 구조로 정규화합니다.
 *
 * 객체가 아니거나 스코프가 배열이 아니면 그 스코프는 빈 배열이 된다 — 값이 문자열로 실려 온
 * 경우(설정 기본값을 `json_encode` 로 선언한 경우)도 여기서 걸러진다.
 *
 * @param  raw  인라인 페이로드에서 읽은 원시 값
 * @return 정규화된 허용목록
 */
export function normalizeAllowlist(raw: unknown): NecessaryAllowlist {
    const result = emptyAllowlist() as { -readonly [K in AllowlistScope]: string[] };

    if (raw === null || typeof raw !== 'object') {
        return result;
    }

    const source = raw as Record<string, unknown>;
    for (const scope of ALLOWLIST_SCOPES) {
        const value = source[scope];
        if (!Array.isArray(value)) {
            continue;
        }
        result[scope] = value.filter((v): v is string => typeof v === 'string' && v !== '');
    }

    return result;
}

/**
 * 두 허용목록을 스코프별 합집합으로 병합합니다.
 *
 * @param  a  왼쪽 허용목록
 * @param  b  오른쪽 허용목록
 * @return 병합된 허용목록 (중복 제거)
 */
export function mergeAllowlists(a: NecessaryAllowlist, b: NecessaryAllowlist): NecessaryAllowlist {
    const result = emptyAllowlist() as { -readonly [K in AllowlistScope]: string[] };

    for (const scope of ALLOWLIST_SCOPES) {
        result[scope] = [...new Set([...a[scope], ...b[scope]])];
    }

    return result;
}

/**
 * 허용목록이 한 항목도 없는지 판정합니다.
 *
 * @param  allowlist  허용목록
 * @return 세 스코프가 모두 비어 있으면 true
 */
export function isEmptyAllowlist(allowlist: NecessaryAllowlist): boolean {
    return ALLOWLIST_SCOPES.every((scope) => allowlist[scope].length === 0);
}

/**
 * 한 패턴이 이름에 매칭되는지 판정합니다.
 *
 * 끝에 `*` 가 붙으면 앞부분 매칭, 아니면 정확 일치. `*` 는 끝에만 의미가 있으며
 * (검증에서 그 외 위치를 거른다) 접두사가 빈 `*` 단독 표기는 전체 개방이 되므로 매칭하지 않는다.
 *
 * @param  name  검사할 키 또는 쿠키 이름
 * @param  pattern  운영자 표기 패턴
 * @return 매칭 여부
 */
export function matchesAllowlistPattern(name: string, pattern: string): boolean {
    if (pattern.endsWith('*')) {
        const prefix = pattern.slice(0, -1);
        return prefix !== '' && name.startsWith(prefix);
    }

    return pattern === name;
}

/**
 * 이름이 해당 스코프의 허용목록에 있는지 판정합니다.
 *
 * @param  name  검사할 키 또는 쿠키 이름
 * @param  scope  스코프
 * @param  allowlist  허용목록
 * @return 허용 여부
 */
export function isNecessary(name: string, scope: AllowlistScope, allowlist: NecessaryAllowlist): boolean {
    return allowlist[scope].some((pattern) => matchesAllowlistPattern(name, pattern));
}
