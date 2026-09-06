/**
 * 동적 스크립트 주입 출처 정책 (프론트측 SSoT).
 *
 * 브라우저에 새 `<script>` 를 만들어 붙이는 모든 경로는 이 모듈의 판정을 경유한다.
 * 레이아웃 `scripts[]`(TemplateApp) 뿐 아니라 `loadScript` 액션 · 확장 핸들러 재로드 ·
 * 편집기 프리뷰 · `G7Core.asset.loadScript` 공개 seam 이 같은 판정을 쓴다.
 *
 * ## 허용 규칙
 *
 * 1. `/` 로 시작하는 same-origin 절대 경로.
 * 2. 확장이 manifest(`trusted_script_hosts`)로 선언한 신뢰 호스트에 속한 외부 스크립트
 *    — 코어가 집계해 `window.G7Config.trustedScriptHosts` 로 노출한다.
 *
 * 그 외(`//` protocol-relative · scheme 포함 외부 origin)는 미선언 원격 코드 로드 경로다.
 *
 * ## 3층 동형
 *
 * 저장측 `App\Rules\SafeLayoutExpressions::normalizeForOriginCheck` · 정적 검사
 * `layout-scripts-src-same-origin` 과 **같은 정규화**를 공유해야 한다. 한 층만 바뀌면
 * 그 차집합이 그대로 우회로가 된다 (KVE-2026-1915 B-2).
 *
 * @since engine-v1.64.0
 */

/**
 * origin 판정 전에 스크립트 src 를 브라우저 URL 파서와 동일하게 정규화합니다.
 *
 * 문자열 접두 검사만으로는 authority 우회를 막지 못합니다. 브라우저(WHATWG URL)는
 * 파싱 전에 ASCII tab·개행을 제거하고, special scheme(http/https)에서 백슬래시를
 * 슬래시와 동등하게 처리하기 때문입니다. 그래서 `/\/evil.com/x.js` ·
 * `/{tab}/evil.com/x.js` 는 `//` 로 시작하지 않고 scheme 도 없는데 실제로는
 * `https://evil.com/x.js` 로 해석되어 원격 스크립트가 로드됩니다.
 *
 * 정규화 후 판정하면 경로 중간의 백슬래시·탭(`/js/a\b.js`)은 authority 를 만들지
 * 않으므로 그대로 same-origin 으로 통과합니다(과차단 없음).
 *
 * @param src 원본 src 문자열
 * @returns 정규화된 src
 */
export function normalizeScriptSrcForOriginCheck(src: string): string {
    // ASCII tab / LF / CR 제거 (브라우저 파서가 파싱 전에 제거하는 문자)
    // → 백슬래시를 슬래시로 (special scheme 에서 등가)
    const slashed = src.replace(/[\t\n\r]/g, '').replace(/\\/g, '/');

    // 선행 슬래시가 3개 이상이어도 브라우저는 authority 시작으로 접는다
    // (`///host/x` ≡ `//host/x`, `https:///host/x` ≡ `https://host/x`).
    // 경로 중간의 연속 슬래시(`/js//a.js`)는 브라우저도 경로로 두므로 건드리지 않는다.
    return slashed.replace(/^([a-z][a-z0-9+.\-]*:)?\/{2,}/i, '$1//');
}

/**
 * 스크립트 src 에서 http(s) 호스트명을 추출합니다.
 *
 * `//host/...`(protocol-relative)·`https://host/...` 를 처리하며, http/https 가 아닌
 * scheme(`javascript:`·`data:` 등)은 null 을 반환해 신뢰 호스트 판정 대상에서 제외합니다.
 *
 * @param src 스크립트 src 문자열 (정규화된 값을 넘길 것)
 * @param baseOrigin 상대 경로 해석 기준 origin (기본: 현재 문서 origin)
 * @returns 소문자 호스트명 (판정 불가 시 null)
 */
export function extractScriptHost(src: string, baseOrigin?: string): string | null {
    try {
        // protocol/origin 이 비어 있을 수 있다 (테스트 하네스가 location 을 교체한 경우 등).
        // 그때 빈 문자열을 그대로 이어 붙이면 `//host` 가 파싱 불가가 되어 호스트 추출이
        // null 로 떨어지고, protocol-relative 로 적은 **신뢰 호스트가 조용히 차단**된다.
        const protocol =
            (typeof window !== 'undefined' && window.location?.protocol) || 'https:';
        const base =
            baseOrigin ||
            (typeof window !== 'undefined' ? window.location?.origin : undefined) ||
            'https://localhost';

        const normalized = src.startsWith('//') ? `${protocol}${src}` : src;
        const url = new URL(normalized, base);

        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return null;
        }

        return url.hostname.toLowerCase();
    } catch {
        return null;
    }
}

/**
 * 코어가 집계해 노출한 신뢰 외부 스크립트 호스트 목록을 반환합니다.
 *
 * @returns 소문자 호스트명 배열 (window.G7Config.trustedScriptHosts)
 */
export function getTrustedScriptHosts(): string[] {
    const hosts =
        typeof window !== 'undefined'
            ? (window as any).G7Config?.trustedScriptHosts
            : undefined;

    return Array.isArray(hosts)
        ? hosts.map((host: unknown) => String(host).toLowerCase())
        : [];
}

/**
 * 스크립트 src 가 주입 허용 대상인지 판정합니다.
 *
 * @param src 스크립트 src 문자열
 * @param trustedHosts 신뢰 호스트 목록 (생략 시 `getTrustedScriptHosts()`)
 * @returns 주입 허용이면 true
 */
export function isAllowedScriptSrc(src: string, trustedHosts?: readonly string[]): boolean {
    if (typeof src !== 'string') {
        return false;
    }

    const trimmed = src.trim();

    if (trimmed === '') {
        return false;
    }

    // 접두 검사 전에 브라우저 URL 파서와 동일하게 정규화한다
    const normalized = normalizeScriptSrcForOriginCheck(trimmed);

    const isProtocolRelative = normalized.startsWith('//');
    const hasScheme = /^[a-z][a-z0-9+.-]*:/i.test(normalized);

    // same-origin path-only 절대 경로 (`/api/...`) — 항상 허용
    if (!isProtocolRelative && !hasScheme && normalized.startsWith('/')) {
        return true;
    }

    // 외부 origin — 확장이 선언한 신뢰 호스트만 허용
    const host = extractScriptHost(normalized);
    const hosts = trustedHosts ?? getTrustedScriptHosts();

    return host !== null && hosts.map(h => String(h).toLowerCase()).includes(host);
}
