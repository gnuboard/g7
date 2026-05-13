import React from 'react';

export interface AProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {}

/**
 * 기본 a(링크) 컴포넌트
 */
export const A: React.FC<AProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <a className={className} {...props}>
      {children}
    </a>
  );
};
