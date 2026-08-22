/**
 * preblocker 회귀 테스트
 *
 * preblocker 는 GDPR 플러그인 IIFE 로드 직후 즉시 등록되어
 * HTMLScriptElement.prototype.src setter 를 가로채는 사전 차단 엔진이다.
 * settings API 응답을 기다리지 않고 보수적 차단(모든 비-necessary 차단)으로 진입하여
 * race condition (settings 응답 지연 동안 추적 스크립트 외부 로드 완료) 을 방지한다.
 *
 * 본 테스트는 jsdom 환경에서 prototype intercept 의 차단/통과/복원 동작을 검증한다.
 *
 * 우선순위:
 *   1. data-gdpr-category 속성 (운영자 명시) — origin 무관
 *   2. 외부 origin + 카탈로그 매칭 — 도메인 기반
 *   3. 그 외 — 통과 (동일 origin 자기 차단 방지 + 카탈로그 미매칭)
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    installPreblocker,
    uninstallPreblocker,
    updatePreblockerConfig,
    restorePreblockedCategory,
    type PreblockerConfig,
} from '../preblocker';

const SITE_HOST = 'mysite.test';

beforeEach(() => {
    Object.defineProperty(window, 'location', {
        value: { hostname: SITE_HOST, href: `https://${SITE_HOST}/`, origin: `https://${SITE_HOST}` },
        writable: true,
    });
    document.body.innerHTML = '';
    document.head.innerHTML = '';
});

afterEach(() => {
    uninstallPreblocker();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
});

function defaultConfig(overrides: Partial<PreblockerConfig> = {}): PreblockerConfig {
    return {
        blockedDomains: {
            analytics: [
                'googletagmanager.com',
                '*.googletagmanager.com',
                '*.hotjar.com',
                'static.hotjar.com',
            ],
            marketing: ['connect.facebook.net'],
        },
        consent: { necessary: true },
        ...overrides,
    };
}

describe('preblocker — 도메인 기반 차단 (외부 origin)', () => {
    it('카탈로그 매칭 + 동의 없음 → src 미설정, blocked 속성 보존', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';

        expect(script.getAttribute('src')).toBeNull();
        expect(script.getAttribute('data-gdpr-blocked-src')).toBe(
            'https://www.googletagmanager.com/gtag/js?id=G-DEMO',
        );
        expect(script.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
    });

    it('카탈로그 매칭 + 동의 있음 → src 설정 통과', () => {
        installPreblocker(defaultConfig({ consent: { necessary: true, analytics: true } }));

        const script = document.createElement('script');
        script.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';

        expect(script.getAttribute('src')).toBe(
            'https://www.googletagmanager.com/gtag/js?id=G-DEMO',
        );
        expect(script.getAttribute('data-gdpr-blocked-src')).toBeNull();
    });

    it('와일드카드 매칭 — *.hotjar.com → static.hotjar.com 차단', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.src = 'https://static.hotjar.com/c/hotjar-0.js';

        expect(script.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
    });

    it('카탈로그 미매칭 외부 도메인 → 통과', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.src = 'https://cdn.example.com/lib.js';

        expect(script.getAttribute('src')).toBe('https://cdn.example.com/lib.js');
        expect(script.getAttribute('data-gdpr-blocked-src')).toBeNull();
    });
});

describe('preblocker — 동일 origin 통과 (자기 차단 방지)', () => {
    it('동일 origin 절대 URL — 속성 없으면 카탈로그 매칭 무관 통과', () => {
        installPreblocker({
            blockedDomains: { analytics: [SITE_HOST] }, // 일부러 자기 도메인을 카탈로그에 넣음
            consent: { necessary: true },
        });

        const script = document.createElement('script');
        script.src = `https://${SITE_HOST}/api/plugins/sirsoft-gdpr/assets/dist/js/plugin.iife.js`;

        expect(script.getAttribute('src')).toBe(
            `https://${SITE_HOST}/api/plugins/sirsoft-gdpr/assets/dist/js/plugin.iife.js`,
        );
        expect(script.getAttribute('data-gdpr-blocked-src')).toBeNull();
    });

    it('상대 경로 (/...) — 속성 없으면 통과', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.src = '/api/templates/assets/sirsoft-basic/js/components.iife.js';

        expect(script.getAttribute('src')).toBe(
            '/api/templates/assets/sirsoft-basic/js/components.iife.js',
        );
    });

    it('프로토콜 상대 경로 (//...) 동일 host → 통과', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.src = `//${SITE_HOST}/local.js`;

        expect(script.getAttribute('src')).toBe(`//${SITE_HOST}/local.js`);
    });
});

describe('preblocker — 속성 기반 차단 (자체 호스팅 추적)', () => {
    it('동일 origin + data-gdpr-category=analytics + 동의 없음 → 차단', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.setAttribute('data-gdpr-category', 'analytics');
        script.src = `https://${SITE_HOST}/js/self-hosted-analytics.js`;

        expect(script.getAttribute('src')).toBeNull();
        expect(script.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
    });

    it('동일 origin + data-gdpr-category=analytics + 동의 있음 → 통과', () => {
        installPreblocker(defaultConfig({ consent: { necessary: true, analytics: true } }));

        const script = document.createElement('script');
        script.setAttribute('data-gdpr-category', 'analytics');
        script.src = `https://${SITE_HOST}/js/self-hosted-analytics.js`;

        expect(script.getAttribute('src')).toBe(
            `https://${SITE_HOST}/js/self-hosted-analytics.js`,
        );
    });

    it('data-gdpr-category=necessary → 동의 없음이어도 항상 통과', () => {
        installPreblocker(defaultConfig());

        const script = document.createElement('script');
        script.setAttribute('data-gdpr-category', 'necessary');
        script.src = `https://${SITE_HOST}/js/session.js`;

        expect(script.getAttribute('src')).toBe(`https://${SITE_HOST}/js/session.js`);
    });

    it('속성 우선순위 — 외부 origin + 속성=marketing 이지만 카탈로그는 analytics → marketing 으로 차단 판정', () => {
        installPreblocker(
            defaultConfig({
                consent: { necessary: true, analytics: true }, // analytics 만 동의
            }),
        );

        const script = document.createElement('script');
        script.setAttribute('data-gdpr-category', 'marketing'); // 운영자 marketing 분류
        script.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';

        // 속성이 우선 → marketing 동의 없으므로 차단
        expect(script.getAttribute('data-gdpr-blocked-category')).toBe('marketing');
    });
});

describe('preblocker — 초기 보수적 동의 상태', () => {
    it('동의 상태 미지정 시 necessary 만 허용 — 모든 추적 차단', () => {
        installPreblocker({
            blockedDomains: { analytics: ['googletagmanager.com', '*.googletagmanager.com'] },
            // consent 생략
        } as PreblockerConfig);

        const script = document.createElement('script');
        script.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';

        expect(script.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
    });
});

describe('preblocker — updateConfig (settings/consent 응답 후 동기화)', () => {
    it('updateConfig 후 새 카탈로그 적용', () => {
        installPreblocker({
            blockedDomains: { analytics: ['initial-only.test'] },
            consent: { necessary: true },
        });

        // 초기 카탈로그에 없는 도메인은 통과
        const script1 = document.createElement('script');
        script1.src = 'https://later-added.test/track.js';
        expect(script1.getAttribute('src')).toBe('https://later-added.test/track.js');

        // 갱신
        updatePreblockerConfig({
            blockedDomains: { analytics: ['later-added.test'] },
            consent: { necessary: true },
        });

        const script2 = document.createElement('script');
        script2.src = 'https://later-added.test/track.js';
        expect(script2.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
    });

    it('updateConfig 로 동의 부여 후 신규 요청 통과', () => {
        installPreblocker(defaultConfig());

        const script1 = document.createElement('script');
        script1.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';
        expect(script1.getAttribute('data-gdpr-blocked-category')).toBe('analytics');

        updatePreblockerConfig({
            blockedDomains: defaultConfig().blockedDomains,
            consent: { necessary: true, analytics: true },
        });

        const script2 = document.createElement('script');
        script2.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';
        expect(script2.getAttribute('src')).toBe(
            'https://www.googletagmanager.com/gtag/js?id=G-DEMO',
        );
    });
});

describe('preblocker — restorePreblockedCategory (동의 후 복원)', () => {
    it('차단된 요소를 새 <script> 로 교체하여 src 재설정', () => {
        installPreblocker(defaultConfig());

        const original = document.createElement('script');
        original.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';
        document.head.appendChild(original);

        expect(original.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
        expect(original.getAttribute('src')).toBeNull();

        // 동의 부여 → config 갱신 후 복원
        updatePreblockerConfig({
            blockedDomains: defaultConfig().blockedDomains,
            consent: { necessary: true, analytics: true },
        });
        restorePreblockedCategory('analytics');

        // 원본은 DOM 에서 제거, 새 요소가 같은 src 로 추가됨
        const restored = document.head.querySelector<HTMLScriptElement>(
            'script[src="https://www.googletagmanager.com/gtag/js?id=G-DEMO"]',
        );
        expect(restored).not.toBeNull();
        expect(restored?.getAttribute('data-gdpr-blocked-src')).toBeNull();
    });

    it('동의 안 받은 카테고리는 restore 호출해도 효과 없음', () => {
        installPreblocker(defaultConfig());

        const original = document.createElement('script');
        original.src = 'https://www.googletagmanager.com/gtag/js?id=G-DEMO';
        document.head.appendChild(original);

        // consent 미갱신 상태에서 restore 호출
        restorePreblockedCategory('analytics');

        // 여전히 차단 상태
        const stillBlocked = document.head.querySelector<HTMLScriptElement>(
            '[data-gdpr-blocked-category="analytics"]',
        );
        expect(stillBlocked).not.toBeNull();
        expect(stillBlocked?.getAttribute('src')).toBeNull();
    });
});

describe('preblocker — 우회 방지 (createElement 후 직접 속성 설정)', () => {
    it('속성 기반 차단 후 운영자가 카테고리 변경해도 일관 동작', () => {
        installPreblocker(defaultConfig({ consent: { necessary: true, marketing: true } }));

        const script = document.createElement('script');
        script.setAttribute('data-gdpr-category', 'analytics'); // analytics 미동의
        script.src = `https://${SITE_HOST}/js/internal.js`;
        // analytics 차단
        expect(script.getAttribute('data-gdpr-blocked-category')).toBe('analytics');
    });
});
