/**
 * GDPR 도메인 기반 차단 매칭 + 기본 카탈로그
 *
 * 운영자가 카테고리별 차단 도메인 목록을 관리하면 `data-gdpr-category` 속성
 * 없이도 외부 추적 리소스를 동의 전 차단합니다. 매칭 알고리즘은 endsWith 기반
 * 으로 정규식을 쓰지 않아 일반 페이지 영향이 미미합니다.
 *
 * @module sirsoft-gdpr/blocker-domains
 */

/**
 * 호스트가 차단 도메인 패턴과 매칭되는지 확인합니다.
 *
 * 패턴 형식:
 * - 정확 매칭: `google-analytics.com` → `google-analytics.com` 만 매칭
 * - 와일드카드: `*.hotjar.com` → `static.hotjar.com` 등 매칭. `hotjar.com`
 *   자체는 매칭 안 됨 (정확 매칭으로 별도 추가 필요)
 *
 * @param host 검사 대상 호스트
 * @param pattern 차단 패턴
 * @return 매칭 여부
 */
export function matchesDomain(host: string, pattern: string): boolean {
    const lowerHost = host.toLowerCase();
    const lowerPattern = pattern.toLowerCase();

    if (lowerPattern.startsWith('*.')) {
        const suffix = lowerPattern.slice(1); // ".hotjar.com"
        return lowerHost.endsWith(suffix) && lowerHost !== suffix.slice(1);
    }

    return lowerHost === lowerPattern;
}

/**
 * URL 의 호스트가 카테고리별 차단 목록에 매칭되는지 확인합니다.
 *
 * 동일 origin 은 차단 대상 외 (운영자 자체 자산 보호). `data:`/`blob:` 등 비
 * HTTP 스킴은 외부 호스트가 없으므로 차단 대상 외. `necessary` 카테고리는
 * 매칭 자체를 skip 합니다 (필수 쿠키 보호).
 *
 * @param url 검사 대상 URL (절대 또는 상대)
 * @param patterns 카테고리 → 도메인 패턴 배열
 * @return 매칭된 카테고리, 매칭 없으면 null
 */
export function categorizeUrl(
    url: string,
    patterns: Record<string, readonly string[]>,
): string | null {
    if (typeof window === 'undefined' || typeof URL === 'undefined') return null;

    let host: string;
    let protocol: string;
    try {
        const parsed = new URL(url, window.location.href);
        protocol = parsed.protocol;
        host = parsed.hostname;
    } catch {
        return null;
    }

    if (protocol !== 'http:' && protocol !== 'https:') return null;
    if (host === '' || host === window.location.hostname) return null;

    for (const [category, domains] of Object.entries(patterns)) {
        if (category === 'necessary') continue;
        for (const domain of domains) {
            if (matchesDomain(host, domain)) return category;
        }
    }

    return null;
}

/**
 * 기본 차단 도메인 카탈로그 — 운영자가 `blocked_domains_default_catalog = true`
 * 로 켜야 적용. 운영자 커스텀 목록과 합집합으로 병합됩니다.
 *
 * 카탈로그 갱신은 코드 PR 로만 가능. 사용자별 변경 불가.
 */
export const DEFAULT_BLOCKED_DOMAINS: Record<string, readonly string[]> = {
    // functional 카테고리는 자체 호스팅 functional 만 처리 — 외부 도구 도메인은 Phase 2 에서 보강.
    // 현재 빈 배열로 두어 운영자가 admin UI 의 functional 차단 도메인 섹션을 사용할 수 있도록 함.
    functional: [],
    analytics: [
        // Google Analytics 계열
        'google-analytics.com',
        '*.google-analytics.com',
        'googletagmanager.com',
        '*.googletagmanager.com',
        'ssl.google-analytics.com',
        // 행동 분석
        '*.hotjar.com',
        'static.hotjar.com',
        '*.mixpanel.com',
        'cdn.mxpnl.com',
        '*.amplitude.com',
        'cdn.amplitude.com',
        '*.segment.io',
        '*.segment.com',
        // 한국 분석 도구
        'wcs.naver.net',
        'wcs.naver.com',
        '*.beusable.net',
    ],
    marketing: [
        // Meta (Facebook)
        'facebook.net',
        'connect.facebook.net',
        'facebook.com',
        '*.facebook.com',
        // Google Ads
        'doubleclick.net',
        '*.doubleclick.net',
        'googleadservices.com',
        'googlesyndication.com',
        'ads.google.com',
        // 광고 네트워크
        '*.criteo.com',
        'static.criteo.net',
        '*.adnxs.com',
        '*.taboola.com',
        'cdn.taboola.com',
        '*.outbrain.com',
        // 한국 광고 네트워크
        '*.kakao.com',
        'analytics.ad.daum.net',
        // SNS 임베드
        'platform.twitter.com',
        '*.twitter.com',
        'platform.linkedin.com',
        '*.linkedin.com',
    ],
};

// `mergeBlockedDomains()` 는 카탈로그 토글이 단일 개념으로 통합되면서 제거되었습니다.
// 카탈로그 도메인은 신규 설치 시 `blocked_domains` 기본값으로 채워지며, 차단 엔진은
// 운영자 입력 도메인만 사용합니다. 카탈로그 갱신 동기화는 관리자 UI 의 "카탈로그에서
// 도메인 가져오기" 링크가 클라이언트 측 Set 합집합으로 처리합니다.