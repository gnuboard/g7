import React, { forwardRef, useEffect, useRef } from 'react';

export interface CheckboxProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  /**
   * indeterminate 상태 (부분 선택 상태)
   *
   * 부모-자식 체크박스 연동에서 일부 자식만 선택되었을 때 사용합니다.
   * HTML checkbox의 indeterminate 속성을 JavaScript로 설정합니다.
   */
  indeterminate?: boolean;
}

/**
 * 기본 체크박스 컴포넌트
 *
 * forwardRef를 사용하여 ref 전달을 지원합니다.
 * indeterminate prop을 통해 부분 선택 상태를 표시할 수 있습니다.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
  ({ label, className = '', indeterminate = false, ...props }, ref) => {
    const internalRef = useRef<HTMLInputElement>(null);

    // 외부 ref와 내부 ref를 병합
    const combinedRef = (node: HTMLInputElement | null) => {
      internalRef.current = node;
      if (typeof ref === 'function') {
        ref(node);
      } else if (ref) {
        ref.current = node;
      }
    };

    // indeterminate 상태 설정 (DOM 속성이므로 JavaScript로 설정해야 함)
    useEffect(() => {
      if (internalRef.current) {
        internalRef.current.indeterminate = indeterminate;
      }
    }, [indeterminate]);

    return (
      <input
        ref={combinedRef}
        type="checkbox"
        className={className}
        {...props}
      />
    );
  }
);

Checkbox.displayName = 'Checkbox';
