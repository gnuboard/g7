import React from 'react';

export interface TbodyProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

/**
 * 기본 tbody 컴포넌트
 */
export const Tbody: React.FC<TbodyProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <tbody
      className={className}
      {...props}
    >
      {children}
    </tbody>
  );
};
