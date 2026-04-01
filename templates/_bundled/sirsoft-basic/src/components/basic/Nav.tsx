import React, { forwardRef } from 'react';

export interface NavProps extends React.HTMLAttributes<HTMLElement> {}

/**
 * 기본 nav 컴포넌트
 */
export const Nav = forwardRef<HTMLElement, NavProps>(
  ({ children, className = '', ...props }, ref) => {
    return (
      <nav
        ref={ref}
        className={className}
        {...props}
      >
        {children}
      </nav>
    );
  }
);

Nav.displayName = 'Nav';
