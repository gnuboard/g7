import React, { forwardRef } from 'react';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {}

/**
 * 기본 버튼 컴포넌트 (type="button" 기본값으로 submit 방지)
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({
  children,
  className = '',
  type = 'button',
  ...props
}, ref) => {
  return (
    <button
      ref={ref}
      type={type}
      className={className}
      {...props}
    >
      {children}
    </button>
  );
});

Button.displayName = 'Button';
