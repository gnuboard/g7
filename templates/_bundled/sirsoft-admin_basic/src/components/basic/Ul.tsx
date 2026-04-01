import React from 'react';

export interface UlProps extends React.HTMLAttributes<HTMLUListElement> {}

/**
 * 기본 ul(순서없는 목록) 컴포넌트
 */
export const Ul: React.FC<UlProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <ul
      className={className}
      {...props}
    >
      {children}
    </ul>
  );
};
