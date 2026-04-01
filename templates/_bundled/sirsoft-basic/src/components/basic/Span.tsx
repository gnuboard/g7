import React from 'react';

export interface SpanProps extends React.HTMLAttributes<HTMLSpanElement> {}

/**
 * 기본 span 컴포넌트
 */
export const Span: React.FC<SpanProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <span
      className={className}
      {...props}
    >
      {children}
    </span>
  );
};
