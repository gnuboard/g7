import { default as React } from 'react';
export interface IProps extends React.HTMLAttributes<HTMLElement> {
    className?: string;
    children?: React.ReactNode;
    style?: React.CSSProperties;
}
/**
 * I 기본 컴포넌트
 *
 * HTML <i> 태그를 래핑한 기본 컴포넌트입니다.
 * 주로 아이콘 폰트 (FontAwesome 등)와 함께 사용됩니다.
 *
 * @example
 * ```tsx
 * <I className="fas fa-user" />
 * <I className="fas fa-home" />
 * ```
 */
export declare const I: React.FC<IProps>;
