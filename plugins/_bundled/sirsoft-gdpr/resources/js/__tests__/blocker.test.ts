/**
 * 차단 엔진 통합 — 속성 기반 + 도메인 기반 분기 + 동의 변경 시 rescan.
 *
 * jsdom 환경에서 DOM 삽입 → 차단/복원 검증.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { setBlockedDomains, setCurrentConsent, startBlocker, stopBlocker } from '../blocker';

const PLACEHOLDER_CLASS = 'gdpr-blocked-embed';

beforeEach(() => {
    Object.defineProperty(window, 'location', {
        value: { hostname: 'mysite.test', href: 'https://mysite.test/' },
        writable: true,
    });
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    setBlockedDomains({});
    setCurrentConsent({});
    startBlocker();
});

afterEach(() => {
    stopBlocker();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
});

/**
 * MutationObserver microtask 처리 대기.
 */
async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * placeholder 가 카테고리로 차단되었는지 확인.
 */
function findPlaceholder(category: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(
        `.${PLACEHOLDER_CLASS}[data-gdpr-blocked-category="${category}"]`,
    );
}

describe('속성 기반 차단 (기존 회귀)', () => {
    it('data-gdpr-category=analytics 인 <script> 는 동의 전 placeholder 로 치환', async () => {
        const script = document.createElement('script');
        script.src = 'https://mysite.test/js/ga.js';
        script.setAttribute('data-gdpr-category', 'analytics');
        document.body.appendChild(script);

        await flush();

        expect(document.querySelector('script[data-gdpr-category]')).toBeNull();
        expect(findPlaceholder('analytics')).not.toBeNull();
    });

    it('속성 기반 차단된 요소는 동의 부여 시 rescan 으로 복원', async () => {
        const script = document.createElement('script');
        script.src = 'https://mysite.test/js/ga.js';
        script.setAttribute('data-gdpr-category', 'analytics');
        document.body.appendChild(script);
        await flush();
        expect(findPlaceholder('analytics')).not.toBeNull();

        setCurrentConsent({ analytics: true });
        await flush();

        expect(findPlaceholder('analytics')).toBeNull();
        expect(document.querySelector('script[data-gdpr-category]')).not.toBeNull();
    });

    it('data-gdpr-category=necessary 는 동의 전이라도 차단되지 않음', async () => {
        const script = document.createElement('script');
        script.src = 'https://mysite.test/js/session.js';
        script.setAttribute('data-gdpr-category', 'necessary');
        document.body.appendChild(script);

        await flush();

        expect(document.querySelector('script[data-gdpr-category="necessary"]')).not.toBeNull();
        expect(findPlaceholder('necessary')).toBeNull();
    });
});

describe('도메인 기반 차단 (신규)', () => {
    it('운영자 도메인 정확 매칭 — 속성 없는 외부 <script> 차단', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });

        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        document.body.appendChild(script);
        await flush();

        expect(findPlaceholder('analytics')).not.toBeNull();
    });

    it('와일드카드 매칭 — 서브도메인 외부 <script> 차단', async () => {
        setBlockedDomains({ analytics: ['*.hotjar.com'] });

        const script = document.createElement('script');
        script.src = 'https://static.hotjar.com/c/hotjar.js';
        document.body.appendChild(script);
        await flush();

        expect(findPlaceholder('analytics')).not.toBeNull();
    });

    it('<img> 트래킹 픽셀 도메인 매칭 시 차단', async () => {
        setBlockedDomains({ marketing: ['facebook.com'] });

        const img = document.createElement('img');
        img.src = 'https://facebook.com/tr?id=xxx';
        document.body.appendChild(img);
        await flush();

        expect(findPlaceholder('marketing')).not.toBeNull();
        expect(document.querySelector('img')).toBeNull();
    });

    it('<link rel="preconnect"> 도메인 매칭 시 차단', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });

        const link = document.createElement('link');
        link.rel = 'preconnect';
        link.href = 'https://google-analytics.com/';
        document.head.appendChild(link);
        await flush();

        expect(findPlaceholder('analytics')).not.toBeNull();
    });

    it('<iframe> 도메인 매칭 시 차단', async () => {
        setBlockedDomains({ marketing: ['*.facebook.com'] });

        const iframe = document.createElement('iframe');
        iframe.src = 'https://www.facebook.com/plugins/like.php';
        document.body.appendChild(iframe);
        await flush();

        expect(findPlaceholder('marketing')).not.toBeNull();
    });

    it('동일 origin <script> 는 도메인 미등록이라도 차단되지 않음', async () => {
        setBlockedDomains({ analytics: ['mysite.test'] }); // 운영자가 자체 도메인을 잘못 등록
        const script = document.createElement('script');
        script.src = 'https://mysite.test/js/site.js';
        document.body.appendChild(script);
        await flush();

        expect(document.querySelector('script')).not.toBeNull();
        expect(findPlaceholder('analytics')).toBeNull();
    });

    it('necessary 카테고리는 매칭 자체를 skip', async () => {
        setBlockedDomains({ necessary: ['session.example.com'] });
        const script = document.createElement('script');
        script.src = 'https://session.example.com/x.js';
        document.body.appendChild(script);
        await flush();

        expect(document.querySelector('script')).not.toBeNull();
    });
});

