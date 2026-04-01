import React from 'react';

export interface ThProps extends React.ThHTMLAttributes<HTMLTableCellElement> {}

/**
 * 기본 th(테이블 헤더 셀) 컴포넌트
 */
export const Th: React.FC<ThProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <th
      className={className}
      {...props}
    >
      {children}
    </th>
  );
};
