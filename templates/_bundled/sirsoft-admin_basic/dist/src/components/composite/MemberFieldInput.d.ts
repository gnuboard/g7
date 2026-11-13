import { default as React } from 'react';
import { EditorAttrs } from '../../types';
/** 「기타(직접 입력)」 선택지의 값 — 서버 `FieldValue::OTHER_INDEX` 와 같다 */
export declare const MEMBER_FIELD_OTHER = "__other__";
export interface MemberFieldOption {
    value: string;
    label: string;
}
/** 서버가 주는 항목 계약 (`GET /api/admin/users/form-fields` 등) */
export interface MemberFieldContract {
    key: string;
    name: string;
    type: string;
    renderer?: string;
    label?: string;
    placeholder?: string | null;
    help?: string | null;
    required?: boolean;
    readonly?: boolean;
    locked_reason?: string | null;
    options?: MemberFieldOption[];
    constraints?: Record<string, any>;
    default?: any;
}
export interface MemberFieldInputProps {
    /** 항목 계약 */
    field: MemberFieldContract;
    /** 폼 경로 (자동 바인딩의 필드 이름) */
    name?: string;
    /** 현재 값 */
    value?: any;
    /** 값 변경 콜백 (엔진 변경 이벤트를 받는다) */
    onChange?: (event: any) => void;
    /** 이 칸의 검증 오류 문구 (있으면 빨간 테두리와 aria-invalid) */
    error?: string | null;
    /** 오류 문구 노드의 id (aria-describedby 로 잇는다) */
    errorId?: string;
    /** 비활성 여부 (저장 중 등) */
    disabled?: boolean;
    /** 시간대 선택지 (생략하면 전역 `appConfig.supportedTimezones` 를 읽는다) */
    timezones?: Array<string | MemberFieldOption>;
    /** 입력칸에 붙일 클래스 (생략하면 관리자 템플릿의 입력칸 기본 모양) */
    inputClassName?: string;
    /** 오류가 있을 때 입력칸에 붙일 클래스 */
    errorClassName?: string;
    /** 루트 클래스 */
    className?: string;
    /** DOM id (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * 국가 선택 목록의 국가 코드(ISO 3166-1 두 글자). 이름은 다국어 `countries.{코드}` 에서 읽는다.
 *
 * 회원 목록의 국가 필터(`admin_user_list` 의 `countryList`)와 같은 목록이어야 한다 — 여기서 고른 값을 목록이 찾는다.
 */
export declare const MEMBER_FIELD_COUNTRY_CODES: string[];
/**
 * 날짜 제한 값(날짜 글자 또는 `today`, `after_today`)을 입력칸의 min/max 로 바꾼다.
 *
 * 화면의 제한은 편의일 뿐이고 판정은 서버가 사이트 시간대로 한다 — 그래서 상대 기준은 브라우저의 오늘로 어림한다.
 *
 * @param bound 제한 값
 * @param side 가장 이른 날(min)인지 가장 늦은 날(max)인지
 * @param withTime 날짜+시간 입력칸인지
 * @returns 입력칸에 줄 값 (없으면 undefined)
 */
export declare function resolveDateBound(bound: unknown, side: 'min' | 'max', withTime?: boolean): string | undefined;
/**
 * 회원 항목 입력칸
 *
 * @example
 * ```html
 * <member-field-input data-type="composite" field="{{field}}" name="{{field.name}}" error="{{_local.errors?.[field.name]?.[0]}}"/>
 * ```
 */
export declare const MemberFieldInput: React.FC<MemberFieldInputProps>;
export default MemberFieldInput;
