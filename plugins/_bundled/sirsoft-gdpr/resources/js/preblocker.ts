/**
 * GDPR 사전 차단 엔진 (preblocker)
 *
 * GDPR 플러그인 IIFE 가 로드된 직후 즉시 `HTMLScriptElement.prototype.src` setter 를
 * 가로채는 1차 차단 라인. 운영자가 banner_enabled=true 일 때 settings API 응답을
 * 기다리지 않고 보수적 차단 (모든 비-necessary 차단) 으로 진입하여 race condition
 * (settings 응답 지연 동안 추적 스크립트가 외부 로드 완료되는 회귀) 을 막는다.
 *
 * 차단 우선순위:
 *   1. data-gdpr-category 속성 (운영자 명시) — origin 무관, 자체 호스팅 추적 지원
 *   2. 외부 origin + 카탈로그 매칭 — 도메인 기반 (categorizeUrl 재사용)
 *   3. 그 외 — 통과 (동일 origin 자기 차단 방지, 카탈로그 미매칭 등)
 *
 * 차단 시 src 설정을 무시하고 다음 속성을 element 에 보존:
 *   - data-gdpr-blocked-src: 원본 URL
 *   - data-gdpr-blocked-category: 매칭된 카테고리
 *
 * 동의 변경 시 `updatePreblockerConfig` + `restorePreblockedCategory` 호출로
 * 새 <script> 요소를 만들어 교체하여 정상 로드 흐름으로 진입.
 *
 * 본 모듈은 `<script src>` 만 가로챈다. `<iframe src>`, `<img src>`, `<link href>` 는
 * 안전성 (이미지/스타일 영향 회피) 을 위해 MutationObserver 백업 라인 (blocker.ts) 에
 * 위임한다.
 *
 * @module sirsoft-gdpr/preblocker
 */

import { categorizeUrl, matchesDomain } from './blocker-domains';

/**
 * preblocker 동작 설정.
 *
 * @property blockedDomains 카테고리 → 도메인 패턴 배열 (운영자 입력 + 카탈로그 합)
 * @property consent 카테고리 → 동의 여부 ({ necessary: true, analytics: false, ... })
 * @property needs_renewal 옛 정책 동의가 신정책에 그대로 쓰이는 상태 (true 면 필수 외 강제 차단)
 */
export interface PreblockerConfig {
    blockedDomains: Record<string, readonly string[]>;
    consent?: Record<string, boolean>;
    needs_renewal?: boolean;
}

const BLOCKED_SRC_ATTR = 'data-gdpr-blocked-src';
const BLOCKED_CATEGORY_ATTR = 'data-gdpr-blocked-category';
const CATEGORY_ATTR = 'data-gdpr-category';

let installed = false;
let originalSrcDescriptor: PropertyDescriptor | null = null;
let config: PreblockerConfig = { blockedDomains: {}, consent: { necessary: true }, needs_renewal: false };

/**
 * 카테고리 허용 여부.
 *
 * necessary 카테고리는 동의 무관 항상 허용 (ePrivacy Art.5(3) 면제).
 * needs_renewal=true (옛 동의가 신정책에 그대로 쓰이는 상태) 면 필수 외 모든 카테고리 강제 차단
 * — 사용자가 의사 표명할 때까지 보수적 차단 (GDPR Art.6 강화).
 *
 * @param category 카테고리 키
 * @return 허용 여부
 */
function isAllowed(category: string): boolean {
    if (category === 'necessary') return true;
    if (config.needs_renewal === true) return false;
    return config.consent?.[category] === true;
}

/**
 * <script> 요소의 차단 분기 판정.
 *
 * 1. data-gdpr-category 속성 (운영자 명시) — 우선
 * 2. 외부 origin + 카탈로그 매칭 — categorizeUrl 위임
 *
 * @param el script 요소
 * @param value 설정 시도된 src 값
 * @return 차단해야 할 카테고리, 차단 불필요면 null
 */
function decideBlock(el: HTMLScriptElement, value: string): string | null {
    // 1. 속성 기반 (운영자 명시 우선)
    const attrCategory = el.getAttribute(CATEGORY_ATTR);
    if (attrCategory !== null && attrCategory !== '') {
        if (isAllowed(attrCategory)) return null;
        return attrCategory;
    }

    // 2. 도메인 기반 — categorizeUrl 이 동일 origin / 비-HTTP / necessary skip 처리
    const domainCategory = categorizeUrl(value, config.blockedDomains);
    if (domainCategory === null) return null;
    if (isAllowed(domainCategory)) return null;
    return domainCategory;
}

