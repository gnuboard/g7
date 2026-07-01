/**
 * GDPR 자동 차단 엔진
 *
 * 두 가지 매칭 방식을 병행 적용합니다:
 *
 * 1. 속성 기반 — `data-gdpr-category` 속성이 부여된 `<script>` / `<iframe>`
 *    (운영자가 명시적으로 부여한 의도 — 평가 우선)
 * 2. 도메인 기반 — `<script>` / `<iframe>` / `<img>` / `<link>` 의 URL 호스트가
 *    운영자 차단 도메인 목록 또는 기본 카탈로그와 매칭
 *
 * 동의 전에는 placeholder div 로 치환하여 외부 요청 자체를 차단. 동의 부여 시
 * 원본 복원. 동일 origin 리소스는 차단 대상 외 (운영자 자체 자산 보호).
 *
 * GDPR Art.6 — 합법적 처리 근거. 동의 없는 추적 스크립트 차단으로 분석/마케팅
 * 카테고리 동의 전 GA·Meta Pixel 등이 작동하지 않도록 합니다.
 *
 * 동의 상태 SSoT: 서버 응답 (/api/plugins/sirsoft-gdpr/consent/cookie/status).
 * 부트스트랩에서 setCurrentConsent() 로 캐시 주입 + 레이아웃 JSON 의 동의 저장
 * onSuccess 시점에 `gdpr:consent-changed` 커스텀 이벤트로 캐시 갱신 + rescan.
 *
 * @module sirsoft-gdpr/blocker
 */

import { categorizeUrl } from './blocker-domains';

const BLOCKED_ATTR = 'data-gdpr-category';
const PLACEHOLDER_CLASS = 'gdpr-blocked-embed';
const ORIGINAL_DATA_ATTR = 'data-gdpr-original';

/**
 * MutationObserver SELECTOR — 도메인 매칭 위해 src/href 가진 모든 대상 태그 +
 * 기존 속성 기반 매칭 대상.
 */
const BLOCK_SELECTOR = [
    `script[${BLOCKED_ATTR}]`,
    `iframe[${BLOCKED_ATTR}]`,
    'script[src]',
    'iframe[src]',
    'img[src]',
    'link[href]',
].join(', ');

/**
 * 카테고리 키 → 동의 여부 (서버 응답 형식 그대로) 또는 needs_renewal 부가정보 포함 형태.
 *
 * BC: 기존 호출처는 plain Record 형태로 사용. 신규 호출처는 DetailedConsentSnapshot
 * 으로 needs_renewal 등 부가 정보 전달 가능. 내부 normalize 로 통일 처리.
 *
 * 예 (plain): { necessary: true, analytics: false, marketing: false }
 * 예 (detailed): { categories: { necessary: true, analytics: true }, needs_renewal: true }
 */
export type ConsentSnapshot = Record<string, boolean> | DetailedConsentSnapshot;

/**
 * 부가 정보를 포함한 동의 스냅샷.
 *
 * needs_renewal=true 면 옛 정책 동의가 신정책에 그대로 쓰이고 있는 상태 →
 * 차단 엔진은 필수 외 모든 카테고리를 false 강제 (보수적 차단, GDPR Art.6).
 */
export interface DetailedConsentSnapshot {
    categories: Record<string, boolean>;
    needs_renewal?: boolean;
}

/**
 * 정규화된 동의 캐시 — 내부 표현. union 입력을 본 형태로 통일.
 */
interface NormalizedConsent {
    categories: Record<string, boolean>;
    needs_renewal: boolean;
}

let observer: MutationObserver | null = null;
let currentConsent: NormalizedConsent = { categories: {}, needs_renewal: false };
let blockedDomains: Record<string, readonly string[]> = {};

/**
 * 입력 union 을 NormalizedConsent 로 통일.
 *
 * @param input plain Record 또는 DetailedConsentSnapshot
 * @return 정규화된 NormalizedConsent
 */
function normalizeConsent(input: ConsentSnapshot): NormalizedConsent {
    if (input && typeof input === 'object' && 'categories' in input) {
        const detailed = input as DetailedConsentSnapshot;
        return {
            categories: { ...detailed.categories },
            needs_renewal: detailed.needs_renewal === true,
        };
    }
    return {
        categories: { ...(input as Record<string, boolean>) },
        needs_renewal: false,
    };
}

