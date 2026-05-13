import React, { useState, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Label } from '../basic/Label';
import { Span } from '../basic/Span';

export interface RadioOption {
  /** 라디오 버튼의 값 */
  value: string;
  /** 라디오 버튼의 라벨 */
  label: string;
  /** 비활성화 여부 */
  disabled?: boolean;
}

export interface RadioGroupProps {
  /** 그룹 이름 (form 전송 시 사용) */
  name: string;
  /** 현재 선택된 값 */
  value?: string;
  /** 라디오 옵션 배열 */
  options: RadioOption[];
  /** 값 변경 콜백 */
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 인라인 표시 여부 (기본값: false, 수직 배치) */
  inline?: boolean;
  /** 추가 className */
  className?: string;
  /** 라디오 버튼 크기 */
  size?: 'sm' | 'md' | 'lg';
  /** 그룹 라벨 */
  label?: string;
  /** 에러 메시지 */
  error?: string;
}

/**
 * RadioGroup 컴포넌트
 *
 * 여러 라디오 버튼을 그룹으로 묶어 관리합니다.
 * Flowbite 스타일을 따르며, 라이트/다크 모드를 모두 지원합니다.
 */
export const RadioGroup: React.FC<RadioGroupProps> = ({
  name,
  value: valueProp,
  options,
  onChange,
  disabled = false,
  inline = false,
  className = '',
  size = 'md',
  label,
  error,
}) => {
  // 내부 상태로 관리
  const [value, setValue] = useState(() => valueProp ?? '');

  // prop 변경 시 내부 상태 동기화
  useEffect(() => {
    setValue(valueProp ?? '');
  }, [valueProp]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;

    // 내부 상태 즉시 업데이트
    setValue(newValue);

    if (onChange && !disabled) {
      // 템플릿 엔진의 actions는 이벤트 객체를 받으므로 항상 이벤트로 전달
      onChange(e);
    }
  };

  // 크기별 클래스
  const sizeClasses = {
    sm: {
      radio: 'w-3.5 h-3.5',
      label: 'text-xs',
      gap: 'gap-1.5',
    },
    md: {
      radio: 'w-4 h-4',
      label: 'text-sm',
      gap: 'gap-2',
    },
    lg: {
      radio: 'w-5 h-5',
      label: 'text-base',
      gap: 'gap-2.5',
    },
  };

  const currentSize = sizeClasses[size];

  // 레이아웃 클래스
  const layoutClass = inline ? 'flex flex-wrap items-center gap-4' : 'flex flex-col gap-2';

  return (
    <Div className={className}>
      {/* 그룹 라벨 */}
      {label && (
        <Span
          className={`block mb-2 font-medium text-gray-700 dark:text-gray-300 ${currentSize.label}`}
        >
          {label}
        </Span>
      )}

      {/* 라디오 버튼 목록 */}
      <Div className={layoutClass}>
        {options.map((option) => {
          const isChecked = String(option.value) === String(value);
          const isDisabled = disabled || option.disabled;

          return (
            <Label
              key={option.value}
              className={`inline-flex items-center ${currentSize.gap} ${
                isDisabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
              }`}
            >
              <input
                type="radio"
                name={name}
                value={option.value}
                checked={isChecked}
                onChange={handleChange}
                disabled={isDisabled}
                className={`
                  ${currentSize.radio}
                  text-blue-600
                  bg-gray-100 dark:bg-gray-700
                  border-gray-300 dark:border-gray-600
                  focus:ring-blue-500 dark:focus:ring-blue-600
                  focus:ring-2
                  dark:ring-offset-gray-800
                  ${isDisabled ? 'cursor-not-allowed' : 'cursor-pointer'}
                `}
              />
              <Span
                className={`
                  ${currentSize.label}
                  font-medium
                  text-gray-900 dark:text-gray-300
                `}
              >
                {option.label}
              </Span>
            </Label>
          );
        })}
      </Div>

      {/* 에러 메시지 */}
      {error && (
        <Span className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</Span>
      )}
    </Div>
  );
};