/**
 * HTMLScriptElement.prototype.src setter 를 가로채는 사전 차단을 설치합니다.
 *
 * 이미 설치된 경우 config 만 갱신하고 재설치 안 함.
 *
 * @param initialConfig 초기 설정 (blockedDomains 필수, consent 미지정 시 necessary 만 허용)
 */
export function installPreblocker(initialConfig: PreblockerConfig): void {
    config = {
        blockedDomains: initialConfig.blockedDomains ?? {},
        consent: initialConfig.consent ?? { necessary: true },
        needs_renewal: initialConfig.needs_renewal === true,
    };

    if (installed) return;
    if (typeof HTMLScriptElement === 'undefined') return;

    const descriptor = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src');
    if (descriptor === undefined || typeof descriptor.set !== 'function') return;
    originalSrcDescriptor = descriptor;

    Object.defineProperty(HTMLScriptElement.prototype, 'src', {
        configurable: true,
        enumerable: descriptor.enumerable ?? true,
        get(this: HTMLScriptElement): string {
            return descriptor.get?.call(this) ?? '';
        },
        set(this: HTMLScriptElement, value: string): void {
            const category = decideBlock(this, value);
            if (category !== null) {
                this.setAttribute(BLOCKED_SRC_ATTR, value);
                this.setAttribute(BLOCKED_CATEGORY_ATTR, category);
                return;
            }
            descriptor.set!.call(this, value);
        },
    });

    installed = true;
}

/**
 * preblocker 의 카탈로그/동의 상태를 갱신합니다.
 *
 * settings API 응답 도착 또는 사용자 동의 변경 시 호출.
 * 신규 차단 판정에 즉시 반영. 이미 차단된 요소는 restorePreblockedCategory 로 별도 복원.
 *
 * @param next 새 설정 (부분 갱신 안 함 — 전체 교체)
 */
export function updatePreblockerConfig(next: PreblockerConfig): void {
    config = {
        blockedDomains: next.blockedDomains ?? {},
        consent: next.consent ?? { necessary: true },
        needs_renewal: next.needs_renewal === true,
    };
}

/**
 * 동의 받은 카테고리의 차단된 <script> 요소를 새로 만들어 교체합니다.
 *
 * 원본 element 는 src 가 비어있는 상태로 보존되어 있으므로, 동일 속성을 가진
 * 새 <script> 를 만들어 같은 위치에 삽입한다. 새 요소의 src 할당 시점에 setter 가
 * 다시 호출되어 도메인 매칭하지만, updatePreblockerConfig 로 동의가 부여된
 * 상태라 통과한다.
 *
 * 동의가 부여되지 않은 카테고리에 대해 호출 시 동작 안 함.
 *
 * @param category 복원할 카테고리
 */
export function restorePreblockedCategory(category: string): void {
    if (!isAllowed(category)) return;
    if (typeof document === 'undefined') return;

    const blocked = document.querySelectorAll<HTMLScriptElement>(
        `script[${BLOCKED_CATEGORY_ATTR}="${category}"]`,
    );

    blocked.forEach((original) => {
        const src = original.getAttribute(BLOCKED_SRC_ATTR);
        if (src === null || src === '') {
            return;
        }

        const fresh = document.createElement('script');
        // 원본의 기타 속성 복제 (id, async, defer, type, crossorigin, data-* 등)
        for (const attr of Array.from(original.attributes)) {
            if (
                attr.name === BLOCKED_SRC_ATTR ||
                attr.name === BLOCKED_CATEGORY_ATTR ||
                attr.name === 'src'
            ) {
                continue;
            }
            fresh.setAttribute(attr.name, attr.value);
        }

        const parent = original.parentNode;
        if (parent === null) {
            return;
        }
        parent.replaceChild(fresh, original);

        // src 할당 — 동의 부여 상태라 setter 통과
        fresh.src = src;
    });
}

/**
 * preblocker 를 제거하여 prototype 을 원상 복구합니다.
 *
 * 테스트 격리 / SPA 라우트 정리 / 운영자 비활성화 시 호출.
 */
export function uninstallPreblocker(): void {
    if (!installed) return;

    if (originalSrcDescriptor !== null) {
        Object.defineProperty(HTMLScriptElement.prototype, 'src', originalSrcDescriptor);
        originalSrcDescriptor = null;
    }

    installed = false;
    config = { blockedDomains: {}, consent: { necessary: true } };
}

/**
 * 도메인 매칭 단일 함수 노출 — 외부 디버깅용.
 *
 * @param host 검사 호스트
 * @param pattern 차단 패턴
 * @return 매칭 여부
 */
export { matchesDomain };
