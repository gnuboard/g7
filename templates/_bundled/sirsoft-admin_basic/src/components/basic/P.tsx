import React from 'react';

export interface PProps extends React.HTMLAttributes<HTMLParagraphElement> {}

/**
 * 기본 p(단락) 컴포넌트
 */
export const P: React.FC<PProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <p
      className={className}
      {...props}
    >
      {children}
    </p>
  );
};
