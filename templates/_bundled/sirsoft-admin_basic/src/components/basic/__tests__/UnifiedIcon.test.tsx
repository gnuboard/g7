import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { UnifiedIcon } from '../UnifiedIcon';

describe('UnifiedIcon 컴포넌트', () => {
  describe('Font Awesome 아이콘', () => {
    it('fa: 접두사로 아이콘이 렌더링된다', () => {
      const { container } = render(<UnifiedIcon icon="fa:cart-shopping" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
      expect(icon?.className).toContain('fa-cart-shopping');
    });

    it('접두사 없이 Font Awesome 아이콘이 렌더링된다', () => {
      const { container } = render(<UnifiedIcon icon="user" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
      expect(icon?.className).toContain('fa-user');
    });

    it('fa-solid fa-xxx 형식이 지원된다', () => {
      const { container } = render(<UnifiedIcon icon="fa-solid fa-cart-shopping" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
      expect(icon?.className).toContain('fa-cart-shopping');
    });

    it('fas fa-xxx 형식이 지원된다', () => {
      const { container } = render(<UnifiedIcon icon="fas fa-user" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
      expect(icon?.className).toContain('fa-user');
    });

    it('far fa-xxx 형식이 지원된다', () => {
      const { container } = render(<UnifiedIcon icon="far fa-star" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
      expect(icon?.className).toContain('fa-star');
    });
  });

  describe('SVG 아이콘', () => {
    it('svg: 접두사로 이미지가 렌더링된다', () => {
      render(<UnifiedIcon icon="svg:/path/to/icon.svg" ariaLabel="Custom Icon" />);
      const img = screen.getByRole('img');
      expect(img).toBeTruthy();
      expect(img.getAttribute('src')).toBe('/path/to/icon.svg');
    });

    it('SVG 아이콘에 크기 클래스가 적용된다', () => {
      render(<UnifiedIcon icon="svg:/icon.svg" size="lg" ariaLabel="Icon" />);
      const img = screen.getByRole('img');
      expect(img.className).toContain('w-6');
      expect(img.className).toContain('h-6');
    });

    it('SVG 아이콘에 색상 클래스가 적용된다', () => {
      render(<UnifiedIcon icon="svg:/icon.svg" color="text-blue-500" ariaLabel="Icon" />);
      const img = screen.getByRole('img');
      expect(img.className).toContain('text-blue-500');
    });
  });

  describe('이미지 아이콘', () => {
    it('img: 접두사로 이미지가 렌더링된다', () => {
      render(<UnifiedIcon icon="img:/path/to/icon.png" ariaLabel="Custom Icon" />);
      const img = screen.getByRole('img');
      expect(img).toBeTruthy();
      expect(img.getAttribute('src')).toBe('/path/to/icon.png');
    });

    it('이미지 아이콘에 크기 클래스가 적용된다', () => {
      render(<UnifiedIcon icon="img:/icon.png" size="sm" ariaLabel="Icon" />);
      const img = screen.getByRole('img');
      expect(img.className).toContain('w-4');
      expect(img.className).toContain('h-4');
    });
  });

  describe('크기', () => {
    it('xs 크기가 적용된다 (FA)', () => {
      const { container } = render(<UnifiedIcon icon="user" size="xs" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-xs');
    });

    it('sm 크기가 적용된다 (FA)', () => {
      const { container } = render(<UnifiedIcon icon="user" size="sm" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-sm');
    });

    it('md 크기가 기본값이다 (FA)', () => {
      const { container } = render(<UnifiedIcon icon="user" />);
      const icon = container.querySelector('i');
      // md는 기본값이므로 fa-md 클래스가 없음
      expect(icon?.className).not.toContain('fa-md');
      expect(icon?.className).not.toContain('fa-lg');
    });

    it('lg 크기가 적용된다 (FA)', () => {
      const { container } = render(<UnifiedIcon icon="user" size="lg" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-lg');
    });

    it('xl 크기가 적용된다 (FA)', () => {
      const { container } = render(<UnifiedIcon icon="user" size="xl" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-2x');
    });

    it.each([
      ['xs', 'w-3', 'h-3'],
      ['sm', 'w-4', 'h-4'],
      ['md', 'w-5', 'h-5'],
      ['lg', 'w-6', 'h-6'],
      ['xl', 'w-8', 'h-8'],
    ] as const)('%s 크기가 SVG에 적용된다', (size, expectedWidth, expectedHeight) => {
      render(<UnifiedIcon icon="svg:/icon.svg" size={size} ariaLabel="Icon" />);
      const img = screen.getByRole('img');
      expect(img.className).toContain(expectedWidth);
      expect(img.className).toContain(expectedHeight);
    });
  });

  describe('색상', () => {
    it('FA 아이콘에 색상이 적용된다', () => {
      const { container } = render(<UnifiedIcon icon="user" color="text-blue-500" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('text-blue-500');
    });
  });

  describe('추가 className', () => {
    it('FA 아이콘에 추가 className이 적용된다', () => {
      const { container } = render(<UnifiedIcon icon="user" className="custom-class" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('custom-class');
    });

    it('SVG 아이콘에 추가 className이 적용된다', () => {
      render(<UnifiedIcon icon="svg:/icon.svg" className="custom-class" ariaLabel="Icon" />);
      const img = screen.getByRole('img');
      expect(img.className).toContain('custom-class');
    });

    it('이미지 아이콘에 추가 className이 적용된다', () => {
      render(<UnifiedIcon icon="img:/icon.png" className="custom-class" ariaLabel="Icon" />);
      const img = screen.getByRole('img');
      expect(img.className).toContain('custom-class');
    });
  });

  describe('접근성', () => {
    it('FA 아이콘에 ariaLabel이 적용된다', () => {
      const { container } = render(<UnifiedIcon icon="user" ariaLabel="사용자 아이콘" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('aria-label')).toBe('사용자 아이콘');
    });

    it('SVG 아이콘에 ariaLabel이 적용된다', () => {
      render(<UnifiedIcon icon="svg:/icon.svg" ariaLabel="커스텀 아이콘" />);
      const img = screen.getByRole('img');
      expect(img.getAttribute('aria-label')).toBe('커스텀 아이콘');
    });

    it('이미지 아이콘에 alt 속성이 적용된다', () => {
      render(<UnifiedIcon icon="img:/icon.png" ariaLabel="이미지 아이콘" />);
      const img = screen.getByRole('img');
      expect(img.getAttribute('alt')).toBe('이미지 아이콘');
    });
  });

  describe('타입 파싱', () => {
    it('알 수 없는 접두사는 FA로 폴백된다', () => {
      const { container } = render(<UnifiedIcon icon="unknown:something" />);
      const icon = container.querySelector('i');
      // 알 수 없는 타입이므로 default case에서 Icon 컴포넌트로 렌더링
      expect(icon).toBeTruthy();
    });

    it('콜론이 없는 문자열은 FA로 처리된다', () => {
      const { container } = render(<UnifiedIcon icon="cart-shopping" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
      expect(icon?.className).toContain('fa-cart-shopping');
    });

    it('빈 접두사는 FA로 처리된다', () => {
      const { container } = render(<UnifiedIcon icon=":something" />);
      // 콜론 인덱스가 0이므로 FA 기본 처리
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
    });
  });
});
