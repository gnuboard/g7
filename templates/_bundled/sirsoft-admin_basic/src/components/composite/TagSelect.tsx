import React, { useMemo, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Svg } from '../basic/Svg';

export interface TagSelectOption {
  value: string | number;
  label: string;
}

export interface TagSelectProps {
  /** 선택 가능한 옵션 목록 (라벨 매핑용) */
  options?: TagSelectOption[];
  /** 선택된 값 배열 */
  value?: (string | number)[];
  /** 값 변경 핸들러 */
  onChange?: (value: (string | number)[]) => void;
  /** placeholder (선택된 항목 없을 때) */
  placeholder?: string;
  /** 비활성화 */
  disabled?: boolean;
  /** 추가 클래스 */
  className?: string;
}

// CSS 클래스 상수 (ui-elements.css의 .tag 클래스 재사용)
const STYLES = {
  container: {
    base: 'tag-select',
    disabled: 'opacity-50 cursor-not-allowed',
  },
  tag: 'tag', // ui-elements.css .tag 클래스 사용
  tagRemove: 'tag-remove', // ui-elements.css .tag-remove 클래스 사용
  placeholder: 'tag-select-placeholder',
} as const;

/**
 * 태그 선택 표시 컴포넌트
 *
 * 선택된 항목들을 태그(뱃지) 형태로 표시합니다.
 * 각 태그의 X 버튼으로 개별 삭제가 가능합니다.
 */
export const TagSelect: React.FC<TagSelectProps> = ({
  options = [],
  value = [],
  onChange,
  placeholder = 'No items selected',
  disabled = false,
  className = '',
}) => {
  // 값으로 라벨 찾기
  const getLabel = useCallback(
    (val: string | number): string => {
      const option = options.find(opt => String(opt.value) === String(val));
      return option?.label || String(val);
    },
    [options]
  );

  // 태그 제거 핸들러
  const handleRemove = useCallback(
    (valueToRemove: string | number) => {
      if (disabled) return;
      const newValues = value.filter(v => String(v) !== String(valueToRemove));
      onChange?.(newValues);
    },
    [value, onChange, disabled]
  );

  // 선택된 값들을 옵션 정보와 함께 매핑
  const selectedItems = useMemo(() => {
    return value.map(v => ({
      value: v,
      label: getLabel(v),
    }));
  }, [value, getLabel]);

  const containerClassName = `${STYLES.container.base} ${disabled ? STYLES.container.disabled : ''} ${className}`.trim();

  return (
    <Div className={containerClassName}>
      {selectedItems.length === 0 ? (
        <Span className={STYLES.placeholder}>{placeholder}</Span>
      ) : (
        selectedItems.map(item => (
          <Span key={item.value} className={STYLES.tag}>
            {item.label}
            {!disabled && (
              <Span
                className={STYLES.tagRemove}
                onClick={() => handleRemove(item.value)}
                role="button"
                aria-label={`Remove ${item.label}`}
              >
                <Svg
                  className="w-3 h-3"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M6 18L18 6M6 6l12 12"
                  />
                </Svg>
              </Span>
            )}
          </Span>
        ))
      )}
    </Div>
  );
};
