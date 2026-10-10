/**
 * 회원 목록 확장 필터 값 조립 핸들러 (#705)
 *
 * 모듈·플러그인이 회원관리 목록 필터에 자기 조건을 더할 때 쓰는 값 채널이다.
 * 엔진의 navigate `<query>` 와 데이터소스 `<params>` 는 속성 맵이라 확장이 키를 동적으로 늘릴 수
 * 없다. 그래서 코어가 배열 칸 하나(`ext_filters[]`)를 열고, 원소마다 `{확장id}.{키}={값}` 을 싣는다.
 *
 * 확장은 원소 문자열을 직접 만들지 않고 이 핸들러를 부른다 — 같은 키는 교체하고 빈 값은 지운
 * **새 배열**을 돌려주므로, 결과를 `$prev` 로 받아 `_local.userFilterExtraQuery` 에 담으면 된다.
 *
 * ```html
 * <sequence>
 *   <action handler="setUserFilterExt" key="sirsoft-ecommerce.mileage_min" value="{{$event.target.value}}"/>
 *   <set-state target="_local" userFilterExtraQuery="{{$prev}}"/>
 * </sequence>
 * ```
 *
 * 키 형식은 서버 검증(`UserListRequest::EXT_FILTER_PATTERN`)과 같다 — 소문자·숫자·`_`·`.`·`-` 만,
 * 확장id 와 키 사이에 점 하나 이상. 형식이 어긋난 키는 무시하고 기존 배열을 그대로 돌려준다.
 */
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
 * 확장 필터 배열에서 한 키를 교체·제거한 새 배열을 만듭니다.
 *
 * @param current 기존 배열
 * @param key `{확장id}.{키}`
 * @param value 값 (빈 값이면 제거)
 * @returns 새 배열
 */
export declare function applyUserFilterExt(current: string[], key: string, value: SetUserFilterExtParams['value']): string[];
/**
 * 회원 목록 확장 필터 값 조립 핸들러
 *
 * @param action 액션 정의 (params: key, value, current)
 * @param context 액션 컨텍스트
 * @returns 갱신된 확장 필터 배열
 */
export declare function setUserFilterExtHandler(action: {
    params?: Partial<SetUserFilterExtParams>;
}, context?: {
    data?: any;
    state?: any;
}): string[];
