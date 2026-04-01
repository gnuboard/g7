import React from 'react';

export interface LabelProps extends React.LabelHTMLAttributes<HTMLLabelElement> {}

/**
 * 기본 레이블 컴포넌트
 */
export const Label: React.FC<LabelProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <label
      className={className}
      {...props}
    >
      {children}
    </label>
  );
};
