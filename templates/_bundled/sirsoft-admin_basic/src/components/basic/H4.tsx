import React from 'react';

export interface H4Props extends React.HTMLAttributes<HTMLHeadingElement> {}

/**
 * 기본 h4 제목 컴포넌트
 */
export const H4: React.FC<H4Props> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <h4
      className={className}
      {...props}
    >
      {children}
    </h4>
  );
};
