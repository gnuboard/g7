import React from 'react';

export interface FooterProps extends React.HTMLAttributes<HTMLElement> {
  ref?: React.Ref<HTMLElement>;
}

/**
 * 기본 footer 컴포넌트
 */
export const Footer = React.forwardRef<HTMLElement, FooterProps>(({
  children,
  className = '',
  ...props
}, ref) => {
  return (
    <footer
      ref={ref}
      className={className}
      {...props}
    >
      {children}
    </footer>
  );
});
