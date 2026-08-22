import React, { useState, useEffect } from 'react';
import type { EditorAttrs } from '../../types';

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
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * Toggle 스위치 컴포넌트
 *
 * Flowbite 스타일의 토글 스위치입니다.
 * 라이트/다크 모드를 모두 지원합니다.
 * 반응형으로 prop 변경을 추적합니다.
 *
 * 접근성: 실제 checkbox 는 sr-only 로 숨기고 보이는 트랙이 조작 대상이므로, 그 트랙에
 * `role="switch"` + `aria-checked` + `tabIndex` 를 부여하고 Space/Enter 키를 처리합니다.
 * 이 처리가 없으면 마우스 없이는 토글을 전혀 조작할 수 없고 보조기기에도 스위치로 읽히지
 * 않습니다. 옆 라벨은 sibling 이라 `label[for]` 로 연결되지 않으므로 `aria-label` 로 이름을
 * 직접 부여합니다.
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
  id,
  editorAttrs,
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

  /**
   * 키보드 조작 — Space / Enter 로 전환한다 (WAI-ARIA switch 규약).
   *
   * 실제 checkbox 는 sr-only + tabIndex=-1 이라 초점을 받지 않으므로, 초점을 받는 래퍼가
   * 키 입력을 처리해야 한다. 이 처리가 없으면 마우스 없이는 토글을 전혀 쓸 수 없다.
   *
   * @param e 키보드 이벤트
   */
  const handleToggleKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (disabled) return;
    if (e.key !== ' ' && e.key !== 'Enter' && e.key !== 'Spacebar') return;

    // Space 는 기본 동작이 스크롤이므로 막는다.
    e.preventDefault();
    handleToggleClick();
  };

  return (
    <div className={`toggle-container ${className}`} id={id} {...editorAttrs}>
      {/* 토글 스위치 */}
      <div
        className={`toggle-switch-wrapper ${
          disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
        }`}
        role="switch"
        aria-checked={checked}
        aria-disabled={disabled || undefined}
        aria-label={label ?? name}
        tabIndex={disabled ? -1 : 0}
        onKeyDown={handleToggleKeyDown}
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
        <div className={`toggle-switch-track ${currentSize.track} ${currentSize.thumb}`} />
      </div>

      {/* 라벨 (토글 옆) */}
      {label && (
        <span
          className={`toggle-label ${
            disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
          }`}
          onClick={handleToggleClick}
        >
          {label}
        </span>
      )}

      {/* 디스크립션 (다음 줄) */}
      {description && <p className="toggle-description">{description}</p>}
    </div>
  );
};
