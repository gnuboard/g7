/**
 * sirsoft-gdpr 플러그인 진입점
 *
 * 페이지 로드 시 자동 부트스트랩:
 *
 * 0. 사전 차단 즉시 등록 (preblocker) — settings fetch 기다리지 않고
 *    window.G7Config.plugins['sirsoft-gdpr'].blocked_domains 를 동기 사용하여
 *    HTMLScriptElement.prototype.src setter 가로채기. 보수적 동의 (necessary 만)
 *    상태로 진입하여 settings/consent 응답 지연 동안 추적 스크립트가 외부 로드되는
 *    race condition 방지.
 * 1. Phase 2 1st-party storage/cookie 인터셉터 즉시 등록 — strictly necessary
 *    allowlist 외 모든 비-필수 저장을 동의 시까지 차단 (EDPB Guidelines 2/2023 §16).
 *    user-initiated 면제 (WP29 §3.6) 는 항상 활성.
 * 2. 공개 설정 페치 (banner_enabled / cookie_policy_version / blocked_domains)
 * 3. 자동 차단 엔진 (MutationObserver 백업 라인) 시작 — banner_enabled=true 시
 * 4. 코어 동의 런타임의 현재 스냅샷(`G7Core.consent.getSnapshot()` = `_global.consent`)으로
 *    preblocker + blocker + interceptor 동기화하고, 이후 변경은 `G7Core.consent.subscribe()`
 *    (코어 이벤트 `consent:changed`)로 받는다.
 *
 * 동의 SSoT 는 서버 DB 이고, 이 플러그인은 코어 동의 계약의 제공자로 서버에서 답한다(GdprConsentProvider).
 * 브라우저에서는 코어 런타임 하나가 스냅샷을 조회·보관하고 로그인·로그아웃 때 다시 읽는다 — 이 플러그인은
 * 자체 조회도 자체 이벤트도 없이 그 런타임을 소비만 한다(#639). 배너·마이페이지 레이아웃은 동의 저장 뒤
 * 코어 내장 핸들러 `refreshConsent` 를 부른다.
 *
 * @module sirsoft-gdpr/index
 */

import '../css/banner.css';
import { startBlocker, setCurrentConsent, setBlockedDomains } from './blocker';
import {
    installCookieInterceptor,
    updateCookieInterceptorConfig,
} from './cookieInterceptor';
import { cleanupFunctionalArtifacts } from './functionalCleaner';
import { fetchPublicSettings } from './layouts';
import {
    LOCKED_FALLBACK,
    resolveInlineAllowlist,
    type NecessaryAllowlist,
} from './necessaryAllowlist';
import {
    installPreblocker,
    updatePreblockerConfig,
    restorePreblockedCategory,
    type PreblockerConfig,
} from './preblocker';
import {
    installStorageInterceptor,
    updateStorageInterceptorConfig,
} from './storageInterceptor';
import { installUserInitiatedTracker } from './userInitiatedTracker';

const PLUGIN_IDENTIFIER = 'sirsoft-gdpr';

/** 코어 동의 스냅샷 (`_global.consent`) 중 이 플러그인이 읽는 부분 */
interface CoreConsentSnapshot {
    status: 'unknown' | 'ready' | 'none';
    categories: Record<string, boolean>;
    needsRenewal: boolean;
}

/** 코어 동의 파사드 (`G7Core.consent`) */
interface CoreConsentFacade {
    getSnapshot: () => CoreConsentSnapshot | null;
    subscribe: (listener: (snapshot: CoreConsentSnapshot) => void) => () => void;
}

interface G7Config {
    plugins?: Record<string, Record<string, unknown>>;
}

/**
 * G7Config 인라인 페이로드에서 GDPR 차단 카탈로그 즉시 조회.
 *
 * @return 카테고리 → 도메인 패턴 배열
 */
function readInlineBlockedDomains(): Record<string, readonly string[]> {
    if (typeof window === 'undefined') return {};
    const config = (window as unknown as { G7Config?: G7Config }).G7Config;
    const pluginConfig = config?.plugins?.[PLUGIN_IDENTIFIER];
    const raw = pluginConfig?.blocked_domains;
    if (raw === undefined || raw === null || typeof raw !== 'object') return {};

    const normalized: Record<string, readonly string[]> = {};
    for (const [category, domains] of Object.entries(raw as Record<string, unknown>)) {
        if (!Array.isArray(domains)) continue;
        const strs = domains.filter((d): d is string => typeof d === 'string' && d !== '');
        if (strs.length > 0) normalized[category] = strs;
    }
    return normalized;
}

