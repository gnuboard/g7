/**
 * 회원 목록 확장 필터 값 조립 핸들러
 *
 * 관리자 회원 목록 필터에 이 모듈이 더하는 행(마일리지 잔액·배송지)이 쓴다. 코어 API 는 회원 목록
 * 요청의 배열 칸 하나(`ext_filters[]`)만 열고, 원소마다 `{확장id}.{키}={값}` 을 받는다. 엔진의
 * navigate `<query>` 와 데이터소스 `<params>` 는 속성 맵이라 확장이 키를 동적으로 늘릴 수 없으므로,
 * 행은 원소 문자열을 직접 만들지 않고 이 핸들러로 같은 키는 교체하고 빈 값은 지운 **새 배열**을 받아
 * `_local.userFilterExtraQuery` 에 담는다. 그 배열을 `ext_filters[]` 로 보내는 것은 확장점을 연
 * 관리자 템플릿의 검색 버튼이다.
 *
 * 이 모듈이 직접 소유한다 — 확장 조각은 어느 관리자 템플릿으로 그려지든 같은 동작을 해야 하므로
 * 특정 템플릿이 등록한 핸들러에 기대지 않는다. 템플릿과 공유하는 것은 확장점의 상태 칸
 * (`_local.userFilterExtraQuery`)과 코어 API 형식뿐이다.
 *
 * ```html
 * <action handler="sirsoft-ecommerce.setUserFilterExt" key="sirsoft-ecommerce.mileage_min"
 *         value="{{$event.target.value}}" current="{{_local.userFilterExtraQuery || []}}"/>
 * ```
 *
 * 키 형식은 서버 검증(`UserListRequest::EXT_FILTER_PATTERN`)과 같다 — 소문자·숫자·`_`·`.`·`-` 만,
 * 확장id 와 키 사이에 점 하나 이상. 형식이 어긋난 키는 무시하고 기존 배열을 그대로 돌려준다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecommerce:UserListFilterExt')) ?? {
    log: (...args: unknown[]) => console.log('[Ecommerce:UserListFilterExt]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecommerce:UserListFilterExt]', ...args),
    error: (...args: unknown[]) => console.error('[Ecommerce:UserListFilterExt]', ...args),
};

/** 키 형식 — `{확장id}.{키}` */
const KEY_PATTERN = /^[a-z0-9_-]+\.[a-z0-9_.-]+$/;

/** 값 최대 길이 (서버 정규식 `[^&]{0,255}` 과 같다) */
const MAX_VALUE_LENGTH = 255;

/**
 * 파라미터
 */
export interface SetUserFilterExtParams {
    /** `{확장id}.{키}` (예: `sirsoft-ecommerce.mileage_min`) */
    key: string;
    /** 값. 빈 값·null·undefined 면 그 키를 지운다 */
    value?: string | number | boolean | null;
    /** 갱신할 기존 배열. 생략하면 현재 `_local.userFilterExtraQuery` 를 읽는다 */
    current?: string[] | null;
}

/**
 * 컨텍스트에서 현재 확장 필터 배열을 읽습니다.
 *
 * @param context 액션 컨텍스트
 * @returns 현재 배열 (없으면 빈 배열)
 */
function readCurrent(context?: { data?: any; state?: any }): string[] {
    const fromLocal = context?.data?._local?.userFilterExtraQuery ?? context?.state?.userFilterExtraQuery;
    if (Array.isArray(fromLocal)) {
        return fromLocal.filter((v: unknown): v is string => typeof v === 'string');
    }

    try {
        const local = (window as any).G7Core?.state?.getLocal?.();
        if (Array.isArray(local?.userFilterExtraQuery)) {
            return local.userFilterExtraQuery.filter((v: unknown): v is string => typeof v === 'string');
        }
    } catch {
        // 상태 접근 불가 — 빈 배열로 시작
    }

    return [];
}

/**
 * 확장 필터 배열에서 한 키를 교체·제거한 새 배열을 만듭니다.
 *
 * @param current 기존 배열
 * @param key `{확장id}.{키}`
 * @param value 값 (빈 값이면 제거)
 * @returns 새 배열
 */
export function applyUserFilterExt(current: string[], key: string, value: SetUserFilterExtParams['value']): string[] {
    const prefix = `${key}=`;
    const rest = current.filter((entry) => typeof entry === 'string' && !entry.startsWith(prefix));

    if (value === null || value === undefined || value === '' || value === false) {
        return rest;
    }

    const text = String(value).replace(/&/g, '').slice(0, MAX_VALUE_LENGTH);
    if (text === '') {
        return rest;
    }

    return [...rest, `${key}=${text}`];
}

/**
 * 회원 목록 확장 필터 값 조립 핸들러
 *
 * @param action 액션 정의 (params: key, value, current)
 * @param context 액션 컨텍스트
 * @returns 갱신된 확장 필터 배열
 */
export function setUserFilterExtHandler(
    action: { params?: Partial<SetUserFilterExtParams> },
    context?: { data?: any; state?: any }
): string[] {
    const params = action?.params || {};
    const current = Array.isArray(params.current) ? params.current : readCurrent(context);
    const key = typeof params.key === 'string' ? params.key.trim() : '';

    if (!KEY_PATTERN.test(key)) {
        logger.warn('[setUserFilterExt] 키 형식이 올바르지 않습니다 ({확장id}.{키}):', params.key);
        return current;
    }

    return applyUserFilterExt(current, key, params.value);
}
