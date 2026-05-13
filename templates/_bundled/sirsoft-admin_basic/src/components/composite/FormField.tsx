import React from 'react';
import { Div } from '../basic/Div';
import { Label } from '../basic/Label';

export interface FormFieldProps {
  /** 필드 레이블 */
  label?: string;
  /** 필수 필드 표시 여부 */
  required?: boolean;
  /** 에러 메시지 */
  error?: string;
  /** 도움말 텍스트 */
  helperText?: string;
  /** 추가 className */
  className?: string;
  /** 레이블 className */
  labelClassName?: string;
  /** 자식 요소 (필드 컨텐츠) */
  children?: React.ReactNode;
  /** 수평 레이아웃 여부 */
  horizontal?: boolean;
  /** 레이블 너비 (수평 레이아웃 시) */
  labelWidth?: string;
}

/**
 * FormField 컴포넌트
 *
 * 폼 필드에 레이블, 에러, 도움말을 제공하는 래퍼 컴포넌트입니다.
 * 수직(기본) 및 수평 레이아웃을 지원합니다.
 */
export const FormField: React.FC<FormFieldProps> = ({
  label,
  required = false,
  error,
  helperText,
  className = '',
  labelClassName = '',
  children,
  horizontal = false,
  labelWidth = 'w-1/3',
}) => {
  const containerClass = horizontal
    ? `flex items-start gap-4 ${className}`
    : `space-y-1 ${className}`;

  const labelContainerClass = horizontal ? `${labelWidth} flex-shrink-0 pt-2` : '';
  const contentContainerClass = horizontal ? 'flex-1' : '';

  return (
    <Div className={containerClass}>
      {label && (
        <Div className={labelContainerClass}>
          <Label
            className={`block text-sm font-medium text-gray-700 dark:text-gray-300 ${labelClassName}`}
          >
            {label}
            {required && (
              <span className="ml-1 text-red-500 dark:text-red-400">*</span>
            )}
          </Label>
        </Div>
      )}

      <Div className={`${contentContainerClass} ${error ? 'form-field-error' : ''}`.trim()}>
        {children}

        {helperText && !error && (
          <Div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {helperText}
          </Div>
        )}

        {error && (
          <Div className="mt-1 text-xs text-red-600 dark:text-red-400">
            {error}
          </Div>
        )}
      </Div>
    </Div>
  );
};
