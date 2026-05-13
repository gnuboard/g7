/**
 * ComputedEditor.test.tsx
 *
 * computed 속성 편집기 컴포넌트 테스트
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ComputedEditor, type ComputedValue } from '../ComputedEditor';

// PipeAutocomplete 모킹
vi.mock('../PipeAutocomplete', () => ({
  PipeAutocomplete: ({ value, onChange, label }: any) => (
    <div data-testid="pipe-autocomplete">
      <label>{label}</label>
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="expression"
      />
    </div>
  ),
}));

// SwitchExpressionEditor 모킹
vi.mock('../SwitchExpressionEditor', () => ({
  SwitchExpressionEditor: ({ value, onChange, label }: any) => (
    <div data-testid="switch-editor">
      <label>{label}</label>
      <button onClick={() => onChange({ $switch: '{{test}}', $cases: {}, $default: '' })}>
        Update Switch
      </button>
    </div>
  ),
}));

describe('ComputedEditor', () => {
  let mockOnChange: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    mockOnChange = vi.fn();
  });

  describe('초기 렌더링', () => {
    it('값이 없으면 빈 상태로 렌더링되어야 함', () => {
      render(<ComputedEditor value={undefined} onChange={mockOnChange} />);

      expect(screen.getByText('Computed 속성')).toBeInTheDocument();
      expect(screen.getByText('+ 추가')).toBeInTheDocument();
    });

    it('값이 있으면 개수 배지가 표시되어야 함', () => {
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
        canEdit: '{{permissions.edit}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      expect(screen.getByText('2개')).toBeInTheDocument();
    });

    it('값이 있으면 자동으로 확장되어 내용이 표시되어야 함', () => {
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // 확장된 상태이므로 내용이 보여야 함
      expect(screen.getByText('$computed.displayPrice')).toBeInTheDocument();
    });
  });

  describe('computed 추가', () => {
    it('+ 추가 버튼 클릭 시 새 computed가 생성되어야 함', async () => {
      const user = userEvent.setup();
      render(<ComputedEditor value={undefined} onChange={mockOnChange} />);

      await user.click(screen.getByText('+ 추가'));

      expect(mockOnChange).toHaveBeenCalledWith({
        computed_1: '',
      });
    });

    it('기존 computed가 있을 때 추가 시 번호가 증가해야 함', async () => {
      const user = userEvent.setup();
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      await user.click(screen.getByText('+ 추가'));

      expect(mockOnChange).toHaveBeenCalledWith({
        displayPrice: '{{product.price_formatted}}',
        computed_2: '',
      });
    });
  });

  describe('computed 삭제', () => {
    it('삭제 버튼 클릭 시 해당 computed가 제거되어야 함', async () => {
      const user = userEvent.setup();
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
        canEdit: '{{permissions.edit}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      // 첫 번째 computed 삭제 버튼 클릭
      const deleteButtons = screen.getAllByTitle('computed 삭제');
      await user.click(deleteButtons[0]);

      expect(mockOnChange).toHaveBeenCalledWith({
        canEdit: '{{permissions.edit}}',
      });
    });

    it('마지막 computed 삭제 시 undefined가 전달되어야 함', async () => {
      const user = userEvent.setup();
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      const deleteButton = screen.getByTitle('computed 삭제');
      await user.click(deleteButton);

      expect(mockOnChange).toHaveBeenCalledWith(undefined);
    });
  });

  describe('computed 표시', () => {
    it('$computed.name 형식으로 표시되어야 함', () => {
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      expect(screen.getByText('$computed.displayPrice')).toBeInTheDocument();
    });

    it('expression 타입은 expression 배지가 표시되어야 함', () => {
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      expect(screen.getByText('expression')).toBeInTheDocument();
    });

    it('$switch 타입은 $switch 배지가 표시되어야 함', () => {
      const value: Record<string, ComputedValue> = {
        statusClass: {
          $switch: '{{status}}',
          $cases: { active: 'green', inactive: 'gray' },
          $default: 'blue',
        },
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      // $switch 배지가 최소 하나 이상 있는지 확인 (배지와 버튼 모두 $switch 텍스트를 가짐)
      const switchElements = screen.getAllByText('$switch');
      expect(switchElements.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('사용 예시', () => {
    it('computed가 있으면 사용 예시가 표시되어야 함', () => {
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // value가 있으면 자동으로 확장됨
      expect(screen.getByText('사용 예시:')).toBeInTheDocument();
      // {{$computed.displayPrice}} 텍스트가 여러 곳에 나타남 (사용 예시 + 아이템 표시)
      const usageExamples = screen.getAllByText('{{$computed.displayPrice}}');
      expect(usageExamples.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('패널 토글', () => {
    it('헤더 클릭 시 패널이 토글되어야 함', async () => {
      const user = userEvent.setup();
      const value: Record<string, ComputedValue> = {
        displayPrice: '{{product.price_formatted}}',
      };

      render(<ComputedEditor value={value} onChange={mockOnChange} />);

      // 초기에는 확장 상태
      expect(screen.getByText('$computed.displayPrice')).toBeInTheDocument();

      // 헤더 클릭하여 접기
      await user.click(screen.getByText('Computed 속성'));

      // 접힌 상태
      expect(screen.queryByText('$computed.displayPrice')).not.toBeInTheDocument();

      // 다시 클릭하여 펼치기
      await user.click(screen.getByText('Computed 속성'));

      // 다시 확장 상태
      expect(screen.getByText('$computed.displayPrice')).toBeInTheDocument();
    });
  });
});
