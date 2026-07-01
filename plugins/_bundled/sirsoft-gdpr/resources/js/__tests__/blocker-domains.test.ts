/**
 * 도메인 매칭 + 카테고리 판정 단위 테스트.
 *
 * 카탈로그 합집합 함수(`mergeBlockedDomains`)는 자동 차단 단일 개념 통합과 함께
 * 제거되었습니다. 카탈로그 도메인은 신규 설치 시 `blocked_domains` 기본값으로
 * 채워지며, 운영자가 입력한 도메인만 차단 엔진에서 사용됩니다.
 */

import { describe, expect, it, beforeEach } from 'vitest';
import { matchesDomain, categorizeUrl, DEFAULT_BLOCKED_DOMAINS } from '../blocker-domains';

describe('matchesDomain', () => {
    it('정확 매칭 — 동일 호스트만 매칭', () => {
        expect(matchesDomain('google-analytics.com', 'google-analytics.com')).toBe(true);
        expect(matchesDomain('www.google-analytics.com', 'google-analytics.com')).toBe(false);
        expect(matchesDomain('example.com', 'google-analytics.com')).toBe(false);
    });

    it('와일드카드 매칭 — 서브도메인만 매칭, base 자체는 미매칭', () => {
        expect(matchesDomain('static.hotjar.com', '*.hotjar.com')).toBe(true);
        expect(matchesDomain('vars.hotjar.com', '*.hotjar.com')).toBe(true);
        // base 자체는 별도 정확 매칭 추가 필요
        expect(matchesDomain('hotjar.com', '*.hotjar.com')).toBe(false);
        expect(matchesDomain('example.com', '*.hotjar.com')).toBe(false);
    });

    it('대소문자 무관 매칭', () => {
        expect(matchesDomain('Google-Analytics.COM', 'google-analytics.com')).toBe(true);
        expect(matchesDomain('STATIC.HOTJAR.COM', '*.hotjar.com')).toBe(true);
    });
});

describe('categorizeUrl', () => {
    const patterns = {
        analytics: ['google-analytics.com', '*.hotjar.com'],
        marketing: ['facebook.net'],
        necessary: ['session.example.com'], // skip 대상이라 이 패턴은 무시되어야 함
    } as const;

    beforeEach(() => {
        // jsdom 의 window.location.hostname 을 own-site 로 가정
        Object.defineProperty(window, 'location', {
            value: { hostname: 'mysite.test', href: 'https://mysite.test/' },
            writable: true,
        });
    });

    it('http(s) 외부 URL — 카테고리 판정', () => {
        expect(categorizeUrl('https://www.google-analytics.com/analytics.js', patterns)).toBe(null);
        expect(categorizeUrl('https://google-analytics.com/analytics.js', patterns)).toBe('analytics');
        expect(categorizeUrl('https://static.hotjar.com/c/hotjar.js', patterns)).toBe('analytics');
        expect(categorizeUrl('https://facebook.net/tr', patterns)).toBe('marketing');
    });

    it('잘못된 URL — null 반환', () => {
        expect(categorizeUrl('not a url at all !!!', patterns)).toBe(null);
    });

    it('data:/blob: URI — 차단 대상 외 (null)', () => {
        expect(categorizeUrl('data:image/png;base64,abcd', patterns)).toBe(null);
        expect(categorizeUrl('blob:https://mysite.test/abcd', patterns)).toBe(null);
        expect(categorizeUrl('about:blank', patterns)).toBe(null);
    });

    it('동일 origin — 차단 대상 외 (null)', () => {
        expect(categorizeUrl('https://mysite.test/js/site.js', patterns)).toBe(null);
        expect(categorizeUrl('/js/relative.js', patterns)).toBe(null);
    });

    it('necessary 카테고리 — 매칭 자체를 skip', () => {
        // session.example.com 은 necessary 패턴에 있지만 카테고리가 necessary 라 skip
        expect(categorizeUrl('https://session.example.com/x.js', patterns)).toBe(null);
    });

    it('빈 패턴 — 항상 null', () => {
        expect(categorizeUrl('https://google-analytics.com/x.js', {})).toBe(null);
    });
});

describe('DEFAULT_BLOCKED_DOMAINS — 시드 데이터 정합성', () => {
    it('functional / analytics / marketing 카테고리가 정의되어 있고 도메인 1개 이상 포함', () => {
        // 카탈로그는 신규 설치 시 blocked_domains 기본값과 관리자 UI 추천 옵션의 SSoT.
        // BE 측 GdprSettingsController::DEFAULT_BLOCKED_DOMAINS_CATALOG 와 동일하게 유지.
        // Phase 1: functional 카테고리 추가 (빈 배열 — Phase 2 에서 외부 functional 도구 도메인 채움)
        expect(DEFAULT_BLOCKED_DOMAINS.functional).toBeDefined();
        expect(DEFAULT_BLOCKED_DOMAINS.analytics).toBeDefined();
        expect(DEFAULT_BLOCKED_DOMAINS.marketing).toBeDefined();
        expect(DEFAULT_BLOCKED_DOMAINS.analytics.length).toBeGreaterThan(0);
        expect(DEFAULT_BLOCKED_DOMAINS.marketing.length).toBeGreaterThan(0);
    });

    it('Phase 1: functional 키는 빈 배열로 초기화 (Phase 2 에서 채움)', () => {
        expect(DEFAULT_BLOCKED_DOMAINS.functional).toEqual([]);
    });

    it('대표 도메인 — Google Analytics 와 Meta Pixel 포함', () => {
        expect(DEFAULT_BLOCKED_DOMAINS.analytics).toContain('google-analytics.com');
        expect(DEFAULT_BLOCKED_DOMAINS.marketing).toContain('facebook.net');
    });

    it('Phase 1: functional 카테고리 매칭은 도메인 등록 시 동작', () => {
        // 운영자가 functional 도메인을 등록하면 categorizeUrl 이 functional 반환
        const patterns = {
            functional: ['*.crisp.chat', 'widget.intercom.io'],
            analytics: ['google-analytics.com'],
            marketing: ['facebook.net'],
        };
        expect(categorizeUrl('https://client.crisp.chat/widget.js', patterns)).toBe('functional');
        expect(categorizeUrl('https://widget.intercom.io/widget/abc.js', patterns)).toBe('functional');
    });
});
