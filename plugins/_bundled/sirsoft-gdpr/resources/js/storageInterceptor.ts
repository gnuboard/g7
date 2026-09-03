/**
 * GDPR 1st-party Storage 가로채기 (storageInterceptor)
 *
 * `Storage.prototype.setItem` 을 가로채 functional 카테고리 미동의 상태에서
 * 신규 localStorage / sessionStorage 쓰기를 차단. EDPB Guidelines 2/2023 §16
 * "동의 전 사전 차단 (prior consent before storage)" 충족.
 *
 * 게이팅 규칙 (Phase 2 단순화 — 4단계):
 *   1. strictly necessary allowlist 매칭 → 항상 허용
 *      (등재 항목은 `DEFAULT_NECESSARY_ALLOWLIST` 가 SSoT — 여기에 목록을 복제하지
 *       않는다. 사본을 두면 항목이 늘 때 한쪽만 고쳐져 조용히 어긋난다.)
 *   2. functional 동의 → 허용
 *   3. user-initiated 면제 (WP29 §3.6, 항상 활성) → 사용자 인터랙션 직후 허용
 *   4. 그 외 → 차단
 *
 * "운영자 등록 표" 는 제거됨 — GDPR 원칙은 "strictly necessary 외 비-필수 저장은
 * 동의 전 차단" 이므로 등록 표 없이 동일하게 모두 게이팅.
 *
 * window.localStorage.setItem 과 window.sessionStorage.setItem 은 둘 다
 * Storage.prototype.setItem 을 공유하므로 1회 가로채기로 양쪽 모두 커버.
 *
 * @module sirsoft-gdpr/storageInterceptor
 */

import { isUserInitiated } from './userInitiatedTracker';

/**
 * Storage 종류 — strictly necessary 판정 시 storage 타입 매칭에 사용.
 */
export type StorageKind = 'localStorage' | 'sessionStorage';

/**
 * strictly necessary allowlist 항목.
 *
 * @property key 정확 매칭 또는 prefix
 * @property storage 적용할 스토리지 (생략 시 둘 다)
 * @property matchType 'exact' (기본) 또는 'prefix'
 */
export interface NecessaryAllowlistEntry {
    key: string;
    storage?: StorageKind;
    matchType?: 'exact' | 'prefix';
}

/**
 * 인터셉터 설정.
 *
 * @property functionalConsented functional 카테고리 동의 여부
 * @property necessaryAllowlist strictly necessary 면제 키 (코어 + 정적)
 */
export interface StorageInterceptorConfig {
    functionalConsented: boolean;
    necessaryAllowlist: readonly NecessaryAllowlistEntry[];
}

/**
 * 정적 strictly necessary allowlist — G7 코어 동작에 필수인 키 + WP29 §3.6 면제 키.
 *
 * 본 목록은 G7 코어가 정상 동작하는 데 반드시 필요한 키만 포함. 운영자가 추가 등록 불가
 * (코드 상수). 운영자가 추가하려면 본 파일 수정 + PR 필요.
 *
 * 계열: 인증·세션 / 사용자 명시 선택(언어·테마) / 구매 동선(장바구니·비회원 주문) /
 *       관리자 화면 상태 / 레이아웃 편집기 작업 상태 / 결제 복귀 기록 / 본인인증 복귀.
 *
 * 이 배열이 유일한 정본이다. 코어·확장이 새 저장 키를 도입하면서 여기 등재를 잊으면
 * functionalCleaner 가 부팅마다 그 키를 파기해 "저장은 되는데 새로고침하면 사라지는"
 * 상태가 되고, 예외도 로그도 남지 않는다. 그 누락은 저장소의 `.setItem()` 을 기계
 * 도출해 대조하는 `__tests__/necessaryAllowlistCoverage.test.ts` 가 잡는다.
 */
