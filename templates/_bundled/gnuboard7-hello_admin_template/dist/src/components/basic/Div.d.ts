import { default as React } from 'react';
export interface DivProps extends React.HTMLAttributes<HTMLDivElement> {
    ref?: React.Ref<HTMLDivElement>;
}
/**
 * 기본 div 컴포넌트
 */
export declare const Div: React.ForwardRefExoticComponent<Omit<DivProps, "ref"> & React.RefAttributes<HTMLDivElement>>;
