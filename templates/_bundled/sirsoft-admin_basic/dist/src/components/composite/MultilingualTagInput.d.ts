import { default as React } from 'react';
/** 다국어 값 객체 */
export interface MultilingualValue {
    [locale: string]: string;
}
export interface MultilingualTagInputProps {
    /** 다국어 태그 배열 [{ko: "빨강", en: "Red"}, ...] */
    value?: MultilingualValue[];
    /** 값 변경 콜백 */
    onChange?: (event: any) => void;
    /** 입력창 placeholder */
    placeholder?: string;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 새 항목 추가 가능 여부 (기본값: true) */
    creatable?: boolean;
    /** 태그 구분자 배열 (creatable 모드에서 입력 시 자동 분리, 기본값: [',']) */
    delimiters?: string[];
    /** 추가 className */
    className?: string;
    /** 폼 필드 이름 (템플릿 엔진용) */
    name?: string;
    /** 에러 메시지 */
    error?: string;
    /** 최대 태그 개수 */
    maxItems?: number;
    /**
     * 외부 모달 ID (레이아웃 JSON의 modals 섹션에 정의된 모달)
     * 지정 시 내장 모달 대신 openModal 핸들러로 외부 모달을 엽니다.
     * 외부 모달은 _global.multilingualTagEdit에서 편집 정보를 읽고,
     * 저장 시 setParentLocal()로 부모 상태를 직접 수정해야 합니다.
     */
    modalId?: string;
    /**
     * 부모 상태에서의 실제 데이터 경로 (점 표기법, 예: "ui.optionInputs.0.values")
     * modalId와 함께 사용하며, 핸들러에서 setParentLocal() 호출 시 이 경로를 사용합니다.
     * 미지정 시 name prop을 기반으로 "form.{name}" 경로를 사용합니다.
     */
    statePath?: string;
    /**
     * 컴포넌트 컨텍스트 (DynamicRenderer에서 자동 전달)
     * openModal 호출 시 부모 컨텍스트를 정확히 지정하기 위해 사용됩니다.
     * @internal
     */
    __componentContext?: {
        state: Record<string, any>;
        setState: (updates: any) => void;
    };
}
/**
 * 다국어 태그 입력 컴포넌트
 *
 * 각 태그가 다국어 값을 가지는 태그 입력 컴포넌트입니다.
 * 태그 추가/수정 시 모달을 통해 모든 언어의 값을 입력받습니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "MultilingualTagInput",
 *   "props": {
 *     "value": "{{form.optionValues}}",
 *     "placeholder": "옵션값 입력 후 Enter"
 *   },
 *   "actions": [{
 *     "type": "change",
 *     "handler": "setState",
 *     "params": { "form.optionValues": "{{$event.target.value}}" }
 *   }]
 * }
 */
export declare const MultilingualTagInput: React.FC<MultilingualTagInputProps>;
