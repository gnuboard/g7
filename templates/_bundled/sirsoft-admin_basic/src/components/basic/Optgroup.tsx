import React from 'react';

export interface OptgroupProps extends React.OptgroupHTMLAttributes<HTMLOptGroupElement> {}

/**
 * 기본 optgroup 컴포넌트 (Select 내부에서 옵션 그룹화)
 */
export const Optgroup: React.FC<OptgroupProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <optgroup
      className={className}
      {...props}
    >
      {children}
    </optgroup>
  );
};