export const DEFAULT_NECESSARY_ALLOWLIST: readonly NecessaryAllowlistEntry[] = [
    // 사용자 명시 선택 (WP29 §3.6) — 다국어 설정
    { key: 'g7_locale', storage: 'localStorage', matchType: 'exact' },
    // 사용자 명시 선택 (WP29 §3.6) — 화면 테마(밝게/어둡게/시스템 설정).
    // 언어 설정과 같은 범주다: 사용자가 화면에서 직접 고른 표시 환경이며 추적에 쓰이지
    // 않는다. 목록에서 빠져 있는 동안에는 테마를 바꿔도 새로고침하면 되돌아갔고,
    // 그 증상이 미인증 화면뿐 아니라 관리자 화면 전체에 나타났다 (dev-g7#640).
    { key: 'g7_color_scheme', storage: 'localStorage', matchType: 'exact' },
    // 인증 토큰 — 로그인 유지 필수 (strictly necessary, Art.6(1)(b))
    { key: 'auth_token', storage: 'localStorage', matchType: 'exact' },
    // 코어 캐시 버전 — 운영자가 의도적 갱신 시 사용
    { key: 'g7_cache_version', storage: 'localStorage', matchType: 'exact' },
    // 자산 URL 형식 판정 캐시 — 사용자 정보가 아니라 서버 능력 판정 결과다.
    // 파기되면 재방문마다 기본 형식으로 첫 자산 요청을 보내 404 를 겪은 뒤에야
    // 대체 형식으로 넘어간다. 키에 캐시 버전이 붙으므로 접두사로 등재한다.
    { key: 'g7_asset_url_mode', storage: 'localStorage', matchType: 'prefix' },
    // 장바구니 게스트 키 — 익명 카트 식별 (구매 동선 필수)
    { key: 'g7_cart_key', storage: 'localStorage', matchType: 'exact' },
    // devtools UI 상태 — 개발자 환경 (strictly necessary 개발자 도구)
    { key: 'g7-devtools-panel', storage: 'localStorage', matchType: 'exact' },
    // 비회원 주문 조회 — 주문 이행에 필요 (Art.6(1)(b)). 게스트 장바구니 키와 같은 범주로,
    // 파기되면 방금 주문한 비회원이 자기 주문내역에 도달할 수 없다.
    { key: 'g7_guest_order_token', storage: 'localStorage', matchType: 'exact' },
    { key: 'g7_guest_order_number', storage: 'localStorage', matchType: 'exact' },
    { key: 'g7_guest_order_expires_at', storage: 'localStorage', matchType: 'exact' },
    // 관리자 페이지 상태 (필터/정렬/컬럼/devtools) — 사용자 의사로 조작
    { key: 'g7_devtools_', matchType: 'prefix' },
    { key: 'g7_filters_', matchType: 'prefix' },
    { key: 'g7_columns_', matchType: 'prefix' },
    { key: 'g7_order_', matchType: 'prefix' },
    // 관리자 화면 표시 설정 — 테마와 같은 범주 (사용자가 화면에서 직접 고른 표시 환경).
    // `g7_filters_` 는 목록 필터 '값' 이고 `g7_filter_visibility_` 는 필터 '표시 여부' 라
    // 접두사가 서로 덮지 않는다 — 목록에 따로 서야 한다 (dev-g7#640).
    { key: 'g7_admin_sidebar_collapsed', storage: 'localStorage', matchType: 'exact' },
    { key: 'g7_filter_visibility_', matchType: 'prefix' },
    { key: 'g7_dismissed_warnings', storage: 'localStorage', matchType: 'exact' },
    // 레이아웃 편집기 작업 상태 (라우트 트리 접힘·클립보드) — 운영자 편집 동선 유지
    { key: 'g7le.', matchType: 'prefix' },
    // 결제 진행 기록 — 결제창 복귀 시 종료·실패 사유 보고에 필요 (Art.6(1)(b)).
    // 결제창은 전체 페이지 이동으로 열리고 돌아오므로 sessionStorage 에 맡겨 두는데,
    // 부팅 시 파기되면 그 보고가 통째로 누락된다.
    { key: 'g7:sirsoft-pay_kginicis:pendingClose', storage: 'sessionStorage', matchType: 'exact' },
    { key: 'g7:sirsoft-pay_nhnkcp:pendingClose', storage: 'sessionStorage', matchType: 'exact' },
    { key: 'g7:sirsoft-tosspayments:pendingClose', storage: 'sessionStorage', matchType: 'exact' },
    { key: '__sirsoftKginicisMobilePaymentReturnPending', matchType: 'exact' },
    // 본인인증 복귀 — 인증창에서 돌아왔을 때 원래 화면·입력 내용 복원에 필요
    { key: 'g7.identity.redirectStash', matchType: 'exact' },
    { key: 'sirsoft-verification_nhnkcp.formStash', matchType: 'exact' },
];

