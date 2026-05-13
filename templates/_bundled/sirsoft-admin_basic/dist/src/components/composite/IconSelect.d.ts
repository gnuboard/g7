import { default as React } from 'react';
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
}
/**
 * IconSelect 컴포넌트
 * 아이콘 선택 드롭다운
 */
export declare const IconSelect: React.FC<IconSelectProps>;
