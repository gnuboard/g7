/**
 * DynamicRenderer.responsive.test.tsx
 *
 * DynamicRenderer의 Responsive 오버라이드 기능 테스트
 *
 * 테스트 항목:
 * 1. props 얕은 머지 (지정한 속성만 대체, 미지정 속성 유지)
 * 2. children 완전 교체
 * 3. text 완전 교체
 * 4. if 조건 완전 교체
 * 5. 프리셋 (mobile, tablet, desktop) 매칭
 * 6. 커스텀 범위 (0-599, 600-899 등) 매칭
 * 7. 우선순위 (커스텀 > 프리셋, 좁은 범위 > 넓은 범위)
 * 8. 화면 크기 변경 시 동적 업데이트
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import * as ResponsiveContextModule from '../ResponsiveContext';

// ResponsiveManager mock
vi.mock('../ResponsiveManager', () => {
  const BREAKPOINT_PRESETS: Record<string, { min: number; max: number }> = {
    mobile: { min: 0, max: 767 },
    tablet: { min: 768, max: 1023 },
    desktop: { min: 1024, max: Infinity },
  };

  const parseRange = (key: string): { min: number; max: number } | null => {
    if (BREAKPOINT_PRESETS[key]) {
      return { ...BREAKPOINT_PRESETS[key] };
    }

    const rangeMatch = key.match(/^(-?\d*)-(-?\d*)$/);
    if (rangeMatch) {
      const [, minStr, maxStr] = rangeMatch;
      const min = minStr === '' ? 0 : parseInt(minStr, 10);
      const max = maxStr === '' ? Infinity : parseInt(maxStr, 10);

      if (!isNaN(min) && !isNaN(max) && min <= max) {
        return { min, max };
      }
    }

    return null;
  };

  const getMatchingKey = (
    responsive: Record<string, any>,
    width: number
  ): string | null => {
    const matchedKeys: Array<{
      key: string;
      range: { min: number; max: number };
      isPreset: boolean;
    }> = [];

    for (const key of Object.keys(responsive)) {
      const range = parseRange(key);
      if (!range) continue;

      if (width >= range.min && width <= range.max) {
        matchedKeys.push({
          key,
          range,
          isPreset: !!BREAKPOINT_PRESETS[key],
        });
      }
    }

    if (matchedKeys.length === 0) return null;

    matchedKeys.sort((a, b) => {
      if (a.isPreset !== b.isPreset) {
        return a.isPreset ? 1 : -1;
      }
      const aWidth = a.range.max - a.range.min;
      const bWidth = b.range.max - b.range.min;
      return aWidth - bWidth;
    });

    return matchedKeys[0].key;
  };

  return {
    responsiveManager: {
      getWidth: vi.fn(() => 1024),
      subscribe: vi.fn(() => () => {}),
      getMatchingKey,
      parseRange,
    },
  };
});

// 테스트용 컴포넌트
const TestButton: React.FC<{
  text?: string;
  children?: React.ReactNode;
  className?: string;
  variant?: string;
  size?: string;
}> = ({ text, children, className, variant, size }) => (
  <button
    data-testid="test-button"
    className={className}
    data-variant={variant}
    data-size={size}
  >
    {children || text}
  </button>
);

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <div data-testid="test-div" className={className}>
    {children}
  </div>
);

const TestCard: React.FC<{
  title?: string;
  children?: React.ReactNode;
}> = ({ title, children }) => (
  <div data-testid="card">
    {title && <h2 data-testid="card-title">{title}</h2>}
    {children}
  </div>
);

describe('DynamicRenderer - Responsive 오버라이드', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  // useResponsive mock 값
  let mockResponsiveValue: {
    width: number;
    isMobile: boolean;
    isTablet: boolean;
    isDesktop: boolean;
    matchedPreset: 'mobile' | 'tablet' | 'desktop';
  };

  beforeEach(() => {
    // ComponentRegistry 설정
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Button: {
        component: TestButton,
        metadata: { name: 'Button', type: 'basic' },
      },
      Div: {
        component: TestDiv,
        metadata: { name: 'Div', type: 'basic' },
      },
      Card: {
        component: TestCard,
        metadata: { name: 'Card', type: 'composite' },
      },
    };

    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({
      navigate: vi.fn(),
    });

    translationContext = {
      templateId: 'test-template',
      locale: 'ko',
    };

    // 기본값: 데스크톱 (1024px)
    mockResponsiveValue = {
      width: 1024,
      isMobile: false,
      isTablet: false,
      isDesktop: true,
      matchedPreset: 'desktop',
    };

    // useResponsive 모킹
    vi.spyOn(ResponsiveContextModule, 'useResponsive').mockReturnValue(
      mockResponsiveValue
    );
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /**
   * 테스트 헬퍼: 특정 화면 너비로 설정
   */
  const setWidth = (width: number) => {
    let preset: 'mobile' | 'tablet' | 'desktop' = 'desktop';
    if (width < 768) {
      preset = 'mobile';
    } else if (width < 1024) {
      preset = 'tablet';
    }

    mockResponsiveValue = {
      width,
      isMobile: width < 768,
      isTablet: width >= 768 && width < 1024,
      isDesktop: width >= 1024,
      matchedPreset: preset,
    };

    vi.spyOn(ResponsiveContextModule, 'useResponsive').mockReturnValue(
      mockResponsiveValue
    );
  };

  describe('1. props 얕은 머지', () => {
    it('responsive에 지정한 props만 대체하고 나머지는 유지', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'props-merge-1',
        type: 'basic',
        name: 'Button',
        props: {
          className: 'base-class',
          variant: 'primary',
          size: 'large',
        },
        responsive: {
          mobile: {
            props: {
              className: 'mobile-class',
              // variant와 size는 지정하지 않음 -> 유지되어야 함
            },
          },
        },
        text: 'Button',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const button = screen.getByTestId('test-button');
      expect(button).toHaveClass('mobile-class');
      expect(button).toHaveAttribute('data-variant', 'primary'); // 유지
      expect(button).toHaveAttribute('data-size', 'large'); // 유지
    });

    it('기본 props가 없는 경우 responsive props만 적용', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'props-merge-2',
        type: 'basic',
        name: 'Button',
        props: {},
        responsive: {
          mobile: {
            props: {
              className: 'mobile-only-class',
              variant: 'secondary',
            },
          },
        },
        text: 'Button',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      const button = screen.getByTestId('test-button');
      expect(button).toHaveClass('mobile-only-class');
      expect(button).toHaveAttribute('data-variant', 'secondary');
    });
  });

  describe('2. children 완전 교체', () => {
    it('responsive에서 children을 지정하면 기본 children 완전 교체', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'children-replace-1',
        type: 'composite',
        name: 'Card',
        props: { title: '제목' },
        children: [
          {
            id: 'child-1',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '데스크톱 버튼',
          },
          {
            id: 'child-2',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '두 번째 버튼',
          },
        ],
        responsive: {
          mobile: {
            children: [
              {
                id: 'mobile-child',
                type: 'basic',
                name: 'Button',
                props: {},
                text: '모바일 버튼',
              },
            ],
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 모바일에서는 children이 완전 교체됨
      expect(screen.getByText('모바일 버튼')).toBeInTheDocument();
      expect(screen.queryByText('데스크톱 버튼')).not.toBeInTheDocument();
      expect(screen.queryByText('두 번째 버튼')).not.toBeInTheDocument();
    });
  });

  describe('3. text 완전 교체', () => {
    it('responsive에서 text를 지정하면 기본 text 완전 교체', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'text-replace-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '데스크톱 텍스트',
        responsive: {
          mobile: {
            text: '모바일 텍스트',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('모바일 텍스트')).toBeInTheDocument();
      expect(screen.queryByText('데스크톱 텍스트')).not.toBeInTheDocument();
    });

    it('데스크톱에서는 기본 text 사용', () => {
      setWidth(1200); // 데스크톱

      const componentDef: ComponentDefinition = {
        id: 'text-replace-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '데스크톱 텍스트',
        responsive: {
          mobile: {
            text: '모바일 텍스트',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('데스크톱 텍스트')).toBeInTheDocument();
    });
  });

  describe('4. if 조건 완전 교체', () => {
    it('모바일에서 responsive if 조건이 적용됨 (기본 if 무시)', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'if-replace-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '버튼',
        if: '{{isDesktop}}', // 기본: 데스크톱에서만 표시 (false)
        responsive: {
          mobile: {
            if: '{{isMobile}}', // 모바일: isMobile일 때만 표시 (true)
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ isDesktop: false, isMobile: true }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 기본 if (isDesktop=false)면 안보여야 하지만,
      // 모바일에서는 responsive의 if (isMobile=true)가 적용되어 보여야 함
      expect(screen.getByText('버튼')).toBeInTheDocument();
    });

    it('모바일에서 responsive if 조건이 false면 렌더링 안됨', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'if-replace-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '버튼',
        if: '{{isDesktop}}', // 기본: 데스크톱에서만 표시
        responsive: {
          mobile: {
            if: '{{isMobile}}', // 모바일: isMobile일 때만 표시
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ isDesktop: true, isMobile: false }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 모바일에서 responsive if (isMobile=false)가 적용되어 안보여야 함
      // 기본 if (isDesktop=true)는 무시됨
      expect(screen.queryByText('버튼')).not.toBeInTheDocument();
    });

    it('데스크톱에서는 기본 if 조건 사용', () => {
      setWidth(1200); // 데스크톱

      const componentDef: ComponentDefinition = {
        id: 'if-replace-3',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '버튼',
        if: '{{isDesktop}}', // 기본: 데스크톱에서만 표시
        responsive: {
          mobile: {
            if: '{{isMobile}}', // 모바일 전용 조건
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{ isDesktop: true, isMobile: false }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 데스크톱에서는 기본 if (isDesktop=true)가 적용되어 보여야 함
      expect(screen.getByText('버튼')).toBeInTheDocument();
    });
  });

  describe('5. 프리셋 매칭 (mobile, tablet, desktop)', () => {
    const componentDef: ComponentDefinition = {
      id: 'preset-match-1',
      type: 'basic',
      name: 'Button',
      props: { className: 'default' },
      text: '기본',
      responsive: {
        mobile: {
          props: { className: 'mobile' },
          text: '모바일',
        },
        tablet: {
          props: { className: 'tablet' },
          text: '태블릿',
        },
        desktop: {
          props: { className: 'desktop' },
          text: '데스크톱',
        },
      },
    };

    it('500px에서 mobile 매칭', () => {
      setWidth(500);

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('모바일')).toBeInTheDocument();
      expect(screen.getByTestId('test-button')).toHaveClass('mobile');
    });

    it('800px에서 tablet 매칭', () => {
      setWidth(800);

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('태블릿')).toBeInTheDocument();
      expect(screen.getByTestId('test-button')).toHaveClass('tablet');
    });

    it('1200px에서 desktop 매칭', () => {
      setWidth(1200);

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('데스크톱')).toBeInTheDocument();
      expect(screen.getByTestId('test-button')).toHaveClass('desktop');
    });
  });

  describe('6. 커스텀 범위 매칭', () => {
    it('커스텀 범위 0-599 매칭', () => {
      setWidth(400);

      const componentDef: ComponentDefinition = {
        id: 'custom-range-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          '0-599': {
            text: '작은 모바일',
          },
          mobile: {
            text: '모바일',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 커스텀 범위가 프리셋보다 우선
      expect(screen.getByText('작은 모바일')).toBeInTheDocument();
    });

    it('열린 범위 1440- 매칭 (1440px 이상)', () => {
      setWidth(1600);

      const componentDef: ComponentDefinition = {
        id: 'open-range-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          desktop: {
            text: '데스크톱',
          },
          '1440-': {
            text: '대형 데스크톱',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 커스텀 범위가 프리셋보다 우선
      expect(screen.getByText('대형 데스크톱')).toBeInTheDocument();
    });
  });

  describe('7. 우선순위 테스트', () => {
    it('커스텀 범위가 프리셋보다 우선', () => {
      setWidth(400);

      const componentDef: ComponentDefinition = {
        id: 'priority-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          mobile: {
            text: '모바일 프리셋',
          },
          '0-480': {
            text: '커스텀 범위',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 400px는 mobile(0-767)과 0-480 둘 다 매칭되지만 커스텀이 우선
      expect(screen.getByText('커스텀 범위')).toBeInTheDocument();
    });

    it('좁은 범위가 넓은 범위보다 우선', () => {
      setWidth(400);

      const componentDef: ComponentDefinition = {
        id: 'priority-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          '0-767': {
            text: '넓은 범위',
          },
          '0-480': {
            text: '좁은 범위',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 400px는 두 범위 모두 매칭되지만 좁은 범위가 우선
      expect(screen.getByText('좁은 범위')).toBeInTheDocument();
    });
  });

  describe('8. 화면 크기 변경 시 동적 업데이트', () => {
    it('화면 크기 변경 시 컴포넌트 재렌더링', () => {
      setWidth(1200); // 데스크톱 시작

      const componentDef: ComponentDefinition = {
        id: 'dynamic-update-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          mobile: {
            text: '모바일',
          },
          desktop: {
            text: '데스크톱',
          },
        },
      };

      const { rerender } = render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('데스크톱')).toBeInTheDocument();

      // 모바일로 변경
      setWidth(500);

      rerender(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('모바일')).toBeInTheDocument();
    });
  });

  describe('9. responsive가 없는 경우', () => {
    it('responsive 속성이 없으면 기본 컴포넌트 정의 사용', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'no-responsive-1',
        type: 'basic',
        name: 'Button',
        props: { className: 'original' },
        text: '원본 텍스트',
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('원본 텍스트')).toBeInTheDocument();
      expect(screen.getByTestId('test-button')).toHaveClass('original');
    });

    it('해당 breakpoint에 맞는 responsive 설정이 없으면 기본값 사용', () => {
      setWidth(800); // 태블릿

      const componentDef: ComponentDefinition = {
        id: 'no-match-1',
        type: 'basic',
        name: 'Button',
        props: { className: 'original' },
        text: '원본',
        responsive: {
          // 태블릿에 대한 설정 없음
          mobile: {
            text: '모바일',
          },
          desktop: {
            text: '데스크톱',
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 태블릿 설정이 없으므로 원본 사용
      expect(screen.getByText('원본')).toBeInTheDocument();
      expect(screen.getByTestId('test-button')).toHaveClass('original');
    });
  });

  describe('10. iteration과 함께 사용', () => {
    it('responsive에서 iteration 오버라이드', () => {
      setWidth(500); // 모바일

      const componentDef: ComponentDefinition = {
        id: 'iteration-override-1',
        type: 'basic',
        name: 'Div',
        props: {},
        iteration: {
          source: 'items',
          item_var: 'item',
        },
        children: [
          {
            id: 'item-button',
            type: 'basic',
            name: 'Button',
            props: {},
            text: '{{item.name}} - 기본',
          },
        ],
        responsive: {
          mobile: {
            iteration: {
              source: 'mobileItems',
              item_var: 'mobileItem',
            },
            children: [
              {
                id: 'mobile-item-button',
                type: 'basic',
                name: 'Button',
                props: {},
                text: '{{mobileItem.name}} - 모바일',
              },
            ],
          },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{
            items: [{ name: 'A' }, { name: 'B' }],
            mobileItems: [{ name: 'M1' }],
          }}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      // 모바일에서는 mobileItems를 순회
      expect(screen.getByText('M1 - 모바일')).toBeInTheDocument();
      expect(screen.queryByText('A - 기본')).not.toBeInTheDocument();
    });
  });

  describe('11. 경계값 테스트', () => {
    it('767px에서 mobile', () => {
      setWidth(767);

      const componentDef: ComponentDefinition = {
        id: 'boundary-1',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          mobile: { text: '모바일' },
          tablet: { text: '태블릿' },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('모바일')).toBeInTheDocument();
    });

    it('768px에서 tablet', () => {
      setWidth(768);

      const componentDef: ComponentDefinition = {
        id: 'boundary-2',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          mobile: { text: '모바일' },
          tablet: { text: '태블릿' },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('태블릿')).toBeInTheDocument();
    });

    it('1023px에서 tablet', () => {
      setWidth(1023);

      const componentDef: ComponentDefinition = {
        id: 'boundary-3',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          tablet: { text: '태블릿' },
          desktop: { text: '데스크톱' },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('태블릿')).toBeInTheDocument();
    });

    it('1024px에서 desktop', () => {
      setWidth(1024);

      const componentDef: ComponentDefinition = {
        id: 'boundary-4',
        type: 'basic',
        name: 'Button',
        props: {},
        text: '기본',
        responsive: {
          tablet: { text: '태블릿' },
          desktop: { text: '데스크톱' },
        },
      };

      render(
        <DynamicRenderer
          componentDef={componentDef}
          dataContext={{}}
          translationContext={translationContext}
          registry={registry}
          bindingEngine={bindingEngine}
          translationEngine={translationEngine}
          actionDispatcher={actionDispatcher}
        />
      );

      expect(screen.getByText('데스크톱')).toBeInTheDocument();
    });
  });
});
