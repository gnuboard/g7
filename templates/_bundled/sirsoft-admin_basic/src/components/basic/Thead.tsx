import React from 'react';

export interface TheadProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

/**
 * 기본 thead 컴포넌트
 */
export const Thead: React.FC<TheadProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <thead
      className={className}
      {...props}
    >
      {children}
    </thead>
  );
};
