import { default as React } from 'react';
export interface HrProps extends React.HTMLAttributes<HTMLHRElement> {
    ref?: React.Ref<HTMLHRElement>;
}
/**
 * 기본 hr (수평선) 컴포넌트
 */
export declare const Hr: React.ForwardRefExoticComponent<Omit<HrProps, "ref"> & React.RefAttributes<HTMLHRElement>>;