/**
 * G7Config 인라인 페이로드에서 strictly necessary 허용목록 즉시 조회.
 *
 * 인터셉터는 `fetchPublicSettings()` **앞에서** 선다 — 동의 전 첫 저장을 막는 것이 이
 * 기능의 목적이라 응답을 기다릴 수 없다. 그래서 허용목록은 인라인으로 와야 하고,
 * `defaults.json` 의 `frontend_schema` 에 `expose: true` 가 없으면 여기서 빈 목록이 된다.
 *
 * 판정은 언제나 **운영자 목록 ∪ 잠금 집합 ∪ 확장 제공 집합**이다. 확장 제공 집합은 활성 확장이
 * 계약 훅으로 덧붙인 키이며 서버가 요청마다 모아 인라인한다. 잠금 집합이 통째로 비어 오면 페이로드가
 * 도달하지 않았다는 뜻이므로 코드 폴백으로 내려간다 — 그러지 않으면 인터셉터가 빈 목록으로
 * 서서 동의 없는 첫 방문자의 로그인 토큰까지 차단한다.
 *
 * @return 스코프별 허용 패턴 목록
 */
function readInlineNecessaryAllowlist(): NecessaryAllowlist {
    if (typeof window === 'undefined') return LOCKED_FALLBACK;
    const config = (window as unknown as { G7Config?: G7Config }).G7Config;
    const pluginConfig = config?.plugins?.[PLUGIN_IDENTIFIER];

    return resolveInlineAllowlist(pluginConfig);
}

/**
 * banner_enabled 도 G7Config 페이로드에 인라인 — 페이지 진입 즉시 사용 가능.
 *
 * @return banner_enabled boolean
 */
function readInlineBannerEnabled(): boolean {
    if (typeof window === 'undefined') return false;
    const config = (window as unknown as { G7Config?: G7Config }).G7Config;
    const pluginConfig = config?.plugins?.[PLUGIN_IDENTIFIER];
    return pluginConfig?.banner_enabled === true;
}

/**
 * 코어 동의 파사드를 반환합니다 (사용자 화면에서만 있다).
 *
 * @return 동의 파사드 또는 null
 */
function resolveConsentFacade(): CoreConsentFacade | null {
    const facade = (window as unknown as { G7Core?: { consent?: CoreConsentFacade } }).G7Core?.consent;
    if (facade && typeof facade.getSnapshot === 'function' && typeof facade.subscribe === 'function') {
        return facade;
    }
    return null;
}

/** 이전 구독 해제 함수 — 부트스트랩이 두 번 돌아도 구독은 하나 */
let unsubscribeConsent: (() => void) | null = null;

/**
 * 코어 동의 스냅샷을 차단 엔진·preblocker·인터셉터에 반영합니다.
 *
 * - `unknown`(부팅 중, 로그인·로그아웃 직후 재조회 중)에는 아무것도 바꾸지 않는다 — 현재 엔진 상태 유지.
 * - 기능 저장소 정리(EDPB §117)는 `ready` 이면서 기능 미동의일 때만 — 일시적 `unknown` 으로 동의한 회원의
 *   저장소를 지우지 않는다.
 * - `needsRenewal` 이면 옛 동의가 새 정책에 쓰이는 상태라 preblocker 차단 요소를 복원하지 않는다.
 *
 * @param snapshot 코어 동의 스냅샷
 * @param blockedDomains 운영자 차단 도메인 카탈로그
 */
export function applyConsentSnapshot(
    snapshot: CoreConsentSnapshot | null | undefined,
    blockedDomains: Record<string, readonly string[]>,
): void {
    if (!snapshot || (snapshot.status !== 'ready' && snapshot.status !== 'none')) return;

    const categories = { ...(snapshot.categories ?? {}) };
    const needsRenewal = snapshot.needsRenewal === true;
    const functionalConsented = snapshot.status === 'ready' && !needsRenewal && categories.functional === true;

    // 1. blocker.ts (MutationObserver 백업 라인) — rescan 으로 placeholder 복원/차단
    setCurrentConsent({ categories, needs_renewal: needsRenewal });

    // 2. preblocker (1차 라인)
    updatePreblockerConfig({ blockedDomains, consent: categories, needs_renewal: needsRenewal });

    // 3. Phase 2: storage / cookie 인터셉터
    const necessaryAllowlist = readInlineNecessaryAllowlist();
    updateStorageInterceptorConfig({ functionalConsented, necessaryAllowlist });
    updateCookieInterceptorConfig({ functionalConsented, necessaryAllowlist });

    // 4. EDPB §117: 기능 미동의 확정이면 allowlist 외 storage/cookie 파기
    if (snapshot.status === 'ready' && !functionalConsented) {
        cleanupFunctionalArtifacts({ allowlist: necessaryAllowlist });
    }

    // 5. 동의 받은 카테고리의 preblocker 차단 요소 복원
    if (!needsRenewal) {
        for (const [category, allowed] of Object.entries(categories)) {
            if (allowed === true && category !== 'necessary') {
                restorePreblockedCategory(category);
            }
        }
    }
}

