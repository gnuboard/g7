import React from 'react';

export interface OlProps extends React.HTMLAttributes<HTMLOListElement> {}

/**
 * 기본 ol(순서있는 목록) 컴포넌트
 */
export const Ol: React.FC<OlProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <ol
      className={className}
      {...props}
    >
      {children}
    </ol>
  );
};
