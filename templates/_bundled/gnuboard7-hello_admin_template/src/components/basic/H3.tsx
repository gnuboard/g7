import React from 'react';

export interface H3Props extends React.HTMLAttributes<HTMLHeadingElement> {}

/**
 * 기본 h3 제목 컴포넌트
 */
export const H3: React.FC<H3Props> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <h3 className={className} {...props}>
      {children}
    </h3>
  );
};
