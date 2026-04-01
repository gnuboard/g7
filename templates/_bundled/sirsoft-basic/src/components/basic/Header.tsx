import React from 'react';

export interface HeaderProps extends React.HTMLAttributes<HTMLElement> {
  ref?: React.Ref<HTMLElement>;
}

/**
 * 기본 header 컴포넌트
 */
export const Header = React.forwardRef<HTMLElement, HeaderProps>(({
  children,
  className = '',
  ...props
}, ref) => {
  return (
    <header
      ref={ref}
      className={className}
      {...props}
    >
      {children}
    </header>
  );
});
