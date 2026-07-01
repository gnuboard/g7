import { default as React } from 'react';
export interface SelectOption {
    value: string | number;
    label: string;
    disabled?: boolean;
}
export interface SelectProps extends Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'onChange'> {
    label?: string;
    error?: string;
    options?: SelectOption[] | string[];
    onChange?: (e: React.ChangeEvent<HTMLSelectElement> | {
        target: {
            value: string | number;
        };
    }) => void;
    /** 드롭다운 내 검색 input 활성화 (engine-v1.40.0+) */
    searchable?: boolean;
    /** 검색 input placeholder */
    searchPlaceholder?: string;
    /**
     * 레이아웃 편집기 주입 속성 (편집 모드 전용). Select 는 커스텀 드롭다운 루트에
     * 개별 data-editor-* 키를 spread 해야 선택/편집이 닿는다(커스텀 루트
     * passthrough). basic 컴포넌트라 `{...props}` 로 DOM 표준 속성(id 등)도 함께 흐른다.
     */
    editorAttrs?: Record<string, unknown>;
}
/**
 * 커스텀 Select 컴포넌트
 *
 * options prop을 사용하여 드롭다운 메뉴를 생성합니다.
 * 둥근 모서리, 그림자, 체크마크가 있는 커스텀 스타일을 지원합니다.
 */
export declare const Select: React.FC<SelectProps>;