/**
 * 차단 엔진의 동의 캐시를 갱신합니다.
 *
 * 부트스트랩(/consent/cookie/status 응답) 또는 동의 저장 직후 호출.
 * 캐시 갱신 후 자동으로 rescan 하여 차단/복원 상태를 즉시 반영합니다.
 *
 * @param consent 카테고리별 동의 상태 스냅샷 (plain Record 또는 DetailedConsentSnapshot)
 */
export function setCurrentConsent(consent: ConsentSnapshot): void {
    currentConsent = normalizeConsent(consent);
    if (observer !== null) {
        rescanBlocked();
    }
}

/**
 * 차단 도메인 목록을 차단 엔진에 주입합니다.
 *
 * 부트스트랩에서 운영자 커스텀 + 기본 카탈로그를 병합한 결과를 한 번 주입.
 * 이후 동의 변경 시 rescan 에 그대로 사용됩니다.
 *
 * @param patterns 카테고리 → 도메인 패턴 배열
 */
export function setBlockedDomains(patterns: Record<string, readonly string[]>): void {
    blockedDomains = { ...patterns };
    if (observer !== null) {
        rescanBlocked();
    }
}

/**
 * 차단 엔진 시작 — DOM 변화 관찰 + 초기 스캔.
 */
export function startBlocker(): void {
    if (typeof document === 'undefined' || observer !== null) return;

    rescanBlocked();

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) return;
                processNode(node);
            });
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
    });

    // 동의 변경 이벤트 — 레이아웃 JSON 동의 저장 onSuccess 또는 setCurrentConsent 호출 시 발화
    window.addEventListener('gdpr:consent-changed', handleConsentChanged as EventListener);
}

/**
 * 차단 엔진 중지 — 테스트/SPA 라우트 정리용.
 */
export function stopBlocker(): void {
    if (observer !== null) {
        observer.disconnect();
        observer = null;
    }
    window.removeEventListener('gdpr:consent-changed', handleConsentChanged as EventListener);
}

/**
 * gdpr:consent-changed 이벤트 핸들러 — detail 에 카테고리별 동의 스냅샷 기대.
 *
 * detail 은 plain Record (BC) 또는 DetailedConsentSnapshot 양쪽 호환.
 */
function handleConsentChanged(event: Event): void {
    const detail = (event as CustomEvent).detail;
    if (detail !== null && typeof detail === 'object') {
        currentConsent = normalizeConsent(detail as ConsentSnapshot);
    }
    rescanBlocked();
}

/**
 * 단일 노드 + 자손 처리.
 *
 * @param el HTMLElement
 */
function processNode(el: HTMLElement): void {
    if (matchesBlockSelector(el)) {
        gateElement(el);
    }

    el.querySelectorAll<HTMLElement>(BLOCK_SELECTOR).forEach((child) => gateElement(child));
}

/**
 * 페이지 전체 재스캔 — 동의 변경 시 호출.
 */
function rescanBlocked(): void {
    if (typeof document === 'undefined') return;

    // 1. 차단된 placeholder 중 동의 받은 카테고리는 복원
    document.querySelectorAll<HTMLElement>(`.${PLACEHOLDER_CLASS}`).forEach((placeholder) => {
        const category = placeholder.dataset.gdprBlockedCategory;
        if (category && isCategoryAllowed(category)) {
            restoreElement(placeholder);
        }
    });

    // 2. 미차단 요소 중 동의 안 받은 카테고리는 차단
    document.querySelectorAll<HTMLElement>(BLOCK_SELECTOR).forEach((el) => gateElement(el));
}

/**
 * 동의 안 받은 카테고리이면 placeholder 로 치환.
 *
 * 평가 우선순위:
 * 1. 속성 기반 매칭 (운영자 명시 의도)
 * 2. 도메인 기반 매칭 (운영자 차단 도메인 또는 카탈로그)
 *
 * @param el 차단 대상 요소
 */
