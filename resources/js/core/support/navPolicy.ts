/**
 * 페이지 이동(navigation) 대상 정책 (프론트측 SSoT).
 *
 * 브라우저를 다른 주소로 보내는 sink(`location.assign`/`location.replace`/`window.open`)와
 * 로그인 후 복귀 경로 해석(`AuthManager.getRedirectUrl`), 렌더러의 URL 속성 정화는
 * 이 모듈의 판정을 경유한다.
 *
 * ## 두 판정
 *
 * 1. **same-origin path 판정** (`sanitizeNavPath`) — `/` 로 시작하는 path-only 만 통과.
 *    `//host`(protocol-relative)·scheme 포함(`https:`·`javascript:` …)·상대경로는 거부한다.
 *    레이아웃 입력(`query.redirect` 등)이 그대로 흘러드는 같은 탭 이동 sink 에 쓴다.
 *    스크립트 정책(`scriptSrcPolicy`)과 달리 **신뢰 호스트 허용 분기를 두지 않는다** —
 *    같은 탭 이동은 외부 origin 으로 나갈 정당한 경로가 레이아웃 입력에 없다.
 * 2. **위험 스킴 판정** (`isDangerousScheme`) — `javascript:`/`vbscript:`/`data:` 만 거부.
 *    새 창 열기·`<a href>` 처럼 정상 외부 링크가 존재하는 자리에 쓴다(과차단 방지).
 * 3. **요청 출처 판정** (`isSameOriginRequestUrl`) — `apiCall` 처럼 인증 정보를 싣는 네트워크 sink 가
 *    Bearer·XSRF 헤더를 실을지 정한다. 교차 출처 요청 자체는 막지 않고 인증 정보만 싣지 않는다.
 *
 * ## 정규화 공유
 *
 * origin 판정 전 정규화는 `normalizeScriptSrcForOriginCheck` 를 그대로 재사용한다.
 * 브라우저 URL 파서와 다른 정규화를 따로 두면 그 차집합이 우회로가 된다(KVE-2026-1915 B-2).
 *
 * @since engine-v1.73.0 (KVE-2026-2348)
 */

import { normalizeScriptSrcForOriginCheck } from './scriptSrcPolicy';

/**
 * 문서를 실행 컨텍스트로 삼는 위험 스킴 목록.
 *
 * `blob:` 은 같은 origin 의 스크립트만 만들 수 있고 다운로드·미리보기에 정당하게 쓰이므로 포함하지 않는다.
 */
const DANGEROUS_SCHEMES = ['javascript:', 'vbscript:', 'data:'];

/** 스크립트를 직접 실행하는 스킴 (`data:` 제외 — src 자리의 이미지 인라인은 정당하다) */
const SCRIPT_SCHEMES = ['javascript:', 'vbscript:'];

/**
 * 상대 주소 판별용 가상 기준 주소 — 예약 TLD(`.invalid`)라 실제 요청 대상과 겹치지 않는다.
 * 이 기준으로 해석한 결과가 같은 출처면 입력에 스킴·호스트가 없었다는 뜻이다.
 */
const RELATIVE_PROBE_BASE = 'https://g7-relative-probe.invalid/';
const RELATIVE_PROBE_ORIGIN = 'https://g7-relative-probe.invalid';

/** 브라우저 URL 파서가 스킴 해석 전에 버리는 선행 C0 제어문자·공백 */
// eslint-disable-next-line no-control-regex
const LEADING_C0_OR_SPACE = /^[\u0000- ]+/;

/**
 * 이동 대상이 안전한 same-origin 절대 경로인지 판정합니다.
 *
 * 판정은 브라우저 URL 파서와 동일하게 정규화한 값으로 하고, 통과 시에는 **원본(trim)** 을
 * 돌려준다. 정규화는 쿼리스트링의 백슬래시까지 바꾸므로 그대로 돌려주면 정상 쿼리 값이
 * 변형된다. 원본은 브라우저가 같은 정규화를 거쳐 해석하므로 판정 결과와 어긋나지 않는다.
 *
 * @param target 이동 대상 문자열
 * @returns 안전한 same-origin 경로(원본 trim 값) 또는 null
 */
