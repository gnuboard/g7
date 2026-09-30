/**
 * 필터 표시/숨김 설정 로드 핸들러 (코어 관리자 템플릿판)
 *
 * 목록 화면의 「검색편집」 으로 운영자가 기본/상세 영역 사이에 옮겨 둔 필터 배치를
 * localStorage 에서 되읽는다. 저장은 `FilterVisibilitySelector` 컴포넌트가 스스로 하고
 * (`g7_filters_{id}_{사용자uuid}`), 이 핸들러는 **같은 키 규칙으로 읽기만** 한다.
 *
 * 이커머스 모듈의 `sirsoft-ecommerce.loadVisibleFilters` 와 시그니처·키 규칙·판정이 같다.
 * 코어 화면(회원관리 등)이 모듈 핸들러를 부르면 그 모듈이 비활성일 때 화면이 깨지므로,
 * 코어 템플릿이 자기 핸들러를 소유한다 (#705).
 *
 * 판정: 저장값이 **배열이면서 비어 있지 않을 때만** 채택한다. 빈 배열을 채택하면 모든 필터가
 * 상세로 숨어 첫 화면에 검색 조건이 하나도 보이지 않는다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:LoadVisibleFilters')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:LoadVisibleFilters]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:LoadVisibleFilters]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:LoadVisibleFilters]', ...args),
};

/**
 * 필터 표시/숨김 설정 로드 파라미터
 */
export interface LoadVisibleFiltersParams {
    /** 필터 ID (localStorage 키 생성에 사용 — FilterVisibilitySelector 의 id 와 같아야 한다) */
    id: string;
    /** 기본 표시 필터 목록 */
    defaultFilters?: string[];
}

/**
 * FilterVisibilitySelector 와 같은 규칙으로 localStorage 키를 만듭니다.
 *
 * @param id 필터 ID
 * @returns `g7_filters_{id}_{uuid}` (로그인 사용자가 없으면 `g7_filters_{id}`)
 */
export function buildVisibleFiltersStorageKey(id: string): string {
    let userId: string | undefined;

    try {
        const authManager = (window as any).G7Core?.AuthManager?.getInstance();
        if (authManager) {
            userId = authManager.getUser()?.uuid;
        }
    } catch {
        // 인증 정보 없음 — 공유 키 사용
    }

    return `g7_filters_${id}${userId !== undefined ? `_${userId}` : ''}`;
}

/**
 * 필터 표시/숨김 설정 로드 핸들러
 *
 * @example
 * ```html
 * <init>
 *   <action handler="loadVisibleFilters" id="admin_user_filters">
 *     <field name="defaultFilters"><item>status</item><item>role</item></field>
 *     <field name="resultTo">
 *       <field name="target" value="_local"/>
 *       <field name="key" value="visibleFilters"/>
 *     </field>
 *   </action>
 * </init>
 * ```
 *
 * @param action 액션 객체 (params 포함)
 * @returns 저장된 필터 목록 또는 기본값
 */
export function loadVisibleFiltersHandler(
    action: { params?: Partial<LoadVisibleFiltersParams> }
): string[] {
    const params = action?.params || {};
    const { id, defaultFilters = [] } = params;

    if (!id) {
        logger.warn('[loadVisibleFilters] id 파라미터가 필요합니다.');
        return defaultFilters;
    }

    const storageKey = buildVisibleFiltersStorageKey(id);

    try {
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (Array.isArray(parsed) && parsed.length > 0) {
                logger.log(`[loadVisibleFilters] localStorage에서 로드: ${storageKey}`, parsed);
                return parsed;
            }
        }
    } catch (e) {
        logger.warn('[loadVisibleFilters] LocalStorage 로드 실패:', e);
    }

    logger.log(`[loadVisibleFilters] 기본값 사용: ${storageKey}`, defaultFilters);
    return defaultFilters;
}
