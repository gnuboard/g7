import React from 'react';

export interface CodeProps extends React.HTMLAttributes<HTMLElement> {}

/**
 * 기본 code 컴포넌트
 *
 * 인라인 코드 또는 코드 스니펫을 표시하기 위한 기본 컴포넌트입니다.
 * HTML <code> 태그를 래핑합니다.
 */
export const Code: React.FC<CodeProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <code
      className={className}
      {...props}
    >
      {children}
    </code>
  );
};