export function sanitizeNavPath(target: unknown): string | null {
    if (typeof target !== 'string') {
        return null;
    }

    const trimmed = target.trim();

    if (trimmed === '') {
        return null;
    }

    const normalized = normalizeScriptSrcForOriginCheck(trimmed);

    // protocol-relative(`//host`) 는 외부 origin 이동
    if (normalized.startsWith('//')) {
        return null;
    }

    // scheme 포함(`https:`·`javascript:` 등) 은 path-only 가 아님 — 단 이 사이트와 같은 출처의 전체 주소는
    // 경로로 바꿔 통과시킨다(서버가 알림 이동 주소를 전체 주소로 준다). 다른 출처·실행 스킴은 거부 그대로.
    if (/^[a-z][a-z0-9+.-]*:/i.test(normalized)) {
        return toSameOriginNavPath(trimmed);
    }

    // 절대 경로만 허용 — 상대경로·제어문자 선행 입력은 거부
    if (!normalized.startsWith('/')) {
        return null;
    }

    return trimmed;
}

/**
 * 같은 출처의 전체 주소(`http(s)://이 사이트/…`)를 「경로?쿼리#해시」로 바꿉니다.
 *
 * 판정은 브라우저 URL 파서(`new URL`)의 origin 비교라 사용자 정보(`https://site@evil/`)·다른 스킴·다른 포트·
 * 비슷한 호스트는 다른 출처로 떨어진다. `http:`·`https:` 전체 주소만 다루고, 스킴 없는 값(경로·상대경로·
 * `//host`)은 null — 같은 탭 sink 의 경로 판정은 {@link sanitizeNavPath} 가 한다. 링크 href 처럼 상대 주소도
 * 받아야 하는 자리는 `base` 를 넘긴다(`navigationLinks.toSameOriginPath`).
 *
 * @param target 주소 문자열
 * @param base 상대 주소 해석 기준(넘기면 스킴 없는 값도 그 기준으로 해석한다)
 * @returns 같은 출처면 경로(+쿼리·해시), 아니면 null
 * @since engine-v1.80.1
 */
