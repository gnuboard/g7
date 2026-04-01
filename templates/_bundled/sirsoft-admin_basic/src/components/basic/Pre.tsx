import React from 'react';

export interface PreProps extends React.HTMLAttributes<HTMLPreElement> {}

/**
 * 기본 pre 컴포넌트
 *
 * 서식이 지정된 텍스트를 표시하기 위한 기본 컴포넌트입니다.
 * HTML <pre> 태그를 래핑합니다.
 */
export const Pre: React.FC<PreProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <pre
      className={className}
      {...props}
    >
      {children}
    </pre>
  );
};
