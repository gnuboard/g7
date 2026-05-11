import React from 'react';

export interface DivProps extends React.HTMLAttributes<HTMLDivElement> {
  ref?: React.Ref<HTMLDivElement>;
}

/**
 * 기본 div 컴포넌트
 */
export const Div = React.forwardRef<HTMLDivElement, DivProps>(({
  children,
  className = '',
  ...props
}, ref) => {
  return (
    <div
      ref={ref}
      className={className}
      {...props}
    >
      {children}
    </div>
  );
});

Div.displayName = 'Div';
