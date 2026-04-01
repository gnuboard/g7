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
}
/**
 * 커스텀 Select 컴포넌트
 *
 * options prop을 사용하여 드롭다운 메뉴를 생성합니다.
 * 둥근 모서리, 그림자, 체크마크가 있는 커스텀 스타일을 지원합니다.
 */
export declare const Select: React.FC<SelectProps>;
