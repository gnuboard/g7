import React from 'react';

export interface TdProps extends React.TdHTMLAttributes<HTMLTableCellElement> {}

/**
 * 기본 td(테이블 데이터 셀) 컴포넌트
 */
export const Td: React.FC<TdProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <td
      className={className}
      {...props}
    >
      {children}
    </td>
  );
};
