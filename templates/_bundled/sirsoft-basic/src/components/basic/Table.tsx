import React from 'react';

export interface TableProps extends React.TableHTMLAttributes<HTMLTableElement> {}

/**
 * 기본 table 컴포넌트
 */
export const Table: React.FC<TableProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <table
      className={className}
      {...props}
    >
      {children}
    </table>
  );
};
