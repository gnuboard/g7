import React from 'react';

export interface HrProps extends React.HTMLAttributes<HTMLHRElement> {}

/**
 * 기본 hr(수평선) 컴포넌트
 */
export const Hr: React.FC<HrProps> = ({
  className = '',
  ...props
}) => {
  return (
    <hr
      className={className}
      {...props}
    />
  );
};
