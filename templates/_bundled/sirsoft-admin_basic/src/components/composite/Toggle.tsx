import React, { useState, useEffect } from 'react';

/**
 * G7Core 전역 객체 접근 헬퍼
 * 런타임에 접근하여 테스트 환경에서도 모킹이 적용되도록 함
 */
const getG7Core = () => (window as any).G7Core;

export interface ToggleProps {
  /** 체크 상태 */
  checked?: boolean;
  /** 체크 상태 변경 콜백 (boolean 또는 ChangeEvent 받음) */
  onChange?: ((checked: boolean) => void) | ((e: React.ChangeEvent<HTMLInputElement>) => void);
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 라벨 텍스트 */
  label?: string;
  /** 설명 텍스트 */
  description?: string;
  /** 토글 크기 */
  size?: 'sm' | 'md' | 'lg';
  /** 추가 className */
  className?: string;
  /** name 속성 */
  name?: string;
  /** 값 (checked의 대체) */
  value?: boolean;
}

/**
 * Toggle 스위치 컴포넌트
 *
 * Flowbite 스타일의 토글 스위치입니다.
 * 라이트/다크 모드를 모두 지원합니다.
 * 반응형으로 prop 변경을 추적합니다.
 */
export const Toggle: React.FC<ToggleProps> = ({
  checked: checkedProp,
  value: valueProp,
  onChange,
  disabled = false,
  label,
  description,
  size = 'md',
  className = '',
  name,
}) => {
  // 내부 상태로 관리
  const [checked, setChecked] = useState(() => checkedProp ?? valueProp ?? false);

  // prop 변경 시 내부 상태 동기화
  useEffect(() => {
    const newValue = checkedProp ?? valueProp ?? false;
    setChecked(newValue);
  }, [checkedProp, valueProp]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newChecked = e.target.checked;

    // 내부 상태 즉시 업데이트
    setChecked(newChecked);

    if (onChange && !disabled) {
      // 템플릿 엔진의 actions는 이벤트 객체를 받으므로 항상 이벤트로 전달
      (onChange as any)(e);
    }
  };

  // 크기별 클래스
  const sizeClasses = {
    sm: {
      track: 'w-9 h-5',
      thumb: 'after:h-4 after:w-4',
    },
    md: {
      track: 'w-11 h-6',
      thumb: 'after:h-5 after:w-5',
    },
    lg: {
      track: 'w-14 h-7',
      thumb: 'after:h-6 after:w-6',
    },
  };

  const currentSize = sizeClasses[size];

  const handleToggleClick = () => {
    if (!disabled) {
      const newChecked = !checked;
      setChecked(newChecked);
      if (onChange) {
        const G7Core = getG7Core();
        if (G7Core?.createChangeEvent) {
          (onChange as any)(G7Core.createChangeEvent({ checked: newChecked, name }));
        }
      }
    }
  };

  return (
    <div className={`inline-flex flex-wrap items-start gap-x-2 w-auto ${className}`}>
      {/* 토글 스위치 */}
      <div
        className={`relative inline-flex items-center flex-shrink-0 ${
          disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
        }`}
        onClick={(e) => {
          e.preventDefault();
          e.stopPropagation();
          handleToggleClick();
        }}
      >
        <input
          type="checkbox"
          id={name}
          name={name}
          checked={checked}
          onChange={handleChange}
          disabled={disabled}
          className="sr-only peer"
          tabIndex={-1}
        />
        <div
          className={`
            relative ${currentSize.track}
            bg-gray-200 dark:bg-gray-700
            rounded-full
            peer
            peer-focus:outline-none
            peer-focus:ring-4
            peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800
            peer-checked:bg-blue-600 dark:peer-checked:bg-blue-500
            after:content-['']
            after:absolute
            after:top-0.5
            after:left-0.5
            after:bg-white
            after:border-gray-300 dark:after:border-gray-600
            after:border
            after:rounded-full
            ${currentSize.thumb}
            after:transition-all
            peer-checked:after:translate-x-full
            peer-checked:after:border-white
            transition-colors
          `}
        />
      </div>

      {/* 라벨 (토글 옆) */}
      {label && (
        <span
          className={`text-sm font-medium text-gray-900 dark:text-white ${
            disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
          }`}
          onClick={handleToggleClick}
        >
          {label}
        </span>
      )}

      {/* 디스크립션 (다음 줄) */}
      {description && (
        <p className="w-full mt-1 text-xs text-gray-500 dark:text-gray-400">
          {description}
        </p>
      )}
    </div>
  );
};
