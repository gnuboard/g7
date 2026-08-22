import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Badge } from '../Badge';

describe('Badge', () => {
  describe('기본 렌더링', () => {
    it('text 를 렌더링해야 함', () => {
      render(<Badge text="활성" />);

      expect(screen.getByText('활성')).toBeInTheDocument();
    });

    it('children 을 렌더링해야 함', () => {
      render(<Badge><span>자식</span></Badge>);

      expect(screen.getByText('자식')).toBeInTheDocument();
    });

    it('아무 색상도 지정하지 않으면 gray 로 폴백해야 함', () => {
      const { container } = render(<Badge text="기본" />);

      expect(container.querySelector('span')?.className).toContain('bg-gray-100');
    });
  });

  describe('color prop (직접 색상명)', () => {
    it('color 로 지정한 색상 클래스를 적용해야 함', () => {
      const { container } = render(<Badge color="green" text="적립완료" />);

      expect(container.querySelector('span')?.className).toContain('bg-green-100');
    });

    it('알 수 없는 color 는 gray 로 폴백해야 함', () => {
      const { container } = render(<Badge color="unknown-color" text="X" />);

      expect(container.querySelector('span')?.className).toContain('bg-gray-100');
    });
  });

  describe('variant prop (의미 기반 — 백엔드 Enum variant() 매핑)', () => {
    const variantTests: Array<{ variant: string; bgClass: string }> = [
      { variant: 'success', bgClass: 'bg-green-100' },
      { variant: 'warning', bgClass: 'bg-yellow-100' },
      { variant: 'danger', bgClass: 'bg-red-100' },
      { variant: 'error', bgClass: 'bg-red-100' },
      { variant: 'info', bgClass: 'bg-blue-100' },
      { variant: 'primary', bgClass: 'bg-blue-100' },
      { variant: 'secondary', bgClass: 'bg-gray-100' },
      { variant: 'text', bgClass: 'bg-gray-100' },
    ];

    it.each(variantTests)('variant=$variant 는 $bgClass 로 매핑되어야 함', ({ variant, bgClass }) => {
      const { container } = render(<Badge variant={variant} text="상태" />);

      expect(container.querySelector('span')?.className).toContain(bgClass);
    });

    it('알 수 없는 variant 는 gray 로 폴백해야 함', () => {
      const { container } = render(<Badge variant="unknown" text="X" />);

      expect(container.querySelector('span')?.className).toContain('bg-gray-100');
    });

    it('회귀: variant 가 무시되어 gray 로만 렌더되던 결함 차단 (배송완료=success → green)', () => {
      const { container } = render(<Badge variant="success" text="배송완료" />);

      const className = container.querySelector('span')?.className ?? '';
      expect(className).toContain('bg-green-100');
      expect(className).not.toContain('bg-gray-100');
    });
  });

  describe('우선순위 (color > variant > gray)', () => {
    it('color 와 variant 가 모두 주어지면 color 가 우선한다', () => {
      const { container } = render(<Badge color="purple" variant="success" text="X" />);

      const className = container.querySelector('span')?.className ?? '';
      expect(className).toContain('bg-purple-100');
      expect(className).not.toContain('bg-green-100');
    });

    it('color 없이 variant 만 주어지면 variant 매핑을 적용한다', () => {
      const { container } = render(<Badge variant="warning" text="X" />);

      expect(container.querySelector('span')?.className).toContain('bg-yellow-100');
    });
  });

  describe('size prop', () => {
    it('size=sm 클래스를 적용해야 함', () => {
      const { container } = render(<Badge size="sm" text="작게" />);

      expect(container.querySelector('span')?.className).toContain('text-[10px]');
    });
  });
});
