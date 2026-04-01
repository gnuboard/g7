import React, { forwardRef } from 'react';

export interface CheckboxProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
}

/**
 * 기본 체크박스 컴포넌트
 *
 * forwardRef를 사용하여 ref 전달을 지원합니다.
 * indeterminate 상태 등을 설정할 때 ref가 필요합니다.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
  ({ label, className = '', ...props }, ref) => {
    return (
      <input
        ref={ref}
        type="checkbox"
        className={className}
        {...props}
      />
    );
  }
);

Checkbox.displayName = 'Checkbox';
