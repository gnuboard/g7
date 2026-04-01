import React from 'react';

export interface SectionProps extends React.HTMLAttributes<HTMLElement> {}

/**
 * 기본 section 컴포넌트
 */
export const Section: React.FC<SectionProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <section
      className={className}
      {...props}
    >
      {children}
    </section>
  );
};
