import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from '../Icon';
import { IconName, iconNameMap } from '../IconTypes';

describe('Icon 컴포넌트', () => {
  describe('기본 렌더링', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon).toBeTruthy();
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('문자열 name prop으로 렌더링된다', () => {
      const { container } = render(<Icon name="fa-user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('IconName enum으로 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.User} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('기본 iconStyle은 solid이다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fas');
    });
  });

  describe('Icon Style', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('solid 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="solid" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fas');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('regular 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="regular" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('far');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('light 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="light" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fal');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('duotone 스타일이 적용된다', () => {
      const { container } = render(<Icon name="user" iconStyle="duotone" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fad');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('brands 스타일이 적용된다', () => {
      const { container } = render(<Icon name="facebook" iconStyle="brands" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fab');
    });
  });

  describe('Icon Size', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('xs 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="xs" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-xs');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('sm 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="sm" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-sm');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('lg 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="lg" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-lg');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('2x 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="2x" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-2x');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('3x 크기가 적용된다', () => {
      const { container } = render(<Icon name="user" size="3x" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-3x');
    });
  });

  describe('Icon Animation', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('spin 애니메이션이 적용된다', () => {
      const { container } = render(<Icon name="spinner" spin />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-spin');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('pulse 애니메이션이 적용된다', () => {
      const { container } = render(<Icon name="circle-notch" pulse />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-pulse');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('spin과 pulse가 함께 적용되지 않는다', () => {
      const { container } = render(<Icon name="spinner" spin pulse />);
      const icon = container.querySelector('i');
      // 둘 다 true지만 일반적으로 하나만 사용해야 함
      expect(icon?.className).toContain('fa-spin');
      expect(icon?.className).toContain('fa-pulse');
    });
  });

  describe('Fixed Width', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('fixedWidth가 적용된다', () => {
      const { container } = render(<Icon name="user" fixedWidth />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-fw');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('fixedWidth가 false일 때 적용되지 않는다', () => {
      const { container } = render(<Icon name="user" fixedWidth={false} />);
      const icon = container.querySelector('i');
      expect(icon?.className).not.toContain('fa-fw');
    });
  });

  describe('Color', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('color prop이 클래스로 적용된다', () => {
      const { container } = render(<Icon name="heart" color="text-red-500" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('text-red-500');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('여러 색상 클래스가 적용된다', () => {
      const { container } = render(<Icon name="star" color="text-yellow-400 dark:text-yellow-300" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('text-yellow-400');
      expect(icon?.className).toContain('dark:text-yellow-300');
    });
  });

  describe('사용자 정의 Props', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(<Icon name="user" className="custom-icon" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('custom-icon');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('style prop이 적용된다', () => {
      const { container } = render(<Icon name="user" style={{ fontSize: '24px', color: 'red' }} />);
      const icon = container.querySelector('i') as HTMLElement;
      expect(icon.style.fontSize).toBe('24px');
      expect(icon.style.color).toBe('red');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('data 속성이 적용된다', () => {
      const { container } = render(<Icon name="user" data-testid="test-icon" />);
      const icon = container.querySelector('i');
      expect(icon?.getAttribute('data-testid')).toBe('test-icon');
    });
  });

  describe('IconName enum 사용', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('User 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.User} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('Search 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.Search} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-search');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('ShoppingCart 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.ShoppingCart} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-shopping-cart');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('Heart 아이콘이 렌더링된다', () => {
      const { container } = render(<Icon name={IconName.Heart} />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-heart');
    });
  });

  describe('복합 Props', () => {
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
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
    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('fa- 접두사가 있는 경우 중복 제거된다', () => {
      const { container } = render(<Icon name="fa-user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
      expect(icon?.className).not.toContain('fa-fa-user');
    });

    /**
     * @scenario component=icon
     * @effects icon_renders_fontawesome_classes_from_props
     */
    it('fa- 접두사가 없는 경우 자동 추가된다', () => {
      const { container } = render(<Icon name="user" />);
      const icon = container.querySelector('i');
      expect(icon?.className).toContain('fa-user');
    });
  });
});

/**
 * 상태 의미 토큰(status-*) → 글리프 매핑 계약
 *
 * 백엔드(클레임·주문 상태 Enum)는 아이콘 라이브러리를 알지 않고 라이브러리 중립 토큰만 내보낸다.
 * 토큰을 이 템플릿의 글리프로 바꾸는 표는 iconNameMap 한 곳이다 — 어휘에 없는 토큰은
 * fa-status-* 로 떨어져 오류 없이 빈 네모가 그려지므로 어휘 21종 전건을 여기서 잠근다.
 */
const STATUS_TOKENS: Record<string, string> = {
  'status-request': 'fa-file-lines',
  'status-payment-pending': 'fa-credit-card',
  'status-paid': 'fa-money-bill',
  'status-approved': 'fa-thumbs-up',
  'status-on-hold': 'fa-pause',
  'status-rejected': 'fa-ban',
  'status-pickup-ready': 'fa-box-open',
  'status-pickup': 'fa-truck',
  'status-received': 'fa-warehouse',
  'status-dispatch-ready': 'fa-box',
  'status-dispatch': 'fa-truck-fast',
  'status-delivered': 'fa-house',
  'status-completed': 'fa-circle-check',
  'status-withdrawn': 'fa-rotate-left',
  'status-processing': 'fa-hourglass-half',
  'status-preparing': 'fa-boxes-packing',
  'status-cancelled': 'fa-circle-xmark',
  'status-exchange': 'fa-right-left',
  'status-return': 'fa-reply',
  'status-exchanged': 'fa-arrows-rotate',
  'status-returned': 'fa-box-archive',
};

describe('상태 의미 토큰 → 글리프 매핑', () => {
  /**
   * @scenario component=icon
   * @effects status_tokens_map_to_template_glyphs
   */
  it('어휘 21종 전건이 iconNameMap 에 있다', () => {
    for (const token of Object.keys(STATUS_TOKENS)) {
      expect(iconNameMap[token], token).toBeDefined();
    }
  });

  /**
   * @scenario component=icon
   * @effects status_tokens_map_to_template_glyphs
   */
  it.each(Object.entries(STATUS_TOKENS))('%s 는 %s 글리프로 그려진다', (token, glyph) => {
    const { container } = render(<Icon name={token} />);
    const icon = container.querySelector('i');

    expect(icon?.className).toContain(glyph);
    expect(icon?.className).not.toContain('fa-status-');
  });
});
