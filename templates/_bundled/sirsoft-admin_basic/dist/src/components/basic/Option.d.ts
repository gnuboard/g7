import { default as React } from 'react';
export interface OptionProps extends React.OptionHTMLAttributes<HTMLOptionElement> {
}
/**
 * 기본 option 컴포넌트 (Select 내부에서 사용)
 */
export declare const Option: React.FC<OptionProps>;
