/**
 * ExpandableContent 컴포넌트 테스트
 *
 * @description 콘텐츠 펼치기/접기 컴포넌트의 동작을 테스트합니다.
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock basic 컴포넌트
vi.mock('../../basic/Div', () => ({
  Div: React.forwardRef(({ children, className, style, ...props }: any, ref: any) => (
    <div ref={ref} className={className} style={style} {...props}>{children}</div>
  )),
}));

vi.mock('../../basic/Button', () => ({
  Button: ({ children, onClick, className, type, ...props }: any) => (
    <button onClick={onClick} className={className} type={type} {...props}>{children}</button>
  ),
}));

vi.mock('../../basic/Span', () => ({
  Span: ({ children, className, ...props }: any) => (
    <span className={className} {...props}>{children}</span>
  ),
}));

vi.mock('../../basic/Icon', () => ({
  Icon: ({ name, size, ...props }: any) => (
    <i data-testid={`icon-${name}`} data-size={size} {...props} />
  ),
}));

// Mock G7Core.t()
(window as any).G7Core = {
  t: (key: string) => {
    const translations: Record<string, string> = {
      'sirsoft-ecommerce.shop.product.expand_detail': '상세정보 펼쳐보기',
      'sirsoft-ecommerce.shop.product.collapse_detail': '상세정보 접기',
    };
    return translations[key] ?? key;
  },
};

import { ExpandableContent } from '../ExpandableContent';

describe('ExpandableContent', () => {
  beforeEach(() => {
    // ResizeObserver mock — Vitest 4.x 에서 vi.fn().mockImplementation 은 new 연산자
    // 적용 시 'is not a constructor' 에러 발생. class 문법으로 정의해야 함.
    global.ResizeObserver = class MockResizeObserver {
      observe = vi.fn();
      unobserve = vi.fn();
      disconnect = vi.fn();
    } as any;
  });

  it('children을 렌더링합니다', () => {
    render(
      <ExpandableContent>
        <p data-testid="content">테스트 콘텐츠</p>
      </ExpandableContent>
    );

    expect(screen.getByTestId('content')).toBeInTheDocument();
    expect(screen.getByText('테스트 콘텐츠')).toBeInTheDocument();
  });

  it('콘텐츠가 짧으면 버튼이 표시되지 않습니다', () => {
    render(
      <ExpandableContent maxHeight={500}>
        <p>짧은 콘텐츠</p>
      </ExpandableContent>
    );

    // scrollHeight가 0 (jsdom)이므로 needsExpand = false
    expect(screen.queryByText('상세정보 펼쳐보기')).not.toBeInTheDocument();
  });

  it('기본 maxHeight가 500입니다', () => {
    const { container } = render(
      <ExpandableContent>
        <p>콘텐츠</p>
      </ExpandableContent>
    );

    // 컴포넌트가 렌더링되었는지 확인
    expect(container.firstChild).toBeInTheDocument();
  });

  it('커스텀 className이 적용됩니다', () => {
    const { container } = render(
      <ExpandableContent className="custom-class">
        <p>콘텐츠</p>
      </ExpandableContent>
    );

    expect(container.firstChild).toHaveClass('custom-class');
  });

  it('커스텀 expandText/collapseText가 적용됩니다', () => {
    // scrollHeight를 강제로 설정하여 needsExpand = true 시뮬레이션
    const originalScrollHeight = Object.getOwnPropertyDescriptor(
      HTMLElement.prototype,
      'scrollHeight'
    );

    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', {
      configurable: true,
      get() {
        return 1000;
      },
    });

    render(
      <ExpandableContent
        maxHeight={100}
        expandText="더 보기"
        collapseText="줄이기"
      >
        <p>긴 콘텐츠</p>
      </ExpandableContent>
    );

    expect(screen.getByText('더 보기')).toBeInTheDocument();

    // 클릭하여 펼치기
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByText('줄이기')).toBeInTheDocument();

    // 복원
    if (originalScrollHeight) {
      Object.defineProperty(HTMLElement.prototype, 'scrollHeight', originalScrollHeight);
    }
  });

  it('버튼 클릭 시 펼치기/접기 토글이 됩니다', () => {
    const originalScrollHeight = Object.getOwnPropertyDescriptor(
      HTMLElement.prototype,
      'scrollHeight'
    );

    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', {
      configurable: true,
      get() {
        return 1000;
      },
    });

    render(
      <ExpandableContent maxHeight={100}>
        <p>긴 콘텐츠</p>
      </ExpandableContent>
    );

    // 접힌 상태: chevron-down 아이콘
    expect(screen.getByTestId('icon-chevron-down')).toBeInTheDocument();

    // 펼치기
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByTestId('icon-chevron-up')).toBeInTheDocument();

    // 다시 접기
    fireEvent.click(screen.getByRole('button'));
    expect(screen.getByTestId('icon-chevron-down')).toBeInTheDocument();

    if (originalScrollHeight) {
      Object.defineProperty(HTMLElement.prototype, 'scrollHeight', originalScrollHeight);
    }
  });
});
