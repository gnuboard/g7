/**
 * QuantitySelector 컴포넌트
 *
 * 수량 선택을 위한 +/- 버튼 컴포넌트입니다.
 * 상품 상세 페이지, 장바구니 등에서 사용됩니다.
 */

import React, { useEffect, useRef, useState } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Icon } from '../basic/Icon';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

interface QuantitySelectorProps {
  /** 현재 수량 */
  value: number;
  /** 최소 수량 */
  min?: number;
  /** 최대 수량 */
  max?: number;
  /** 수량 변경 콜백 */
  onChange: (value: number) => void;
  /** 컴포넌트 크기 */
  size?: 'sm' | 'md' | 'lg';
  /** 비활성화 여부 */
  disabled?: boolean;
  /** 추가 CSS 클래스 */
  className?: string;
    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
/**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
   */
  editorAttrs?: EditorAttrs;
}

/**
 * 수량 선택 컴포넌트
 *
 * @example
 * ```tsx
 * const [quantity, setQuantity] = useState(1);
 *
 * <QuantitySelector
 *   value={quantity}
 *   min={1}
 *   max={10}
 *   onChange={setQuantity}
 * />
 * ```
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "QuantitySelector",
 *   "props": {
 *     "value": "{{item.quantity}}",
 *     "min": 1,
 *     "max": "{{item.stock}}",
 *     "size": "sm"
 *   },
 *   "actions": [{
 *     "event": "onChange",
 *     "type": "change",
 *     "handler": "updateQuantity",
 *     "params": { "itemId": "{{item.id}}", "quantity": "{{$args[0]}}" }
 *   }]
 * }
 * ```
 */
const QuantitySelector: React.FC<QuantitySelectorProps> = ({
  value,
  min = 1,
  max = 999,
  onChange,
  size = 'md',
  disabled = false,
  className = '',
  id,
  editorAttrs,
}) => {
  const sizeClasses = {
    sm: {
      container: 'h-8',
      button: 'w-8 h-8 text-sm',
      input: 'w-10 text-sm',
    },
    md: {
      container: 'h-10',
      button: 'w-10 h-10',
      input: 'w-14',
    },
    lg: {
      container: 'h-12',
      button: 'w-12 h-12 text-lg',
      input: 'w-16 text-lg',
    },
  };

  const classes = sizeClasses[size];

  // 기본 Input 은 바깥 value 가 바뀔 때만 칸 표시를 맞춘다. 범위 밖 입력을 보정한 값이 지금 값과
  // 같으면(재고 1개 칸에 2 입력 → 1) value 가 그대로라 칸에 친 숫자가 남으므로, 그때는 Input 을
  // 다시 그려 지금 값을 보이게 한다. 입력 중이었다면 포커스를 되돌려 계속 칠 수 있게 한다.
  const inputRef = useRef<HTMLInputElement>(null);
  const [resyncKey, setResyncKey] = useState(0);
  const refocusRef = useRef(false);

  useEffect(() => {
    if (refocusRef.current) {
      refocusRef.current = false;
      inputRef.current?.focus();
    }
  }, [resyncKey]);

  const resyncInput = (refocus: boolean) => {
    refocusRef.current = refocus;
    setResyncKey((k) => k + 1);
  };

  const handleDecrease = () => {
    if (value > min && !disabled) {
      onChange(value - 1);
    }
  };

  const handleIncrease = () => {
    if (value < max && !disabled) {
      onChange(value + 1);
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = parseInt(e.target.value, 10);
    if (!isNaN(newValue)) {
      const clampedValue = Math.max(min, Math.min(max, newValue));
      if (clampedValue !== newValue && clampedValue === value) {
        resyncInput(true);
      }
      onChange(clampedValue);
    }
  };

  const handleInputBlur = (e: React.FocusEvent<HTMLInputElement>) => {
    const newValue = parseInt(e.target.value, 10);
    if (isNaN(newValue) || newValue < min) {
      if (min === value) {
        resyncInput(false);
      }
      onChange(min);
    } else if (newValue > max) {
      if (max === value) {
        resyncInput(false);
      }
      onChange(max);
    }
  };

  const isMinDisabled = disabled || value <= min;
  const isMaxDisabled = disabled || value >= max;

  return (
    <Div
      className={`inline-flex items-center border border-gray-300 dark:border-gray-600 rounded-lg ${classes.container} ${className}`}
      id={id} {...editorAttrs}
    >
      {/* 감소 버튼 */}
      <Button
        type="button"
        onClick={handleDecrease}
        disabled={isMinDisabled}
        className={`${classes.button} flex items-center justify-center text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-l-lg transition-colors`}
        aria-label={t('common.quantity_decrease')}
      >
        <Icon name="minus" className="text-base" />
      </Button>

      {/* 수량 입력 */}
      <Input
        key={resyncKey}
        ref={inputRef}
        type="number"
        value={value}
        onChange={handleInputChange}
        onBlur={handleInputBlur}
        min={min}
        max={max}
        disabled={disabled}
        className={`${classes.input} text-center border-x border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none`}
        aria-label={t('common.quantity')}
      />

      {/* 증가 버튼 */}
      <Button
        type="button"
        onClick={handleIncrease}
        disabled={isMaxDisabled}
        className={`${classes.button} flex items-center justify-center text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-r-lg transition-colors`}
        aria-label={t('common.quantity_increase')}
      >
        <Icon name="plus" className="text-base" />
      </Button>
    </Div>
  );
};

export default QuantitySelector;
