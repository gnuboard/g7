import { default as React } from 'react';
export interface HeaderProps extends React.HTMLAttributes<HTMLElement> {
    ref?: React.Ref<HTMLElement>;
}
/**
 * 기본 header 컴포넌트
 */
export declare const Header: React.ForwardRefExoticComponent<Omit<HeaderProps, "ref"> & React.RefAttributes<HTMLElement>>;