export function toSameOriginNavPath(target: unknown, base?: string): string | null {
    if (typeof target !== 'string' || typeof window === 'undefined') {
        return null;
    }

    const trimmed = target.trim();
    if (trimmed === '') {
        return null;
    }
    if (base === undefined && !/^https?:/i.test(normalizeScriptSrcForOriginCheck(trimmed))) {
        return null;
    }

    let url: URL;
    try {
        url = base === undefined ? new URL(trimmed) : new URL(trimmed, base);
    } catch {
        return null;
    }

    if ((url.protocol !== 'http:' && url.protocol !== 'https:') || url.origin !== window.location.origin) {
        return null;
    }

    // `https://이 사이트//다른호스트`(백슬래시 변형 포함)의 경로는 `//다른호스트` — 경로로 돌려주면 같은 탭 sink 가
    // 프로토콜 상대 주소로 읽어 바깥으로 간다. 봇 화면 `ComponentHtmlMapper::toSameOriginLinkPath` 와 같은 거부.
    if (url.pathname.startsWith('//')) {
        return null;
    }

    return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * 같은 탭 이동 sink(openWindow `_self`·redirect·navigate 미매칭 폴백)가 갈 경로를 정합니다.
 *
 * - 주소가 비었으면 `fallback` — 「어디든 가야 하는」 자리의 기본 경로(호출부가 정한다).
 * - 안전한 같은 출처 경로(같은 출처 전체 주소 포함)면 그 경로.
 * - 주소가 있는데 거부되면(실행 스킴·다른 출처·`//host`·백슬래시 우회) `null` — 호출부는 **이동하지 않는다**.
 *   종전(engine-v1.73.0~)에는 거부된 주소도 `fallback`(`/`)으로 바꿔 첫 화면으로 문서 전체 이동했다
 *   (결정 2026-10-15, engine-v1.80.1).
 *
 * @since engine-v1.80.1
 * @param target 이동 대상
 * @param fallback 주소가 비었을 때 쓸 경로 (호출부가 보장하는 same-origin 경로)
 * @returns 이동할 경로, 거부된 주소면 null
 */
export function resolveSameTabNavPath(target: unknown, fallback: string): string | null {
    if (typeof target !== 'string' || target.trim() === '') {
        return fallback;
    }

    return sanitizeNavPath(target);
}

/**
 * navigate 의 `params.fallbackPath` 가 있을 때 갈 경로를 정합니다.
 *
 * 로그인 완료처럼 반드시 어딘가로 가야 하는 자리에서 레이아웃이 안전한 착지점을 명시한다 — 이동 주소(`path`)가
 * 비었거나 거부되면(실행 스킴·다른 출처·`//host`·상대 경로) `fallbackPath` 로 가고, 그것도 같은 판정에서 거부되면
 * null(이동하지 않음). 레이아웃이 표현식으로 주소를 직접 거르면 우회 변형을 화면마다 다시 짜야 하므로 판정을 엔진 한 곳에 둔다.
 *
 * 판정은 같은 탭 sink 의 최종 판정 {@link sanitizeNavPath} 그 자체다 — 대체 경로를 쓸지 정하는 판정과 실제 이동을
 * 거부하는 판정이 갈리면 「여기서 통과했는데 sink 에서 거부돼 이동 0」 이 생긴다(상대 경로 `admin/users` 실측).
 *
 * @param target navigate 이동 주소
 * @param fallbackPath 대체 경로
 * @returns 갈 경로, 둘 다 갈 수 없으면 null
 * @since engine-v1.80.1
 */
export function resolveNavigatePathWithFallback(target: unknown, fallbackPath: unknown): string | null {
    return sanitizeNavPath(target) ?? sanitizeNavPath(fallbackPath);
}

/**
 * URL 이 문서를 실행 컨텍스트로 삼는 위험 스킴인지 판정합니다.
 *
 * 브라우저는 스킴 해석 전에 선행·후행 C0 제어문자/공백을 버리고 ASCII tab·개행을 제거하며
 * 스킴을 대소문자 구분 없이 읽는다. `" JaVa\tScRiPt:…"` 도 같은 판정을 받도록 동일하게 정규화한다.
 *
 * @param target 검사할 URL 문자열
 * @returns 위험 스킴이면 true (문자열이 아니면 false)
 */
export function isDangerousScheme(target: unknown): boolean {
    return hasSchemePrefix(target, DANGEROUS_SCHEMES);
}

/**
 * URL 이 스크립트 실행 스킴(`javascript:`/`vbscript:`)인지 판정합니다.
 *
 * `src` 처럼 `data:` 가 이미지 인라인으로 정당하게 쓰이는 자리에서 쓴다.
 * (`<iframe src="data:…">` 는 불투명 origin 이라 현재 origin 코드 실행이 아니다.)
 *
 * @param target 검사할 URL 문자열
 * @returns 스크립트 실행 스킴이면 true
 */
export function isScriptScheme(target: unknown): boolean {
    return hasSchemePrefix(target, SCRIPT_SCHEMES);
}

/**
 * 요청 대상이 현재 문서와 같은 출처인지 판정합니다 (네트워크 sink — `fetch`).
 *
 * 인증 정보(Bearer 토큰·XSRF 헤더)를 실을지 정하는 데 쓴다. 레이아웃은 요청 대상을 표현식으로 조립할 수
 * 있어 저장측 정적 규칙이 외부 주소를 다 잡지 못하므로, 보내는 자리에서 판정해야 한다(KVE-2026-2350).
 * `fetch` 가 대상을 문서 URL 기준으로 해석하므로 같은 파서(`new URL(target, location.href)`)로 판정한다 —
 * 접두 정규식은 `/\evil.com`·`/{tab}/evil.com` 처럼 브라우저가 외부 origin 으로 읽는 변형을 놓친다.
 *
 * 먼저 가상 기준 주소로 해석해 스킴·호스트가 없는 **상대 주소**인지 본다 — 상대 주소는 문서 주소와 무관하게
 * 같은 출처다. 스킴·호스트를 가진 주소만 현재 문서의 출처와 비교하고, 비교할 출처를 알 수 없으면 교차 출처로 본다.
 *
 * @param target 요청 대상 문자열 (절대·상대 모두)
 * @returns 같은 출처면 true (문자열이 아니거나 파싱할 수 없으면 false)
 * @since engine-v1.73.0
 */
export function isSameOriginRequestUrl(target: unknown): boolean {
    if (typeof target !== 'string') {
        return false;
    }

    let resolved: URL;
    try {
        resolved = new URL(target, RELATIVE_PROBE_BASE);
    } catch {
        return false;
    }

    if (resolved.origin === RELATIVE_PROBE_ORIGIN) {
        return true;
    }

    const currentOrigin = typeof window !== 'undefined' ? window.location?.origin : undefined;

    return typeof currentOrigin === 'string' && currentOrigin !== 'null' && resolved.origin === currentOrigin;
}

/**
 * 브라우저 스킴 해석과 같은 정규화 후 접두 스킴을 대조합니다.
 *
 * @param target 검사할 URL 문자열
 * @param schemes 소문자 스킴 접두 목록
 * @returns 일치하면 true (문자열이 아니면 false)
 */
function hasSchemePrefix(target: unknown, schemes: readonly string[]): boolean {
    if (typeof target !== 'string') {
        return false;
    }

    const normalized = target
        .replace(LEADING_C0_OR_SPACE, '')
        .replace(/[\t\n\r]/g, '')
        .toLowerCase();

    return schemes.some(scheme => normalized.startsWith(scheme));
}
