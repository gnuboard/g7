import React from 'react';

export interface TfootProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

/**
 * 기본 tfoot 컴포넌트
 */
export const Tfoot: React.FC<TfootProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <tfoot
      className={className}
      {...props}
    >
      {children}
    </tfoot>
  );
};
