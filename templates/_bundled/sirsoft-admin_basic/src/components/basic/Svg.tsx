import React from 'react';

export interface SvgProps extends React.SVGAttributes<SVGSVGElement> {}

/**
 * 기본 svg 컴포넌트
 */
export const Svg: React.FC<SvgProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <svg
      className={className}
      {...props}
    >
      {children}
    </svg>
  );
};
