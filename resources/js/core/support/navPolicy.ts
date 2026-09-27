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

    // scheme 포함(`https:`·`javascript:` 등) 은 path-only 가 아님
    if (/^[a-z][a-z0-9+.-]*:/i.test(normalized)) {
        return null;
    }

    // 절대 경로만 허용 — 상대경로·제어문자 선행 입력은 거부
    if (!normalized.startsWith('/')) {
        return null;
    }

    return trimmed;
}

/**
 * 이동 대상이 안전하지 않으면 fallback 경로를 돌려줍니다.
 *
 * @param target 이동 대상 문자열
 * @param fallback 안전하지 않을 때 쓸 경로 (호출부가 보장하는 same-origin 경로)
 * @returns 안전한 이동 경로
 */
export function resolveSafeNavPath(target: unknown, fallback: string): string {
    return sanitizeNavPath(target) ?? fallback;
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