/**
 * 부트스트랩 — DOMContentLoaded 또는 즉시 실행.
 */
async function bootstrap(): Promise<void> {
    if (typeof window === 'undefined') return;

    // 0. preblocker + 1st-party 인터셉터 즉시 등록 (banner_enabled=true 시) — settings fetch 기다리지 않음.
    //    G7Config 인라인 페이로드 (HTML 응답에 동기 포함) 로 즉시 카탈로그 사용 가능.
    //    보수적 동의 상태 (necessary 만) 로 진입 — settings/consent fetch 완료 후 보강.
    const inlineBannerEnabled = readInlineBannerEnabled();
    if (inlineBannerEnabled) {
        const inlineDomains = readInlineBlockedDomains();
        installPreblocker({
            blockedDomains: inlineDomains,
            consent: { necessary: true },
        });

        // Phase 2: functional 1st-party storage 게이팅 (EDPB Guidelines 2/2023 §16).
        //   - userInitiatedTracker: 사용자 인터랙션 시각 기록 (WP29 §3.6 면제 판정용, 항상 활성)
        //   - storageInterceptor: localStorage / sessionStorage 신규 쓰기 가로채기
        //   - cookieInterceptor: document.cookie 신규 쓰기 가로채기
        // 모두 strictly necessary 허용목록(운영자 설정 ∪ 잠금 집합) 외 미동의 + 비-사용자 쓰기는 차단.
        installUserInitiatedTracker();
        const inlineAllowlist = readInlineNecessaryAllowlist();
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: inlineAllowlist,
        });
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: inlineAllowlist,
        });
    }

    // 1. 공개 설정 fetch — banner_enabled / blocked_domains 의 SSoT 확정값 조회.
    //    G7Config 인라인은 *페이지 진입 시 스냅샷* 이라 SPA 라우트 이동 후 변경 가능성 대비.
    const settings = await fetchPublicSettings();
    if (!settings) {
        return;
    }

    const bannerEnabled = settings.banner_enabled === true;
    if (!bannerEnabled) {
        return;
    }

    // 도메인 기반 차단 목록 주입 (blocker.ts 백업 라인) — 운영자 입력 도메인만 사용.
    const customDomains = (settings.blocked_domains as Record<string, string[]> | undefined) ?? {};
    setBlockedDomains(customDomains);

    // preblocker 카탈로그도 보강 (인라인 페이로드와 응답 일치성 확인 + 누락 시 보강)
    const preblockerConfig: PreblockerConfig = {
        blockedDomains: customDomains,
        consent: { necessary: true },
    };
    updatePreblockerConfig(preblockerConfig);

    startBlocker();

    // 2. 코어 동의 런타임 구독 후 현재 스냅샷 반영 — 구독을 먼저 걸어야 그 사이 도착한 변경을 놓치지 않는다.
    //    런타임이 없는 화면(관리자)에서는 보수적 차단 상태를 유지한다.
    const facade = resolveConsentFacade();
    if (facade === null) {
        return;
    }
    unsubscribeConsent?.();
    unsubscribeConsent = facade.subscribe((next) => applyConsentSnapshot(next, customDomains));
    applyConsentSnapshot(facade.getSnapshot(), customDomains);
}

/**
 * 코어 재초기화 시 호출되는 진입점 — 등록할 핸들러가 없어 아무것도 하지 않는다 (결정 D22).
 *
 * 이 플러그인은 액션 핸들러를 등록하지 않는다(동의 저장 뒤 동기화는 코어 내장 `refreshConsent`). 전역
 * `window.__SirsoftGdpr` 는 외부에서 이 이름으로 플러그인 존재를 확인하던 코드를 깨지 않으려고 남긴다.
 * 동의 구독은 코어 `componentEvent` 에 걸려 있어 로케일 재초기화에도 유지된다.
 */
function initPlugin(): void {
    // no-op
}

if (typeof window !== 'undefined') {
    (window as unknown as Record<string, unknown>).__SirsoftGdpr = {
        identifier: PLUGIN_IDENTIFIER,
        // 재등록할 핸들러가 없다 — 빈 진입점 (D22)
        initPlugin,
    };
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            void bootstrap();
        });
    } else {
        void bootstrap();
    }
}

export { initPlugin, PLUGIN_IDENTIFIER };