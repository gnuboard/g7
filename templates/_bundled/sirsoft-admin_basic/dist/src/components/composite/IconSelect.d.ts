import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface IconOption {
    value: string;
    label: string;
    faIcon: string;
}
export interface IconSelectProps {
    value?: string;
    onChange?: (value: string) => void;
    options?: IconOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    noResultsText?: string;
    className?: string;
    disabled?: boolean;
    name?: string;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
export declare const defaultIconOptions: IconOption[];
/**
 * IconSelect 컴포넌트
 * 아이콘 선택 드롭다운
 */
export declare const IconSelect: React.FC<IconSelectProps>;