let installed = false;
let originalSetItem: ((key: string, value: string) => void) | null = null;
let config: StorageInterceptorConfig = {
    functionalConsented: false,
    necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
};

/**
 * 키가 strictly necessary allowlist 에 매칭되는지 검사합니다.
 *
 * storage 인자는 호출된 storage 종류 (localStorage / sessionStorage). allowlist 엔트리에
 * storage 가 명시되어 있으면 정확히 일치할 때만 매칭. 미명시면 둘 다 허용.
 *
 * @param  key  스토리지 키
 * @param  storage  호출 스토리지 종류
 * @return 매칭 여부
 */
function matchesNecessary(key: string, storage: StorageKind): boolean {
    for (const entry of config.necessaryAllowlist) {
        if (entry.storage && entry.storage !== storage) {
            continue;
        }
        const matchType = entry.matchType ?? 'exact';
        if (matchType === 'exact' && entry.key === key) {
            return true;
        }
        if (matchType === 'prefix' && key.startsWith(entry.key)) {
            return true;
        }
    }
    return false;
}

/**
 * setItem 호출이 허용되는지 판정합니다.
 *
 * 본 함수는 사이드 이펙트 없음 — 정책 평가만. 차단/통과 결정은 호출자가 수행.
 *
 * 게이팅 규칙 (4단계):
 *   1. strictly necessary → 허용
 *   2. functional 동의 → 허용
 *   3. user-initiated (WP29 §3.6, 항상 활성) → 허용
 *   4. 그 외 → 차단
 *
 * @param  key  스토리지 키
 * @param  storage  호출 스토리지 종류
 * @return 허용 여부
 */
export function isStorageAllowed(key: string, storage: StorageKind): boolean {
    if (matchesNecessary(key, storage)) {
        return true;
    }

    if (config.functionalConsented) {
        return true;
    }

    // user-initiated 면제 (WP29 §3.6) — 사용자가 직접 트리거한 일회성 설정은 동의 없이 허용.
    // GDPR 표준 면제로 항상 활성 (운영자 토글 없음).
    if (isUserInitiated()) {
        return true;
    }

    return false;
}

/**
 * 인터셉터를 설치합니다 — Storage.prototype.setItem 을 가로채기.
 *
 * 중복 install 방지: installed 플래그.
 *
 * @param  initialConfig  초기 설정 (이후 updateStorageInterceptorConfig 로 갱신)
 * @return void
 */
export function installStorageInterceptor(initialConfig: StorageInterceptorConfig): void {
    if (installed) {
        return;
    }
    installed = true;
    config = initialConfig;

    const proto = Storage.prototype;
    originalSetItem = proto.setItem;

    proto.setItem = function (this: Storage, key: string, value: string): void {
        const storage: StorageKind = this === window.sessionStorage ? 'sessionStorage' : 'localStorage';
        if (!isStorageAllowed(key, storage)) {
            return;
        }
        if (originalSetItem) {
            originalSetItem.call(this, key, value);
        }
    };
}

/**
 * 인터셉터 설정을 갱신합니다 — 동의 변경 시 호출.
 *
 * @param  newConfig  갱신할 설정
 * @return void
 */
export function updateStorageInterceptorConfig(newConfig: StorageInterceptorConfig): void {
    config = newConfig;
}

/**
 * 인터셉터를 해제합니다 (테스트 격리 / cleanup 용).
 *
 * 원본 setItem 을 복원하고 installed 플래그를 리셋.
 *
 * @return void
 */
export function uninstallStorageInterceptor(): void {
    if (!installed) {
        return;
    }
    if (originalSetItem) {
        Storage.prototype.setItem = originalSetItem;
    }
    originalSetItem = null;
    config = {
        functionalConsented: false,
        necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
    };
    installed = false;
}