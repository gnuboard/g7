/**
 * @file Div.test.tsx
 * @description Basic Div 컴포넌트 단위 테스트
 *
 * @vitest-environment jsdom
 *
 * @scenario-manifest tests/scenarios/div-component.yaml
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Div } from '../../src/components/basic/Div';

describe('Div (Basic)', () => {
  /**
   * @scenario case=children
   * @effects div_renders_children
   */
  it('children 을 렌더링한다', () => {
    render(<Div data-testid="wrap">Hello</Div>);
    expect(screen.getByTestId('wrap')).toHaveTextContent('Hello');
  });

  /**
   * @scenario case=class_name
   * @effects div_passes_class_name
   */
  it('className 을 적용한다', () => {
    render(<Div data-testid="wrap" className="p-4 bg-white" />);
    expect(screen.getByTestId('wrap')).toHaveClass('p-4');
    expect(screen.getByTestId('wrap')).toHaveClass('bg-white');
  });

  /**
   * @scenario case=ref
   * @effects div_forwards_ref
   */
  it('ref 를 forwardRef 로 전달받는다', () => {
    const ref = React.createRef<HTMLDivElement>();
    render(<Div ref={ref} data-testid="wrap" />);
    expect(ref.current).not.toBeNull();
    expect(ref.current?.tagName).toBe('DIV');
  });

  /**
   * @scenario case=html_attributes
   * @effects div_passes_html_attributes
   */
  it('임의의 HTML 속성을 전달한다', () => {
    render(<Div data-testid="wrap" id="foo" aria-label="bar" />);
    const el = screen.getByTestId('wrap');
    expect(el).toHaveAttribute('id', 'foo');
    expect(el).toHaveAttribute('aria-label', 'bar');
  });
});
