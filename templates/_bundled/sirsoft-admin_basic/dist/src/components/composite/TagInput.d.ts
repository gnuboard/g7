import { default as React } from 'react';
export interface TagOption {
    value: string | number;
    label: string;
    count?: number;
    isDisabled?: boolean;
    /** 그룹명 (드롭다운에서 그룹 헤더로 표시) */
    group?: string;
    /** 상세 설명 (옵션 아래에 작은 글씨로 표시) */
    description?: string;
}
export interface GroupedOption {
    label: string;
    options: TagOption[];
}
export type TagVariant = 'blue' | 'green' | 'amber' | 'red' | 'purple' | 'gray';
export interface TagInputProps {
    /** 선택된 값 (멀티: 배열, 싱글: 단일 값 또는 배열) */
    value: (string | number)[] | string | number | null;
    /** 선택 가능한 옵션 목록 */
    options?: TagOption[];
    /** 값 변경 콜백 (이벤트 객체 또는 배열) */
    onChange: ((event: any) => void) | ((values: (string | number)[] | string | number | null) => void);
    /** 새 항목 추가 가능 여부 */
    creatable?: boolean;
    /** 입력창 placeholder */
    placeholder?: string;
    /** 검색 결과 없을 때 메시지 */
    noOptionsMessage?: string;
    /** 새 항목 추가 라벨 (문자열 또는 생성 함수) */
    formatCreateLabel?: string | ((inputValue: string) => string);
    /** 새 항목 추가 접미사 (기본값: "추가") */
    createLabelSuffix?: string;
    /** 최대 선택 개수 (멀티 모드에서만 유효) */
    maxItems?: number;
    /** 비활성화 여부 (boolean 또는 문자열 "true"/"false") */
    disabled?: boolean | string;
    /** 삭제 전 확인 콜백 (true: 허용, string: 불가 + 메시지) */
    onBeforeRemove?: (option: TagOption) => boolean | string;
    /** 새 옵션 생성 시 콜백 */
    onCreateOption?: (inputValue: string) => void;
    /** 추가 className */
    className?: string;
    /** 태그 값별 variant 맵핑 */
    tagVariants?: Record<string | number, TagVariant>;
    /** 기본 variant (지정되지 않은 태그) */
    defaultVariant?: TagVariant;
    /** 폼 필드 이름 (템플릿 엔진용) */
    name?: string;
    /** 다중 선택 여부 (기본값: true) */
    isMulti?: boolean;
    /** 선택 해제 가능 여부 (싱글 모드에서 유효, 기본값: true) */
    isClearable?: boolean;
    /** 검색 가능 여부 (기본값: true) */
    isSearchable?: boolean;
    /** 태그 구분자 배열 (creatable 모드에서 입력 시 자동 분리, 기본값: [',']) */
    delimiters?: string[];
    /** 붙여넣기 시 자동 분리 여부 (기본값: true) */
    splitOnPaste?: boolean;
    /** 검색 입력 변경 콜백 (비동기 검색용, 매 키 입력 시 호출) */
    onInputChange?: (event: any) => void;
}
/**
 * 태그 입력 컴포넌트
 *
 * 다중 선택이 가능한 태그 입력 컴포넌트입니다.
 * isMulti=false로 설정하면 단일 선택 모드로 동작합니다.
 * creatable 모드에서는 새 항목 추가가 가능합니다.
 */
export declare const TagInput: React.FC<TagInputProps>;
