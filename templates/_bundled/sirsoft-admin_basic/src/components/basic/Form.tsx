import React from 'react';

export interface FormProps extends React.FormHTMLAttributes<HTMLFormElement> {}

/**
 * 기본 form 컴포넌트
 */
export const Form: React.FC<FormProps> = ({
  children,
  className = '',
  ...props
}) => {
  return (
    <form
      className={className}
      {...props}
    >
      {children}
    </form>
  );
};
