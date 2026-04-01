/**
 * QuantitySelector 컴포넌트
 *
 * 수량 선택을 위한 +/- 버튼 컴포넌트입니다.
 * 상품 상세 페이지, 장바구니 등에서 사용됩니다.
 */

import React from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Icon } from '../basic/Icon';

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
      onChange(clampedValue);
    }
  };

  const handleInputBlur = (e: React.FocusEvent<HTMLInputElement>) => {
    const newValue = parseInt(e.target.value, 10);
    if (isNaN(newValue) || newValue < min) {
      onChange(min);
    } else if (newValue > max) {
      onChange(max);
    }
  };

  const isMinDisabled = disabled || value <= min;
  const isMaxDisabled = disabled || value >= max;

  return (
    <Div
      className={`inline-flex items-center border border-gray-300 dark:border-gray-600 rounded-lg ${classes.container} ${className}`}
    >
      {/* 감소 버튼 */}
      <Button
        type="button"
        onClick={handleDecrease}
        disabled={isMinDisabled}
        className={`${classes.button} flex items-center justify-center text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-l-lg transition-colors`}
        aria-label="수량 감소"
      >
        <Icon name="minus" className="w-4 h-4" />
      </Button>

      {/* 수량 입력 */}
      <Input
        type="number"
        value={value}
        onChange={handleInputChange}
        onBlur={handleInputBlur}
        min={min}
        max={max}
        disabled={disabled}
        className={`${classes.input} text-center border-x border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none`}
        aria-label="수량"
      />

      {/* 증가 버튼 */}
      <Button
        type="button"
        onClick={handleIncrease}
        disabled={isMaxDisabled}
        className={`${classes.button} flex items-center justify-center text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-r-lg transition-colors`}
        aria-label="수량 증가"
      >
        <Icon name="plus" className="w-4 h-4" />
      </Button>
    </Div>
  );
};

export default QuantitySelector;
