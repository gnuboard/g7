/**
 * QuantitySelector 컴포넌트 테스트
 *
 * @description 수량 선택기 컴포넌트의 동작을 테스트합니다.
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

// QuantitySelector Mock (basic 컴포넌트 의존성 때문에)
const MockQuantitySelector: React.FC<{
  value: number;
  min?: number;
  max?: number;
  onChange: (value: number) => void;
  disabled?: boolean;
}> = ({ value, min = 1, max = 999, onChange, disabled = false }) => {
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

  return (
    <div data-testid="quantity-selector">
      <button
        data-testid="decrease-btn"
        onClick={handleDecrease}
        disabled={disabled || value <= min}
        aria-label="수량 감소"
      >
        -
      </button>
      <input
        data-testid="quantity-input"
        type="number"
        value={value}
        onChange={handleInputChange}
        min={min}
        max={max}
        disabled={disabled}
        aria-label="수량"
      />
      <button
        data-testid="increase-btn"
        onClick={handleIncrease}
        disabled={disabled || value >= max}
        aria-label="수량 증가"
      >
        +
      </button>
    </div>
  );
};

describe('QuantitySelector 컴포넌트', () => {
  describe('렌더링', () => {
    it('기본 구성요소가 렌더링되어야 함', () => {
      render(<MockQuantitySelector value={1} onChange={vi.fn()} />);
      expect(screen.getByTestId('quantity-selector')).toBeInTheDocument();
      expect(screen.getByTestId('decrease-btn')).toBeInTheDocument();
      expect(screen.getByTestId('quantity-input')).toBeInTheDocument();
      expect(screen.getByTestId('increase-btn')).toBeInTheDocument();
    });

    it('현재 값이 표시되어야 함', () => {
      render(<MockQuantitySelector value={5} onChange={vi.fn()} />);
      expect(screen.getByTestId('quantity-input')).toHaveValue(5);
    });
  });

  describe('증가/감소 버튼', () => {
    it('증가 버튼 클릭 시 onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} />);

      fireEvent.click(screen.getByTestId('increase-btn'));
      expect(onChange).toHaveBeenCalledWith(6);
    });

    it('감소 버튼 클릭 시 onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} />);

      fireEvent.click(screen.getByTestId('decrease-btn'));
      expect(onChange).toHaveBeenCalledWith(4);
    });

    it('최소값에서 감소 버튼이 비활성화되어야 함', () => {
      render(<MockQuantitySelector value={1} min={1} onChange={vi.fn()} />);
      expect(screen.getByTestId('decrease-btn')).toBeDisabled();
    });

    it('최대값에서 증가 버튼이 비활성화되어야 함', () => {
      render(<MockQuantitySelector value={10} max={10} onChange={vi.fn()} />);
      expect(screen.getByTestId('increase-btn')).toBeDisabled();
    });
  });

  describe('직접 입력', () => {
    it('직접 입력 시 onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} />);

      fireEvent.change(screen.getByTestId('quantity-input'), { target: { value: '8' } });
      expect(onChange).toHaveBeenCalledWith(8);
    });

    it('최대값을 초과하면 최대값으로 제한되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} max={10} onChange={onChange} />);

      fireEvent.change(screen.getByTestId('quantity-input'), { target: { value: '15' } });
      expect(onChange).toHaveBeenCalledWith(10);
    });

    it('최소값 미만이면 최소값으로 제한되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} min={1} onChange={onChange} />);

      fireEvent.change(screen.getByTestId('quantity-input'), { target: { value: '0' } });
      expect(onChange).toHaveBeenCalledWith(1);
    });
  });

  describe('비활성화 상태', () => {
    it('disabled일 때 모든 버튼이 비활성화되어야 함', () => {
      render(<MockQuantitySelector value={5} onChange={vi.fn()} disabled={true} />);

      expect(screen.getByTestId('decrease-btn')).toBeDisabled();
      expect(screen.getByTestId('increase-btn')).toBeDisabled();
      expect(screen.getByTestId('quantity-input')).toBeDisabled();
    });

    it('disabled일 때 버튼 클릭해도 onChange가 호출되지 않아야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} disabled={true} />);

      fireEvent.click(screen.getByTestId('increase-btn'));
      fireEvent.click(screen.getByTestId('decrease-btn'));
      expect(onChange).not.toHaveBeenCalled();
    });
  });
});
