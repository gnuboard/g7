import React from 'react';

export interface LiProps extends React.LiHTMLAttributes<HTMLLIElement> {}

/**
 * 기본 li(목록 항목) 컴포넌트
 */
export const Li: React.FC<LiProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <li
      className={className}
      {...props}
    >
      {children}
    </li>
  );
};