describe('속성 + 도메인 동시 매칭 시 속성 우선', () => {
    it('속성이 marketing 이고 도메인이 analytics 매칭 — marketing 으로 분류', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });

        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        script.setAttribute('data-gdpr-category', 'marketing');
        document.body.appendChild(script);
        await flush();

        // 속성 우선 → marketing 으로 차단
        expect(findPlaceholder('marketing')).not.toBeNull();
        expect(findPlaceholder('analytics')).toBeNull();
    });

    it('analytics 동의는 있지만 속성이 marketing 인 경우 — 속성 우선이라 차단 유지', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        setCurrentConsent({ analytics: true });

        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        script.setAttribute('data-gdpr-category', 'marketing');
        document.body.appendChild(script);
        await flush();

        expect(findPlaceholder('marketing')).not.toBeNull();
    });
});

describe('동의 변경 시 rescan', () => {
    it('도메인 차단된 요소도 동의 부여 시 복원', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        document.body.appendChild(script);
        await flush();
        expect(findPlaceholder('analytics')).not.toBeNull();

        setCurrentConsent({ analytics: true });
        await flush();

        expect(findPlaceholder('analytics')).toBeNull();
        expect(document.querySelector('script[src*="google-analytics.com"]')).not.toBeNull();
    });

    it('동의 철회 후 새로 삽입된 요소는 다시 차단', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        setCurrentConsent({ analytics: true });

        const first = document.createElement('script');
        first.src = 'https://google-analytics.com/analytics.js';
        document.body.appendChild(first);
        await flush();
        expect(document.querySelectorAll('script').length).toBe(1);

        // 철회
        setCurrentConsent({ analytics: false });
        await flush();
        // 기존 로드된 요소도 rescan 으로 다시 차단
        expect(findPlaceholder('analytics')).not.toBeNull();

        // 새 요소 삽입 → 차단
        const second = document.createElement('script');
        second.src = 'https://google-analytics.com/v2.js';
        document.body.appendChild(second);
        await flush();
        const placeholders = document.querySelectorAll(`.${PLACEHOLDER_CLASS}`);
        expect(placeholders.length).toBeGreaterThanOrEqual(2);
    });
});

describe('preblocker 와의 통합 — 중복 차단 회피', () => {
    it('data-gdpr-blocked-category 속성이 있는 <script> 는 MutationObserver 가 중복 처리하지 않음', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        setCurrentConsent({});

        // preblocker 가 이미 처리한 상태를 시뮬레이션 — src 는 비어있고 보존 속성만
        const script = document.createElement('script');
        script.setAttribute('data-gdpr-blocked-src', 'https://google-analytics.com/analytics.js');
        script.setAttribute('data-gdpr-blocked-category', 'analytics');
        document.body.appendChild(script);
        await flush();

        // 백업 라인이 중복 placeholder 변환을 수행하지 않아야 함 —
        // 원본 <script> 가 그대로 DOM 에 남고 placeholder 미생성
        expect(document.querySelector('script[data-gdpr-blocked-category]')).not.toBeNull();
        expect(findPlaceholder('analytics')).toBeNull();
    });

    it('preblocker 보존 속성 없으면 MutationObserver 백업 라인이 정상 차단 (preblocker 우회 케이스)', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        setCurrentConsent({});

        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        document.body.appendChild(script);
        await flush();

        // 백업 라인이 placeholder 로 변환
        expect(findPlaceholder('analytics')).not.toBeNull();
    });

    /**
     * 작업 6 (B-2) — needs_renewal=true 일 때 동의된 카테고리도 보수적 차단.
     * 옛 정책 동의가 신정책에 그대로 쓰이는 상태 — 사용자가 의사 표명할 때까지 필수 외 차단.
     */
    it('needs_renewal=true 면 analytics=true 인 옛 동의에도 SDK 차단 (보수적)', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        setCurrentConsent({ categories: { necessary: true, analytics: true }, needs_renewal: true });

        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        document.body.appendChild(script);
        await flush();

        // analytics 카테고리 동의되어 있어도 needs_renewal 때문에 차단
        expect(findPlaceholder('analytics')).not.toBeNull();
    });

    it('plain Record 형태 호출 (BC) 도 그대로 동작 — needs_renewal 미지정 시 false 로 처리', async () => {
        setBlockedDomains({ analytics: ['google-analytics.com'] });
        // 기존 호출 패턴 (DetailedConsentSnapshot 아님)
        setCurrentConsent({ necessary: true, analytics: true });

        const script = document.createElement('script');
        script.src = 'https://google-analytics.com/analytics.js';
        document.body.appendChild(script);
        await flush();

        // analytics 동의됨 + needs_renewal 미지정 (false) → 차단 안 됨
        expect(findPlaceholder('analytics')).toBeNull();
    });
});
