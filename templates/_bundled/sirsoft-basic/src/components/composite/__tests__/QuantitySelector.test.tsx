/**
 * QuantitySelector 컴포넌트 테스트
 *
 * @description 수량 선택기 컴포넌트의 동작을 테스트합니다.
 */

import React from 'react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import QuantitySelector from '../QuantitySelector';

/** 화면 언어별 번역 사전(G7Core.t 모의) — 실제 컴포넌트 시험은 기본으로 한국어 사전을 쓴다 */
const DICTS: Record<string, Record<string, string>> = {
  ko: { 'common.quantity': '수량', 'common.quantity_decrease': '수량 감소', 'common.quantity_increase': '수량 증가' },
  en: { 'common.quantity': 'Quantity', 'common.quantity_decrease': 'Decrease quantity', 'common.quantity_increase': 'Increase quantity' },
};

/**
 * G7Core.t 모의를 해당 언어 사전으로 설치합니다.
 *
 * @param locale 화면 언어
 */
function useLocale(locale: string): void {
  (window as any).G7Core = { t: (key: string) => DICTS[locale][key] ?? key };
}

beforeEach(() => useLocale('ko'));
afterEach(() => {
  delete (window as any).G7Core;
});

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
    /**
     * @scenario case=mock_render
     * @effects renders_value
     */
    it('기본 구성요소가 렌더링되어야 함', () => {
      render(<MockQuantitySelector value={1} onChange={vi.fn()} />);
      expect(screen.getByTestId('quantity-selector')).toBeInTheDocument();
      expect(screen.getByTestId('decrease-btn')).toBeInTheDocument();
      expect(screen.getByTestId('quantity-input')).toBeInTheDocument();
      expect(screen.getByTestId('increase-btn')).toBeInTheDocument();
    });

    /**
     * @scenario case=mock_render
     * @effects renders_value
     */
    it('현재 값이 표시되어야 함', () => {
      render(<MockQuantitySelector value={5} onChange={vi.fn()} />);
      expect(screen.getByTestId('quantity-input')).toHaveValue(5);
    });
  });

  describe('증가/감소 버튼', () => {
    /**
     * @scenario case=mock_buttons
     * @effects buttons_respect_bounds
     */
    it('증가 버튼 클릭 시 onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} />);

      fireEvent.click(screen.getByTestId('increase-btn'));
      expect(onChange).toHaveBeenCalledWith(6);
    });

    /**
     * @scenario case=mock_buttons
     * @effects buttons_respect_bounds
     */
    it('감소 버튼 클릭 시 onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} />);

      fireEvent.click(screen.getByTestId('decrease-btn'));
      expect(onChange).toHaveBeenCalledWith(4);
    });

    /**
     * @scenario case=mock_buttons
     * @effects buttons_respect_bounds
     */
    it('최소값에서 감소 버튼이 비활성화되어야 함', () => {
      render(<MockQuantitySelector value={1} min={1} onChange={vi.fn()} />);
      expect(screen.getByTestId('decrease-btn')).toBeDisabled();
    });

    /**
     * @scenario case=mock_buttons
     * @effects buttons_respect_bounds
     */
    it('최대값에서 증가 버튼이 비활성화되어야 함', () => {
      render(<MockQuantitySelector value={10} max={10} onChange={vi.fn()} />);
      expect(screen.getByTestId('increase-btn')).toBeDisabled();
    });
  });

  describe('직접 입력', () => {
    /**
     * @scenario case=mock_typed
     * @effects emits_clamped_onchange
     */
    it('직접 입력 시 onChange가 호출되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} />);

      fireEvent.change(screen.getByTestId('quantity-input'), { target: { value: '8' } });
      expect(onChange).toHaveBeenCalledWith(8);
    });

    /**
     * @scenario case=mock_typed
     * @effects emits_clamped_onchange
     */
    it('최대값을 초과하면 최대값으로 제한되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} max={10} onChange={onChange} />);

      fireEvent.change(screen.getByTestId('quantity-input'), { target: { value: '15' } });
      expect(onChange).toHaveBeenCalledWith(10);
    });

    /**
     * @scenario case=mock_typed
     * @effects emits_clamped_onchange
     */
    it('최소값 미만이면 최소값으로 제한되어야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} min={1} onChange={onChange} />);

      fireEvent.change(screen.getByTestId('quantity-input'), { target: { value: '0' } });
      expect(onChange).toHaveBeenCalledWith(1);
    });
  });

  /**
   * 실제 컴포넌트 — 보정한 값이 지금 값과 같을 때도 입력 칸이 그 값으로 돌아와야 한다.
   *
   * 재고 1개 옵션 칸(값 1·상한 1)에 2를 치면 상태는 1 그대로라 값이 바뀌지 않는다. 기본 `Input` 은
   * 바깥 값이 바뀔 때만 화면 값을 맞추므로 칸에 2가 남은 채 1개가 담겼다(이커머스 전수 실측 S4 — 상품 A).
   */
  describe('실제 컴포넌트 — 보정값이 지금 값과 같을 때 칸 표시', () => {
    const Harness: React.FC<{ initial: number; max: number }> = ({ initial, max }) => {
      const [qty, setQty] = React.useState(initial);
      return <QuantitySelector value={qty} min={1} max={max} onChange={setQty} />;
    };

    /**
     * @scenario case=typed_above_max_same_value
     * @effects input_shows_clamped_value, focus_kept_while_typing
     */
    it('상한 1 에서 2 를 치면 칸이 곧바로 1 로 돌아오고 포커스는 남는다', () => {
      render(<Harness initial={1} max={1} />);
      const input = screen.getByLabelText('수량') as HTMLInputElement;
      input.focus();
      fireEvent.change(input, { target: { value: '2' } });
      const after = screen.getByLabelText('수량') as HTMLInputElement;
      expect(after.value).toBe('1');
      expect(document.activeElement).toBe(after);
    });

    /**
     * @scenario case=blur_empty_at_min
     * @effects input_shows_clamped_value
     */
    it('최솟값일 때 칸을 비우고 벗어나면 최솟값이 다시 보인다', () => {
      render(<Harness initial={1} max={5} />);
      const input = screen.getByLabelText('수량') as HTMLInputElement;
      fireEvent.change(input, { target: { value: '' } });
      fireEvent.blur(screen.getByLabelText('수량'));
      expect((screen.getByLabelText('수량') as HTMLInputElement).value).toBe('1');
    });

    /**
     * @scenario case=typed_in_range
     * @effects input_shows_clamped_value
     */
    it('범위 안 값 입력은 그대로 반영된다', () => {
      render(<Harness initial={1} max={5} />);
      fireEvent.change(screen.getByLabelText('수량'), { target: { value: '3' } });
      expect((screen.getByLabelText('수량') as HTMLInputElement).value).toBe('3');
    });

    /**
     * @scenario case=typed_above_max_different_value
     * @effects input_shows_clamped_value
     */
    it('상한을 넘는 값이 지금 값과 다르면 상한으로 보정된다', () => {
      render(<Harness initial={2} max={5} />);
      fireEvent.change(screen.getByLabelText('수량'), { target: { value: '6' } });
      expect((screen.getByLabelText('수량') as HTMLInputElement).value).toBe('5');
    });
  });

  describe('비활성화 상태', () => {
    /**
     * @scenario case=mock_disabled
     * @effects disabled_blocks_input
     */
    it('disabled일 때 모든 버튼이 비활성화되어야 함', () => {
      render(<MockQuantitySelector value={5} onChange={vi.fn()} disabled={true} />);

      expect(screen.getByTestId('decrease-btn')).toBeDisabled();
      expect(screen.getByTestId('increase-btn')).toBeDisabled();
      expect(screen.getByTestId('quantity-input')).toBeDisabled();
    });

    /**
     * @scenario case=mock_disabled
     * @effects disabled_blocks_input
     */
    it('disabled일 때 버튼 클릭해도 onChange가 호출되지 않아야 함', () => {
      const onChange = vi.fn();
      render(<MockQuantitySelector value={5} onChange={onChange} disabled={true} />);

      fireEvent.click(screen.getByTestId('increase-btn'));
      fireEvent.click(screen.getByTestId('decrease-btn'));
      expect(onChange).not.toHaveBeenCalled();
    });
  });

  /**
   * 수량 칸과 +/- 버튼의 읽기 이름이 화면 언어를 따른다.
   *
   * 영어 화면 장바구니에서 버튼 읽기 이름이 「수량 감소」·「수량 증가」, 칸이 「수량」 으로 남아 있었다
   * (이커머스 전수 실측 S5 — 컴포넌트가 한국어를 그대로 박아 두었다). 화면 낭독기가 다른 언어 화면에서
   * 한국어를 읽는다.
   */
  describe('실제 컴포넌트 — 읽기 이름(aria-label) 다국어', () => {
    /**
     * @scenario case=labels_follow_locale
     * @effects labels_follow_locale
     */
    it('영어 화면에서는 칸·버튼 읽기 이름이 영어다', () => {
      useLocale('en');
      render(<QuantitySelector value={2} min={1} max={5} onChange={vi.fn()} />);
      expect(screen.getByLabelText('Quantity')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Decrease quantity' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Increase quantity' })).toBeInTheDocument();
    });

    /**
     * @scenario case=labels_follow_locale
     * @effects labels_follow_locale
     */
    it('컴포넌트 원본에 한국어 읽기 이름이 박혀 있지 않다', () => {
      const source = readFileSync(resolve(__dirname, '../QuantitySelector.tsx'), 'utf8');
      expect(source).not.toMatch(/aria-label="[^"]*[가-힣]/);
    });
  });
  /**
   * +/- 아이콘 크기는 글자 크기(text-base = 16px)로 정한다 — Icon 은 <i> 글리프라 w-4 h-4 는 박스만 키우고 글리프는
   * 부모 글자 크기를 따른다(docs/rules/frontend-actions-binding.md 「컴포넌트 Props」 등가표).
   */
  describe('실제 컴포넌트 — +/- 아이콘 크기', () => {
    /**
     * @scenario case=icon_size
     * @effects icon_sized_by_font_size
     */
    it('+/- 아이콘은 text-base 로 크기를 정하고 w-4·h-4 를 쓰지 않는다', () => {
      render(<QuantitySelector value={2} min={1} max={5} onChange={vi.fn()} />);
      const icons = screen.getAllByRole('button').map((button) => button.querySelector('i')).filter(Boolean) as HTMLElement[];
      expect(icons.length).toBe(2);
      for (const icon of icons) {
        expect(icon.className).toContain('text-base');
        expect(icon.className).not.toMatch(/\bw-4\b|\bh-4\b/);
      }
    });
  });
});