function gateElement(el: HTMLElement): void {
    // 이미 placeholder 로 변환된 노드는 skip
    if (el.classList.contains(PLACEHOLDER_CLASS)) return;

    // preblocker (1차 라인) 가 이미 처리한 <script> 는 중복 차단 회피.
    // preblocker 는 src 를 무시하고 data-gdpr-blocked-* 속성으로 보존하므로
    // 외부 요청이 발생하지 않은 상태. blocker.ts (MutationObserver 백업) 는
    // preblocker 우회 케이스만 처리.
    if (el.hasAttribute('data-gdpr-blocked-category')) return;

    // 1. 속성 기반
    const attrCategory = el.getAttribute(BLOCKED_ATTR);
    if (attrCategory !== null && attrCategory !== '') {
        if (isCategoryAllowed(attrCategory)) return;
        replaceWithPlaceholder(el, attrCategory);
        return;
    }

    // 2. 도메인 기반
    const url = extractUrl(el);
    if (url === null) return;

    const domainCategory = categorizeUrl(url, blockedDomains);
    if (domainCategory === null) return;

    if (isCategoryAllowed(domainCategory)) return;
    replaceWithPlaceholder(el, domainCategory);
}

/**
 * 차단 대상 요소에서 외부 URL 추출 (`<script src>`, `<iframe src>`, `<img src>`, `<link href>`).
 *
 * @param el HTMLElement
 * @return 외부 URL 또는 null
 */
function extractUrl(el: HTMLElement): string | null {
    if (el instanceof HTMLScriptElement) return el.getAttribute('src');
    if (el instanceof HTMLIFrameElement) return el.getAttribute('src');
    if (el instanceof HTMLImageElement) return el.getAttribute('src');
    if (el instanceof HTMLLinkElement) return el.getAttribute('href');
    return null;
}

/**
 * 요소가 차단 SELECTOR 와 매칭되는지 확인.
 *
 * @param el HTMLElement
 * @return 매칭 여부
 */
function matchesBlockSelector(el: HTMLElement): boolean {
    if (typeof el.matches !== 'function') return false;
    try {
        return el.matches(BLOCK_SELECTOR);
    } catch {
        return false;
    }
}

/**
 * 요소를 placeholder 로 교체 (원본은 outerHTML 로 보존).
 *
 * @param el 차단 대상
 * @param category 차단 카테고리
 */
function replaceWithPlaceholder(el: HTMLElement, category: string): void {
    const placeholder = document.createElement('div');
    placeholder.className = PLACEHOLDER_CLASS;
    placeholder.dataset.gdprBlockedCategory = category;
    placeholder.dataset.gdprOriginalTag = el.tagName.toLowerCase();
    placeholder.setAttribute(ORIGINAL_DATA_ATTR, el.outerHTML);
    placeholder.setAttribute('aria-hidden', 'true');
    placeholder.style.display = 'none';

    el.replaceWith(placeholder);
}

/**
 * placeholder 에서 원본 요소 복원.
 *
 * @param placeholder 차단 placeholder div
 */
function restoreElement(placeholder: HTMLElement): void {
    const original = placeholder.getAttribute(ORIGINAL_DATA_ATTR);
    if (!original) {
        placeholder.remove();
        return;
    }

    const tmp = document.createElement('div');
    tmp.innerHTML = original;
    const restored = tmp.firstElementChild;
    if (restored !== null) {
        placeholder.replaceWith(restored);
    } else {
        placeholder.remove();
    }
}

/**
 * 카테고리가 허용되었는지 확인.
 *
 * 필수(necessary) 카테고리는 동의 여부와 무관하게 항상 허용 (ePrivacy Art.5(3) 면제).
 * needs_renewal=true (옛 동의가 신정책에 그대로 쓰이는 상태) 면 필수 외 모든 카테고리
 * 강제 false 처리 — 사용자가 의사 표명할 때까지 보수적 차단 (GDPR Art.6 강화).
 * 그 외는 currentConsent.categories[category] === true 인 경우만 허용.
 *
 * @param category 카테고리 키
 * @return 허용 여부
 */
function isCategoryAllowed(category: string): boolean {
    if (category === 'necessary') return true;
    if (currentConsent.needs_renewal) return false;
    return currentConsent.categories[category] === true;
}