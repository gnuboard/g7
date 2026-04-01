import React from 'react';

export interface NavProps extends React.HTMLAttributes<HTMLElement> {}

/**
 * 기본 nav 컴포넌트
 */
export const Nav: React.FC<NavProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <nav
      className={className}
      {...props}
    >
      {children}
    </nav>
  );
};
