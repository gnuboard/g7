import React from 'react';

export interface TrProps extends React.HTMLAttributes<HTMLTableRowElement> {}

/**
 * 기본 tr(테이블 행) 컴포넌트
 */
export const Tr: React.FC<TrProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <tr
      className={className}
      {...props}
    >
      {children}
    </tr>
  );
};
