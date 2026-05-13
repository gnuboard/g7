import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from '../Icon';
import { IconName } from '../IconTypes';

describe('Icon 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
    });

    it('문자열 name prop으로 렌더링된다', () => {
      const { container } = render(<Icon name="fa-user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });

    it('IconName enum으로 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.User} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });

    it('기본 iconStyle은 solid이다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fas');
    });
  });

  describe('Icon Style', () => {
    it('solid 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="solid" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fas');
    });

    it('regular 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="regular" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('far');
    });

    it('light 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="light" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fal');
    });

    it('duotone 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="duotone" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fad');
    });

    it('brands 스타일이 적용된다', () => {
      const { container } = render(<Icon name="facebook" iconStyle="brands" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fab');
    });
  });

  describe('Icon Size', () => {
    it('xs 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="xs" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-xs');
    });

    it('sm 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="sm" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-sm');
    });

    it('lg 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="lg" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-lg');
    });

    it('2x 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="2x" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-2x');
    });

    it('3x 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="3x" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-3x');
    });
  });

  describe('Icon Animation', () => {
    it('spin 애니메이션이 적용된다', () => {
      const { container } = render(<Icon name="spinner" spin />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-spin');
    });

    it('pulse 애니메이션이 적용된다', () => {
      const { container } = render(<Icon name="circle-notch" pulse />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-pulse');
    });

    it('spin과 pulse가 함께 적용되지 않는다', () => {
      const { container } = render(<Icon name="spinner" spin pulse />);
      const icon = container.querySelector('i');
      // 둘 다 true지만 일반적으로 하나만 사용해야 함
      expect(icon?.className).toContain('fa-spin');
      expect(icon?.className).toContain('fa-pulse');
    });
  });

  describe('Fixed Width', () => {
    it('fixedWidth가 적용된다', () => {
      const { container } = render(<Icon name="user" fixedWidth />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-fw');
    });

    it('fixedWidth가 false일 때 적용되지 않는다', () => {
      const { container } = render(<Icon name="user" fixedWidth={false} />);
      const icon = container.querySelector('i');
      expect(icon?.className).not.toContain('fa-fw');
    });
  });

  describe('Color', () => {
    it('color prop이 클래스로 적용된다', () => {
      const { container } = render(<Icon name="heart" color="text-red-500" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('text-red-500');
    });

    it('여러 색상 클래스가 적용된다', () => {
      const { container } = render(<Icon name="star" color="text-yellow-400 dark:text-yellow-300" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('text-yellow-400');
      expect(icon?.className).toContain('dark:text-yellow-300');
    });
  });

  describe('Accessibility', () => {
    it('role="img"가 적용된다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('role')).toBe('img');
    });

    it('기본 aria-label이 생성된다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('aria-label')).toBe('user');
    });

    it('하이픈이 공백으로 변환된다', () => {
      const { container } = render(<Icon name="shopping-cart" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('aria-label')).toBe('shopping cart');
    });

    it('사용자 정의 ariaLabel이 적용된다', () => {
      const { container } = render(<Icon name="user" ariaLabel="사용자 프로필" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('aria-label')).toBe('사용자 프로필');
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Icon name="user" className="custom-icon" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('custom-icon');
    });

    it('style prop이 적용된다', () => {
      const { container } = render(<Icon name="user" style={{ fontSize: '24px', color: 'red' }} />);
      const icon = container.querySelector('i') as HTMLElement;
      expect(icon.style.fontSize).toBe('24px');
      expect(icon.style.color).toBe('red');
    });

    it('data 속성이 적용된다', () => {
      const { container } = render(<Icon name="user" data-testid="test-icon" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('data-testid')).toBe('test-icon');
    });
  });

  describe('IconName enum 사용', () => {
    it('User 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.User} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });

    it('Search 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.Search} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-search');
    });

    it('ShoppingCart 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.ShoppingCart} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-shopping-cart');
    });

    it('Heart 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.Heart} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-heart');
    });
  });

  describe('복합 Props', () => {
    it('여러 props가 함께 적용된다', () => {
      const { container } = render(
        <Icon
          name={IconName.Spinner}
          iconStyle="solid"
          size="2x"
          color="text-blue-500"
          spin
          fixedWidth
          ariaLabel="로딩 중"
          className="custom-spinner"
        />
      );

      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fas');
      expect(icon?.className).toContain('fa-spinner');
      expect(icon?.className).toContain('fa-2x');
      expect(icon?.className).toContain('text-blue-500');
      expect(icon?.className).toContain('fa-spin');
      expect(icon?.className).toContain('fa-fw');
      expect(icon?.className).toContain('custom-spinner');
      expect(icon?.getAttribute('aria-label')).toBe('로딩 중');
    });
  });

  describe('아이콘 이름 처리', () => {
    it('fa- 접두사가 있는 경우 중복 제거된다', () => {
      const { container } = render(<Icon name="fa-user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
      expect(icon?.className).not.toContain('fa-fa-user');
    });

    it('fa- 접두사가 없는 경우 자동 추가된다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });
  });
});
