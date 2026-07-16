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
 * 4. 현재 방문자의 카테고리별 동의 상태 페치 후 preblocker + blocker + interceptor 동기화
 * 5. 부팅 시점 functional 미동의면 cleanup 호출 (allowlist 외 모든 storage/cookie 파기, EDPB §117)
 * 6. ActionDispatcher 에 `sirsoft-gdpr.syncConsent` 핸들러 등록 — 레이아웃의 동의 저장
 *    onSuccess 에서 호출하여 두 차단 엔진 + 인터셉터 동기화 + 필요 시 cleanup
 *
 * 동의 SSoT 는 서버 DB. 배너 표시/저장은 cookie_banner.json 레이아웃이 단독 처리하며,
 * 차단 엔진 + 인터셉터는 `sirsoft-gdpr.syncConsent` 핸들러로 동기화.
 *
 * @module sirsoft-gdpr/index
 */

import '../css/banner.css';
import { startBlocker, setCurrentConsent, setBlockedDomains } from './blocker';
import {
    installCookieInterceptor,
    updateCookieInterceptorConfig,
    DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
} from './cookieInterceptor';
import { cleanupFunctionalArtifacts } from './functionalCleaner';
import { fetchPublicSettings, fetchConsentSnapshot } from './layouts';
import {
    installPreblocker,
    updatePreblockerConfig,
    restorePreblockedCategory,
    type PreblockerConfig,
} from './preblocker';
import {
    installStorageInterceptor,
    updateStorageInterceptorConfig,
    DEFAULT_NECESSARY_ALLOWLIST,
} from './storageInterceptor';
import { installUserInitiatedTracker } from './userInitiatedTracker';

const PLUGIN_IDENTIFIER = 'sirsoft-gdpr';

interface ActionDispatcher {
    registerHandler: (name: string, handler: (...args: unknown[]) => unknown) => void;
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
 * G7Core.getActionDispatcher() 가 준비될 때까지 대기 후 핸들러 등록.
 *
 * 모듈/플러그인 에셋 로딩 순서에 따라 ActionDispatcher 가 아직 초기화 안 됐을 수
 * 있으므로 setTimeout 으로 polling. module-assets.md 의 권장 패턴.
 */
function registerSyncHandler(): void {
    const dispatcher = (
        window as unknown as { G7Core?: { getActionDispatcher?: () => ActionDispatcher | undefined } }
    ).G7Core?.getActionDispatcher?.();

    if (!dispatcher) {
        setTimeout(registerSyncHandler, 100);
        return;
    }

    // sirsoft-gdpr.syncConsent — 동의 저장 직후 차단 엔진 + 인터셉터 동기화
    dispatcher.registerHandler(`${PLUGIN_IDENTIFIER}.syncConsent`, async () => {
        const snapshot = await fetchConsentSnapshot();
        if (snapshot === null) return;

        const functionalConsented = !snapshot.needs_renewal && snapshot.categories.functional === true;

        // 1. blocker.ts (MutationObserver 백업 라인) 동기화 — rescan 으로 placeholder 복원
        setCurrentConsent({ categories: snapshot.categories, needs_renewal: snapshot.needs_renewal });

        // 2. preblocker (1차 라인) 동기화 — 새 차단 판정에 즉시 반영
        const blockedDomains = readInlineBlockedDomains();
        updatePreblockerConfig({
            blockedDomains,
            consent: snapshot.categories,
            needs_renewal: snapshot.needs_renewal,
        });

        // 3. Phase 2: storage / cookie 인터셉터 동기화
        updateStorageInterceptorConfig({
            functionalConsented,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });
        updateCookieInterceptorConfig({
            functionalConsented,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        // 4. EDPB §117: 동의 철회 즉시 파기 — strictly necessary allowlist 외 모든 storage/cookie cleanup.
        if (!functionalConsented) {
            cleanupFunctionalArtifacts();
        }

        // 5. 동의 받은 카테고리의 preblocker 차단 요소 복원 (단, needs_renewal=true 면 복원 안 함)
        if (!snapshot.needs_renewal) {
            for (const [category, allowed] of Object.entries(snapshot.categories)) {
                if (allowed === true && category !== 'necessary') {
                    restorePreblockedCategory(category);
                }
            }
        }
    });
}

/**
 * 부트스트랩 — DOMContentLoaded 또는 즉시 실행.
 */
async function bootstrap(): Promise<void> {
    if (typeof window === 'undefined') return;

    // 핸들러 등록은 banner_enabled 활성 여부와 무관하게 항상 수행.
    registerSyncHandler();

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
        // 모두 strictly necessary allowlist (코드 상수) 외 미동의 + 비-사용자 쓰기는 차단.
        installUserInitiatedTracker();
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
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

    // 2. 현재 동의 상태 페치 후 두 엔진 + 인터셉터 동기화 + 복원
    //    needs_renewal=true 면 옛 동의가 신정책에 그대로 쓰이는 상태 → 복원 안 함 (보수적 차단 유지)
    const snapshot = await fetchConsentSnapshot();
    const functionalConsented =
        snapshot !== null && !snapshot.needs_renewal && snapshot.categories.functional === true;

    // Phase 2: storage / cookie 인터셉터 설정 갱신 (functional 동의 여부만 반영).
    updateStorageInterceptorConfig({
        functionalConsented,
        necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
    });
    updateCookieInterceptorConfig({
        functionalConsented,
        necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
    });

    // Phase 2: 부팅 시점 functional 미동의면 allowlist 외 모든 storage/cookie 파기 (EDPB §117).
    //   - 재방문 시 이전 세션의 잔류 데이터 정리
    //   - 신규 게스트 / 거부 후 재방문 모두 동일 처리
    if (!functionalConsented) {
        cleanupFunctionalArtifacts();
    }

    if (snapshot !== null) {
        setCurrentConsent({ categories: snapshot.categories, needs_renewal: snapshot.needs_renewal });
        updatePreblockerConfig({
            blockedDomains: customDomains,
            consent: snapshot.categories,
            needs_renewal: snapshot.needs_renewal,
        });
        if (!snapshot.needs_renewal) {
            for (const [category, allowed] of Object.entries(snapshot.categories)) {
                if (allowed === true && category !== 'necessary') {
                    restorePreblockedCategory(category);
                }
            }
        }
    }
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