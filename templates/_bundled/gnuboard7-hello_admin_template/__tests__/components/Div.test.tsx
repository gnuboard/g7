/**
 * @file Div.test.tsx
 * @description Div 컴포넌트 단순 렌더 검증
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Div } from '../../src/components/basic/Div';

describe('Div (gnuboard7-hello_admin_template)', () => {
  it('자식 요소를 렌더링한다', () => {
    render(<Div data-testid="sample">Hello</Div>);
    expect(screen.getByTestId('sample')).toHaveTextContent('Hello');
  });

  it('className 을 전달한다', () => {
    render(<Div data-testid="sample" className="p-4 bg-white">content</Div>);
    expect(screen.getByTestId('sample')).toHaveClass('p-4');
    expect(screen.getByTestId('sample')).toHaveClass('bg-white');
  });

  it('ref 전달을 지원한다', () => {
    const ref = React.createRef<HTMLDivElement>();
    render(<Div ref={ref}>content</Div>);
    expect(ref.current).not.toBeNull();
    expect(ref.current?.tagName).toBe('DIV');
  });
});
